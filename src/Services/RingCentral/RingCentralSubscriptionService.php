<?php

namespace Platform\UserConnectors\Services\RingCentral;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Platform\UserConnectors\Contracts\SubscribableConnector;
use Platform\UserConnectors\Models\UserConnectorConnection;

class RingCentralSubscriptionService implements SubscribableConnector
{
    public function __construct(
        protected RingCentralConnectorService $connectorService,
    ) {}

    public function getConnectorKey(): string
    {
        return 'ringcentral';
    }

    public function getSubscriptionResources(UserConnectorConnection $connection): array
    {
        return $connection->credentials['settings']['subscription_resources'] ?? [];
    }

    public function getMaxSubscriptionLifetime(): int
    {
        return config('user-connectors.ringcentral.subscriptions.max_lifetime_seconds', 86400);
    }

    public function createSubscriptions(UserConnectorConnection $connection, array $resources): array
    {
        $token = $this->connectorService->getValidAccessToken($connection);
        if (!$token) {
            throw new \RuntimeException('RingCentral: Kein gültiger Access-Token für Subscription-Erstellung.');
        }

        $baseUrl = config('user-connectors.ringcentral.api_base_url', 'https://platform.ringcentral.com/restapi/v1.0');
        $webhookUrl = route('user-connectors.webhooks.ringcentral');
        $expiresIn = $this->getMaxSubscriptionLifetime();

        // RingCentral uses a single subscription with multiple eventFilters
        $eventFilters = array_values($resources);

        $response = Http::withToken($token)
            ->timeout(30)
            ->post($baseUrl . '/subscription', [
                'eventFilters' => $eventFilters,
                'deliveryMode' => [
                    'transportType' => 'WebHook',
                    'address' => $webhookUrl,
                ],
                'expiresIn' => $expiresIn,
            ]);

        if (!$response->successful()) {
            Log::error('RingCentral: Subscription-Erstellung fehlgeschlagen', [
                'connection_id' => $connection->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('RingCentral: Subscription-Erstellung fehlgeschlagen: ' . $response->body());
        }

        $data = $response->json();

        Log::info('RingCentral: Subscription erstellt', [
            'connection_id' => $connection->id,
            'subscription_id' => $data['id'],
        ]);

        return [
            [
                'id' => $data['id'],
                'event_filters' => $eventFilters,
                'expires_at' => isset($data['expirationTime'])
                    ? \Carbon\Carbon::parse($data['expirationTime'])->timestamp
                    : now()->addSeconds($expiresIn)->timestamp,
            ],
        ];
    }

    public function renewSubscriptions(UserConnectorConnection $connection): array
    {
        $token = $this->connectorService->getValidAccessToken($connection);
        if (!$token) {
            throw new \RuntimeException('RingCentral: Kein gültiger Access-Token für Subscription-Erneuerung.');
        }

        $baseUrl = config('user-connectors.ringcentral.api_base_url', 'https://platform.ringcentral.com/restapi/v1.0');
        $expiresIn = $this->getMaxSubscriptionLifetime();

        $existingSubscriptions = $connection->credentials['subscriptions'] ?? [];
        $renewed = [];

        foreach ($existingSubscriptions as $sub) {
            $response = Http::withToken($token)
                ->timeout(30)
                ->put($baseUrl . '/subscription/' . $sub['id'], [
                    'eventFilters' => $sub['event_filters'] ?? [],
                    'expiresIn' => $expiresIn,
                ]);

            if (!$response->successful()) {
                Log::warning('RingCentral: Subscription-Erneuerung fehlgeschlagen, versuche Neuanlage', [
                    'connection_id' => $connection->id,
                    'subscription_id' => $sub['id'],
                    'status' => $response->status(),
                ]);

                // Try to recreate
                $resources = $sub['event_filters'] ?? $this->getSubscriptionResources($connection);
                $recreated = $this->createSubscriptions($connection, $resources);
                $renewed = array_merge($renewed, $recreated);
                continue;
            }

            $data = $response->json();
            $renewed[] = [
                'id' => $sub['id'],
                'event_filters' => $sub['event_filters'] ?? [],
                'expires_at' => isset($data['expirationTime'])
                    ? \Carbon\Carbon::parse($data['expirationTime'])->timestamp
                    : now()->addSeconds($expiresIn)->timestamp,
            ];

            Log::info('RingCentral: Subscription erneuert', [
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
            Log::warning('RingCentral: Kein Token für Subscription-Löschung', ['connection_id' => $connection->id]);
            return;
        }

        $baseUrl = config('user-connectors.ringcentral.api_base_url', 'https://platform.ringcentral.com/restapi/v1.0');
        $existingSubscriptions = $connection->credentials['subscriptions'] ?? [];

        foreach ($existingSubscriptions as $sub) {
            try {
                Http::withToken($token)
                    ->timeout(15)
                    ->delete($baseUrl . '/subscription/' . $sub['id']);

                Log::info('RingCentral: Subscription gelöscht', [
                    'connection_id' => $connection->id,
                    'subscription_id' => $sub['id'],
                ]);
            } catch (\Exception $e) {
                Log::warning('RingCentral: Subscription-Löschung fehlgeschlagen', [
                    'connection_id' => $connection->id,
                    'subscription_id' => $sub['id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
