<?php

namespace Platform\UserConnectors\Services\Microsoft365;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Platform\UserConnectors\Contracts\SubscribableConnector;
use Platform\UserConnectors\Models\UserConnectorConnection;

class Microsoft365SubscriptionService implements SubscribableConnector
{
    public function __construct(
        protected Microsoft365ConnectorService $connectorService,
        protected Microsoft365AppTokenService $appTokenService,
    ) {}

    public function getConnectorKey(): string
    {
        return 'microsoft365';
    }

    public function getSubscriptionResources(UserConnectorConnection $connection): array
    {
        return $connection->credentials['settings']['subscription_resources'] ?? [];
    }

    public function getMaxSubscriptionLifetime(): int
    {
        return config('user-connectors.microsoft365.subscriptions.max_lifetime_seconds', 244800);
    }

    public function createSubscriptions(UserConnectorConnection $connection, array $resources): array
    {
        $token = $this->connectorService->getValidAccessToken($connection);
        if (!$token) {
            throw new \RuntimeException('MS365: Kein gültiger Access-Token für Subscription-Erstellung.');
        }

        $baseUrl = config('user-connectors.microsoft365.graph_base_url', 'https://graph.microsoft.com/v1.0');
        $webhookUrl = route('user-connectors.webhooks.microsoft365');
        $expirationDateTime = now()->addSeconds($this->getMaxSubscriptionLifetime())->toIso8601String();

        $subscriptions = [];

        foreach ($resources as $res) {
            $clientState = Str::random(40);

            $response = Http::withToken($token)
                ->timeout(30)
                ->post($baseUrl . '/subscriptions', [
                    'changeType' => $res['changeType'],
                    'notificationUrl' => $webhookUrl,
                    'resource' => $res['resource'],
                    'expirationDateTime' => $expirationDateTime,
                    'clientState' => $clientState,
                ]);

            if (!$response->successful()) {
                Log::error('MS365: Subscription-Erstellung fehlgeschlagen', [
                    'connection_id' => $connection->id,
                    'resource' => $res['resource'],
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                continue;
            }

            $data = $response->json();

            $subscriptions[] = [
                'id' => $data['id'],
                'resource' => $res['resource'],
                'change_types' => $res['changeType'],
                'expires_at' => \Carbon\Carbon::parse($data['expirationDateTime'])->timestamp,
                'client_state' => $clientState,
            ];

            Log::info('MS365: Subscription erstellt', [
                'connection_id' => $connection->id,
                'subscription_id' => $data['id'],
                'resource' => $res['resource'],
            ]);
        }

        // Also create shared mailbox subscriptions if configured
        $sharedMailboxSubs = $this->createSharedMailboxSubscriptions($connection);
        $subscriptions = array_merge($subscriptions, $sharedMailboxSubs);

        // Create app-level subscriptions (e.g. Teams chat) if tenant_id is configured
        $appSubs = $this->createAppLevelSubscriptions($connection);
        $subscriptions = array_merge($subscriptions, $appSubs);

        return $subscriptions;
    }

    /**
     * Create subscriptions for shared mailboxes using app-level token.
     *
     * Shared mailboxes are configured in connection credentials['shared_mailboxes']
     * as an array of email addresses. Requires Mail.Read (Application Permission).
     */
    protected function createSharedMailboxSubscriptions(UserConnectorConnection $connection): array
    {
        $sharedMailboxes = $connection->credentials['shared_mailboxes'] ?? [];
        if (empty($sharedMailboxes)) {
            return [];
        }

        $oauthApp = $connection->oauthApp;
        if (!$oauthApp || empty($oauthApp->settings['tenant_id'])) {
            Log::warning('MS365: Shared Mailbox Subscriptions erfordern tenant_id in OAuthApp', [
                'connection_id' => $connection->id,
            ]);
            return [];
        }

        $appToken = $this->appTokenService->getAppToken($oauthApp);
        if (!$appToken) {
            Log::warning('MS365: Kein App-Token für Shared Mailbox Subscriptions', [
                'connection_id' => $connection->id,
            ]);
            return [];
        }

        $baseUrl = config('user-connectors.microsoft365.graph_base_url', 'https://graph.microsoft.com/v1.0');
        $webhookUrl = route('user-connectors.webhooks.microsoft365');
        $expirationDateTime = now()->addSeconds($this->getMaxSubscriptionLifetime())->toIso8601String();

        $subscriptions = [];

        foreach ($sharedMailboxes as $mailbox) {
            $mailboxResources = [
                ['resource' => "users/{$mailbox}/mailFolders/inbox/messages", 'changeType' => 'created,updated'],
                ['resource' => "users/{$mailbox}/mailFolders/sentItems/messages", 'changeType' => 'created'],
            ];

            foreach ($mailboxResources as $res) {
                $clientState = Str::random(40);

                $response = Http::withToken($appToken)
                    ->timeout(30)
                    ->post($baseUrl . '/subscriptions', [
                        'changeType' => $res['changeType'],
                        'notificationUrl' => $webhookUrl,
                        'resource' => $res['resource'],
                        'expirationDateTime' => $expirationDateTime,
                        'clientState' => $clientState,
                    ]);

                if (!$response->successful()) {
                    Log::error('MS365: Shared Mailbox Subscription fehlgeschlagen', [
                        'connection_id' => $connection->id,
                        'mailbox' => $mailbox,
                        'resource' => $res['resource'],
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    continue;
                }

                $data = $response->json();

                $subscriptions[] = [
                    'id' => $data['id'],
                    'resource' => $res['resource'],
                    'change_types' => $res['changeType'],
                    'expires_at' => \Carbon\Carbon::parse($data['expirationDateTime'])->timestamp,
                    'client_state' => $clientState,
                    'shared_mailbox' => $mailbox,
                ];

                Log::info('MS365: Shared Mailbox Subscription erstellt', [
                    'connection_id' => $connection->id,
                    'subscription_id' => $data['id'],
                    'mailbox' => $mailbox,
                    'resource' => $res['resource'],
                ]);
            }
        }

        return $subscriptions;
    }

    /**
     * Create app-level subscriptions (e.g. Teams /chats/getAllMessages).
     *
     * These require application permissions (Chat.Read.All) and tenant_id.
     * Only created once per tenant — skips if another connection already has
     * an active subscription for the same resource.
     */
    protected function createAppLevelSubscriptions(UserConnectorConnection $connection): array
    {
        $appResources = config('user-connectors.microsoft365.subscriptions.app_resources', []);
        if (empty($appResources)) {
            return [];
        }

        $oauthApp = $connection->oauthApp;
        if (!$oauthApp || empty($oauthApp->settings['tenant_id'])) {
            Log::debug('MS365: App-level Subscriptions erfordern tenant_id in OAuthApp', [
                'connection_id' => $connection->id,
            ]);
            return [];
        }

        $appToken = $this->appTokenService->getAppToken($oauthApp);
        if (!$appToken) {
            Log::warning('MS365: Kein App-Token für App-level Subscriptions', [
                'connection_id' => $connection->id,
            ]);
            return [];
        }

        $baseUrl = config('user-connectors.microsoft365.graph_base_url', 'https://graph.microsoft.com/v1.0');
        $webhookUrl = route('user-connectors.webhooks.microsoft365');
        $expirationDateTime = now()->addSeconds(min($this->getMaxSubscriptionLifetime(), 3600))->toIso8601String();

        $subscriptions = [];

        foreach ($appResources as $res) {
            $clientState = Str::random(40);

            $subscriptionPayload = [
                'changeType' => $res['changeType'],
                'notificationUrl' => $webhookUrl,
                'resource' => $res['resource'],
                'expirationDateTime' => $expirationDateTime,
                'clientState' => $clientState,
            ];

            $response = Http::withToken($appToken)
                ->timeout(30)
                ->post($baseUrl . '/subscriptions', $subscriptionPayload);

            if (!$response->successful()) {
                Log::warning('MS365: App-level Subscription fehlgeschlagen', [
                    'connection_id' => $connection->id,
                    'resource' => $res['resource'],
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);
                continue;
            }

            $data = $response->json();

            $subscriptions[] = [
                'id' => $data['id'],
                'resource' => $res['resource'],
                'change_types' => $res['changeType'],
                'expires_at' => \Carbon\Carbon::parse($data['expirationDateTime'])->timestamp,
                'client_state' => $clientState,
                'app_level' => true,
            ];

            Log::info('MS365: App-level Subscription erstellt', [
                'connection_id' => $connection->id,
                'subscription_id' => $data['id'],
                'resource' => $res['resource'],
            ]);
        }

        return $subscriptions;
    }

    public function renewSubscriptions(UserConnectorConnection $connection): array
    {
        $userToken = $this->connectorService->getValidAccessToken($connection);
        if (!$userToken) {
            throw new \RuntimeException('MS365: Kein gültiger Access-Token für Subscription-Erneuerung.');
        }

        // Get app token for shared mailbox subscriptions
        $appToken = null;
        $oauthApp = $connection->oauthApp;
        if ($oauthApp && !empty($oauthApp->settings['tenant_id'])) {
            $appToken = $this->appTokenService->getAppToken($oauthApp);
        }

        $baseUrl = config('user-connectors.microsoft365.graph_base_url', 'https://graph.microsoft.com/v1.0');
        $expirationDateTime = now()->addSeconds($this->getMaxSubscriptionLifetime())->toIso8601String();

        $existingSubscriptions = $connection->credentials['subscriptions'] ?? [];
        $renewed = [];

        foreach ($existingSubscriptions as $sub) {
            // Use app token for shared mailbox and app-level subscriptions, user token for personal
            $isShared = !empty($sub['shared_mailbox']);
            $isAppLevel = !empty($sub['app_level']);
            $token = ($isShared || $isAppLevel) ? ($appToken ?? $userToken) : $userToken;

            $response = Http::withToken($token)
                ->timeout(30)
                ->patch($baseUrl . '/subscriptions/' . $sub['id'], [
                    'expirationDateTime' => $expirationDateTime,
                ]);

            if (!$response->successful()) {
                Log::warning('MS365: Subscription-Erneuerung fehlgeschlagen, versuche Neuanlage', [
                    'connection_id' => $connection->id,
                    'subscription_id' => $sub['id'],
                    'shared_mailbox' => $sub['shared_mailbox'] ?? null,
                    'status' => $response->status(),
                ]);

                // Try to recreate
                $resources = [['resource' => $sub['resource'], 'changeType' => $sub['change_types']]];
                $recreated = $this->createSubscriptions($connection, $resources);
                $renewed = array_merge($renewed, $recreated);
                continue;
            }

            $data = $response->json();
            $renewedSub = [
                'id' => $sub['id'],
                'resource' => $sub['resource'],
                'change_types' => $sub['change_types'],
                'expires_at' => \Carbon\Carbon::parse($data['expirationDateTime'])->timestamp,
                'client_state' => $sub['client_state'],
            ];
            if ($isShared) {
                $renewedSub['shared_mailbox'] = $sub['shared_mailbox'];
            }
            if ($isAppLevel) {
                $renewedSub['app_level'] = true;
            }
            $renewed[] = $renewedSub;

            Log::info('MS365: Subscription erneuert', [
                'connection_id' => $connection->id,
                'subscription_id' => $sub['id'],
            ]);
        }

        return $renewed;
    }

    public function deleteSubscriptions(UserConnectorConnection $connection): void
    {
        $token = $this->connectorService->getValidAccessToken($connection);
        if (!$token) {
            Log::warning('MS365: Kein Token für Subscription-Löschung', ['connection_id' => $connection->id]);
            return;
        }

        $baseUrl = config('user-connectors.microsoft365.graph_base_url', 'https://graph.microsoft.com/v1.0');
        $existingSubscriptions = $connection->credentials['subscriptions'] ?? [];

        foreach ($existingSubscriptions as $sub) {
            try {
                Http::withToken($token)
                    ->timeout(15)
                    ->delete($baseUrl . '/subscriptions/' . $sub['id']);

                Log::info('MS365: Subscription gelöscht', [
                    'connection_id' => $connection->id,
                    'subscription_id' => $sub['id'],
                ]);
            } catch (\Exception $e) {
                Log::warning('MS365: Subscription-Löschung fehlgeschlagen', [
                    'connection_id' => $connection->id,
                    'subscription_id' => $sub['id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
