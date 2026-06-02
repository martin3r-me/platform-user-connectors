<?php

namespace Platform\UserConnectors\Services;

use Platform\UserConnectors\Contracts\ConnectorCapability;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365CalendarConnector;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365MailConnector;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365TeamsConnector;
use Platform\UserConnectors\Services\RingCentral\RingCentralCallConnector;
use Platform\UserConnectors\Services\RingCentral\RingCentralMessageConnector;
use Platform\UserConnectors\Services\Sipgate\SipgateCallConnector;
use Platform\UserConnectors\Services\Sipgate\SipgateMessageConnector;

class ConnectorRegistry
{
    /**
     * Maps connector keys to their capability implementations.
     */
    protected array $map = [
        'microsoft365' => [
            'messages' => Microsoft365MailConnector::class,
            'calendar' => Microsoft365CalendarConnector::class,
            'presence' => Microsoft365TeamsConnector::class,
        ],
        'ringcentral' => [
            'calls' => RingCentralCallConnector::class,
            'messages' => RingCentralMessageConnector::class,
        ],
        'sipgate' => [
            'calls' => SipgateCallConnector::class,
            'messages' => SipgateMessageConnector::class,
        ],
    ];

    /**
     * Resolve the connector implementation for a given key + capability.
     */
    public function resolve(string $connectorKey, ConnectorCapability $capability): object
    {
        $class = $this->map[$connectorKey][$capability->value] ?? null;

        if (!$class) {
            throw new \RuntimeException("Connector '{$connectorKey}' unterstützt '{$capability->value}' nicht.");
        }

        return app($class);
    }

    /**
     * Get all capabilities for a connector key.
     *
     * @return ConnectorCapability[]
     */
    public function capabilities(string $connectorKey): array
    {
        $caps = $this->map[$connectorKey] ?? [];

        return array_map(
            fn (string $key) => ConnectorCapability::from($key),
            array_keys($caps)
        );
    }

    /**
     * Get all connector keys that provide a given capability.
     *
     * @return string[]
     */
    public function providersFor(ConnectorCapability $capability): array
    {
        $providers = [];

        foreach ($this->map as $key => $caps) {
            if (isset($caps[$capability->value])) {
                $providers[] = $key;
            }
        }

        return $providers;
    }
}
