<?php

namespace Platform\UserConnectors\Contracts;

use Platform\UserConnectors\Models\UserConnectorConnection;

interface SubscribableConnector
{
    /**
     * The connector key (e.g. 'microsoft365', 'ringcentral').
     */
    public function getConnectorKey(): string;

    /**
     * Get the subscription resources/event filters from the connection's credentials.
     */
    public function getSubscriptionResources(UserConnectorConnection $connection): array;

    /**
     * Create API-side subscriptions and return subscription metadata to store.
     *
     * @return array<int, array> Subscription entries to persist in credentials['subscriptions']
     */
    public function createSubscriptions(UserConnectorConnection $connection, array $resources): array;

    /**
     * Renew existing subscriptions and return updated metadata.
     *
     * @return array<int, array> Updated subscription entries
     */
    public function renewSubscriptions(UserConnectorConnection $connection): array;

    /**
     * Delete all API-side subscriptions for this connection.
     */
    public function deleteSubscriptions(UserConnectorConnection $connection): void;

    /**
     * Maximum subscription lifetime in seconds.
     */
    public function getMaxSubscriptionLifetime(): int;
}
