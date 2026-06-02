<?php

namespace Platform\UserConnectors\Services;

use Illuminate\Support\Facades\Log;
use Platform\UserConnectors\Contracts\SubscribableConnector;
use Platform\UserConnectors\Models\UserConnectorConnection;

class WebhookSubscriptionManager
{
    /** @var array<string, SubscribableConnector> */
    protected array $connectors = [];

    public function registerConnector(SubscribableConnector $connector): void
    {
        $this->connectors[$connector->getConnectorKey()] = $connector;
    }

    public function getConnector(string $connectorKey): ?SubscribableConnector
    {
        return $this->connectors[$connectorKey] ?? null;
    }

    public function getRegisteredKeys(): array
    {
        return array_keys($this->connectors);
    }

    /**
     * Create subscriptions for a connection and persist in credentials.
     */
    public function createSubscriptions(UserConnectorConnection $connection): array
    {
        $connectorKey = $connection->connector->key;
        $connector = $this->getConnector($connectorKey);

        if (!$connector) {
            return [];
        }

        $settings = $connection->credentials['settings'] ?? [];
        if (!($settings['subscriptions_enabled'] ?? true)) {
            Log::info('WebhookSubscriptionManager: Subscriptions deaktiviert', [
                'connection_id' => $connection->id,
                'connector_key' => $connectorKey,
            ]);
            return [];
        }

        $resources = $connector->getSubscriptionResources($connection);
        if (empty($resources)) {
            Log::info('WebhookSubscriptionManager: Keine Subscription-Resources konfiguriert', [
                'connection_id' => $connection->id,
            ]);
            return [];
        }

        $subscriptions = $connector->createSubscriptions($connection, $resources);

        // Persist in credentials
        $credentials = $connection->credentials;
        $credentials['subscriptions'] = $subscriptions;
        $connection->credentials = $credentials;
        $connection->save();

        return $subscriptions;
    }

    /**
     * Renew subscriptions for a connection and persist updated metadata.
     */
    public function renewSubscriptions(UserConnectorConnection $connection): array
    {
        $connectorKey = $connection->connector->key;
        $connector = $this->getConnector($connectorKey);

        if (!$connector) {
            return [];
        }

        $existingSubscriptions = $connection->credentials['subscriptions'] ?? [];
        if (empty($existingSubscriptions)) {
            // No subscriptions to renew — try creating instead
            return $this->createSubscriptions($connection);
        }

        $renewed = $connector->renewSubscriptions($connection);

        $credentials = $connection->credentials;
        $credentials['subscriptions'] = $renewed;
        $connection->credentials = $credentials;
        $connection->save();

        return $renewed;
    }

    /**
     * Delete all API-side subscriptions and clear from credentials.
     */
    public function deleteSubscriptions(UserConnectorConnection $connection): void
    {
        $connectorKey = $connection->connector->key;
        $connector = $this->getConnector($connectorKey);

        if (!$connector) {
            return;
        }

        $connector->deleteSubscriptions($connection);

        $credentials = $connection->credentials;
        $credentials['subscriptions'] = [];
        $connection->credentials = $credentials;
        $connection->save();
    }

    /**
     * Check if a connection has subscriptions expiring within the given buffer.
     */
    public function hasExpiringSoon(UserConnectorConnection $connection, int $bufferSeconds = 3600): bool
    {
        $subscriptions = $connection->credentials['subscriptions'] ?? [];
        if (empty($subscriptions)) {
            return false;
        }

        $threshold = now()->addSeconds($bufferSeconds)->timestamp;

        foreach ($subscriptions as $sub) {
            $expiresAt = $sub['expires_at'] ?? 0;
            if ($expiresAt > 0 && $expiresAt <= $threshold) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve a connection by subscription ID stored in credentials.
     */
    public function resolveConnectionBySubscriptionId(string $connectorKey, string $subscriptionId): ?UserConnectorConnection
    {
        return UserConnectorConnection::query()
            ->whereHas('connector', fn ($q) => $q->where('key', $connectorKey))
            ->where('status', 'active')
            ->get()
            ->first(function (UserConnectorConnection $conn) use ($subscriptionId) {
                $subscriptions = $conn->credentials['subscriptions'] ?? [];
                foreach ($subscriptions as $sub) {
                    if (($sub['id'] ?? '') === $subscriptionId) {
                        return true;
                    }
                }
                return false;
            });
    }
}
