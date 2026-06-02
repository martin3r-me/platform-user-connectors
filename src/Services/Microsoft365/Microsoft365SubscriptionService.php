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

        return $subscriptions;
    }

    public function renewSubscriptions(UserConnectorConnection $connection): array
    {
        $token = $this->connectorService->getValidAccessToken($connection);
        if (!$token) {
            throw new \RuntimeException('MS365: Kein gültiger Access-Token für Subscription-Erneuerung.');
        }

        $baseUrl = config('user-connectors.microsoft365.graph_base_url', 'https://graph.microsoft.com/v1.0');
        $expirationDateTime = now()->addSeconds($this->getMaxSubscriptionLifetime())->toIso8601String();

        $existingSubscriptions = $connection->credentials['subscriptions'] ?? [];
        $renewed = [];

        foreach ($existingSubscriptions as $sub) {
            $response = Http::withToken($token)
                ->timeout(30)
                ->patch($baseUrl . '/subscriptions/' . $sub['id'], [
                    'expirationDateTime' => $expirationDateTime,
                ]);

            if (!$response->successful()) {
                Log::warning('MS365: Subscription-Erneuerung fehlgeschlagen, versuche Neuanlage', [
                    'connection_id' => $connection->id,
                    'subscription_id' => $sub['id'],
                    'status' => $response->status(),
                ]);

                // Try to recreate
                $resources = [['resource' => $sub['resource'], 'changeType' => $sub['change_types']]];
                $recreated = $this->createSubscriptions($connection, $resources);
                $renewed = array_merge($renewed, $recreated);
                continue;
            }

            $data = $response->json();
            $renewed[] = [
                'id' => $sub['id'],
                'resource' => $sub['resource'],
                'change_types' => $sub['change_types'],
                'expires_at' => \Carbon\Carbon::parse($data['expirationDateTime'])->timestamp,
                'client_state' => $sub['client_state'],
            ];

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
