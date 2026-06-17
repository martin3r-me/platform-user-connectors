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

    /**
     * Per-resource max lifetime in seconds. Graph caps `/chats/getAllMessages`
     * (Teams chats) at 60 minutes regardless of the configured global lifetime;
     * patching beyond that produces a 400 and silently kills the subscription.
     * Keep this in sync with createAppLevelSubscriptions().
     */
    protected function resourceMaxLifetime(array $sub): int
    {
        $resource = (string) ($sub['resource'] ?? '');
        $isAppLevel = !empty($sub['app_level']);
        if ($isAppLevel || str_contains($resource, '/chats/') || str_starts_with($resource, 'chats/')) {
            return min($this->getMaxSubscriptionLifetime(), 3600);
        }
        return $this->getMaxSubscriptionLifetime();
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

        // CallRecords subscriptions (separate webhook endpoint)
        if (config('user-connectors.microsoft365.call_records.enabled', false)) {
            $callRecordResources = config('user-connectors.microsoft365.call_records.resources', []);
            $callRecordsWebhookUrl = route('user-connectors.webhooks.microsoft365.call-records');

            foreach ($callRecordResources as $res) {
                $clientState = Str::random(40);

                $response = Http::withToken($appToken)
                    ->timeout(30)
                    ->post($baseUrl . '/subscriptions', [
                        'changeType' => $res['changeType'],
                        'notificationUrl' => $callRecordsWebhookUrl,
                        'resource' => $res['resource'],
                        'expirationDateTime' => $expirationDateTime,
                        'clientState' => $clientState,
                    ]);

                if (!$response->successful()) {
                    Log::warning('MS365: CallRecords Subscription fehlgeschlagen', [
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

                Log::info('MS365: CallRecords Subscription erstellt', [
                    'connection_id' => $connection->id,
                    'subscription_id' => $data['id'],
                    'resource' => $res['resource'],
                ]);
            }
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

        $existing = $connection->credentials['subscriptions'] ?? [];

        // Stale Duplikate aus früheren Renewal-Bugs bereinigen. Sichtbar an
        // callRecords (1h-Lifetime): bevor die Recreation auf single-sub
        // umgestellt wurde, hat jeder PATCH-Fehler die komplette Shared/App/
        // CallRecords-Bootstrap-Kaskade erneut gefeuert → bei Connection #14
        // standen am Ende 16 callRecords-Subs in den Credentials, alle mit
        // demselben Resource. Wir behalten pro Signatur den Eintrag mit der
        // längsten Restlaufzeit und löschen den Rest auf Graph-Seite.
        [$existingSubscriptions, $orphans] = $this->dedupeSubscriptions($existing);
        if (!empty($orphans)) {
            $this->deleteSubscriptionsFromGraph($connection, $orphans, $userToken, $appToken);
        }

        $renewed = [];

        foreach ($existingSubscriptions as $sub) {
            // Use app token for shared mailbox and app-level subscriptions, user token for personal
            $isShared = !empty($sub['shared_mailbox']);
            $isAppLevel = !empty($sub['app_level']);
            $token = ($isShared || $isAppLevel) ? ($appToken ?? $userToken) : $userToken;

            // Per-resource lifetime — Teams chats max 60min, mail/calendar full.
            $expirationDateTime = now()->addSeconds($this->resourceMaxLifetime($sub))->toIso8601String();

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

                // EXAKT diese eine Subscription neu anlegen. Früher rief das
                // createSubscriptions() mit einem Single-Resource-Array auf —
                // intern feuert die Methode aber ZUSÄTZLICH den kompletten
                // Shared/App/CallRecords-Bootstrap, der jedes Mal frische
                // Duplikate produziert hat. recreateSingleSubscription() macht
                // nur den einen POST mit dem richtigen Token + Webhook-URL.
                $newSub = $this->recreateSingleSubscription($connection, $sub, $userToken, $appToken);
                if ($newSub) {
                    $renewed[] = $newSub;
                }
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

    /**
     * Recreate exactly one subscription after a failed PATCH. Picks the
     * correct token (user/app) and webhook URL (default vs callRecords)
     * based on the original sub's metadata — no implicit bootstrap of
     * other resources.
     *
     * @return array<string,mixed>|null Subscription entry to persist, or
     *     null if Graph rejected the request.
     */
    protected function recreateSingleSubscription(
        UserConnectorConnection $connection,
        array $sub,
        ?string $userToken,
        ?string $appToken,
    ): ?array {
        $isShared = !empty($sub['shared_mailbox']);
        $isAppLevel = !empty($sub['app_level']);
        $token = ($isShared || $isAppLevel) ? ($appToken ?? $userToken) : $userToken;

        if (!$token) {
            Log::warning('MS365: Kein Token für Subscription-Neuanlage', [
                'connection_id' => $connection->id,
                'resource' => $sub['resource'] ?? null,
            ]);
            return null;
        }

        $baseUrl = config('user-connectors.microsoft365.graph_base_url', 'https://graph.microsoft.com/v1.0');
        // callRecords haben einen eigenen Webhook-Endpunkt; alles andere
        // landet auf dem zentralen MS365-Webhook.
        $isCallRecords = ($sub['resource'] ?? '') === 'communications/callRecords';
        $webhookUrl = $isCallRecords
            ? route('user-connectors.webhooks.microsoft365.call-records')
            : route('user-connectors.webhooks.microsoft365');

        $clientState = Str::random(40);
        $expirationDateTime = now()->addSeconds($this->resourceMaxLifetime($sub))->toIso8601String();

        $response = Http::withToken($token)
            ->timeout(30)
            ->post($baseUrl . '/subscriptions', [
                'changeType' => $sub['change_types'],
                'notificationUrl' => $webhookUrl,
                'resource' => $sub['resource'],
                'expirationDateTime' => $expirationDateTime,
                'clientState' => $clientState,
            ]);

        if (!$response->successful()) {
            Log::error('MS365: Subscription-Neuanlage fehlgeschlagen', [
                'connection_id' => $connection->id,
                'resource' => $sub['resource'],
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 300),
            ]);
            return null;
        }

        $data = $response->json();
        $newSub = [
            'id' => $data['id'],
            'resource' => $sub['resource'],
            'change_types' => $sub['change_types'],
            'expires_at' => \Carbon\Carbon::parse($data['expirationDateTime'])->timestamp,
            'client_state' => $clientState,
        ];
        if ($isShared) {
            $newSub['shared_mailbox'] = $sub['shared_mailbox'];
        }
        if ($isAppLevel) {
            $newSub['app_level'] = true;
        }

        Log::info('MS365: Subscription neu angelegt nach Renewal-Fehler', [
            'connection_id' => $connection->id,
            'subscription_id' => $data['id'],
            'resource' => $sub['resource'],
        ]);

        return $newSub;
    }

    /**
     * Group subscriptions by their logical signature
     * (resource + change_types + shared_mailbox + app_level). For each group
     * keep the entry with the latest expires_at; return [keepers, orphans].
     *
     * @return array{0: array<int, array>, 1: array<int, array>}
     */
    protected function dedupeSubscriptions(array $subs): array
    {
        $groups = [];
        foreach ($subs as $sub) {
            $sig = ($sub['resource'] ?? '') . '|'
                . ($sub['change_types'] ?? '') . '|'
                . ($sub['shared_mailbox'] ?? '') . '|'
                . (!empty($sub['app_level']) ? '1' : '0');
            $groups[$sig][] = $sub;
        }

        $keepers = [];
        $orphans = [];
        foreach ($groups as $bucket) {
            usort($bucket, fn ($a, $b) => ($b['expires_at'] ?? 0) <=> ($a['expires_at'] ?? 0));
            $keepers[] = $bucket[0];
            for ($i = 1, $n = count($bucket); $i < $n; $i++) {
                $orphans[] = $bucket[$i];
            }
        }

        return [$keepers, $orphans];
    }

    /**
     * Best-effort DELETE on Graph for subs we no longer track. Failures are
     * fine — a 404 just means the Graph-side sub had already expired.
     */
    protected function deleteSubscriptionsFromGraph(
        UserConnectorConnection $connection,
        array $subs,
        ?string $userToken,
        ?string $appToken,
    ): void {
        if (empty($subs)) {
            return;
        }
        $baseUrl = config('user-connectors.microsoft365.graph_base_url', 'https://graph.microsoft.com/v1.0');
        foreach ($subs as $sub) {
            $isShared = !empty($sub['shared_mailbox']);
            $isAppLevel = !empty($sub['app_level']);
            $token = ($isShared || $isAppLevel) ? ($appToken ?? $userToken) : $userToken;
            if (!$token || empty($sub['id'])) {
                continue;
            }
            try {
                Http::withToken($token)
                    ->timeout(15)
                    ->delete($baseUrl . '/subscriptions/' . $sub['id']);
            } catch (\Throwable $e) {
                // already-gone Graph-side, that's fine
            }
        }
        Log::info('MS365: Duplicate Subscriptions bereinigt', [
            'connection_id' => $connection->id,
            'removed_count' => count($subs),
        ]);
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
