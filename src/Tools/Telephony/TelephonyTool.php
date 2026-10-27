<?php

namespace Platform\UserConnectors\Tools\Telephony;

use Platform\Core\Models\User;
use Platform\UserConnectors\Contracts\CallConnector;
use Platform\UserConnectors\Contracts\MessageConnector;
use Platform\UserConnectors\DTOs\Message;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Services\RingCentral\RingCentralApiService;
use Platform\UserConnectors\Services\RingCentral\RingCentralCallConnector;
use Platform\UserConnectors\Services\RingCentral\RingCentralConnectorService;
use Platform\UserConnectors\Services\RingCentral\RingCentralMessageConnector;
use Platform\UserConnectors\Services\Sipgate\SipgateApiService;
use Platform\UserConnectors\Services\Sipgate\SipgateCallConnector;
use Platform\UserConnectors\Services\Sipgate\SipgateConnectorService;
use Platform\UserConnectors\Services\Sipgate\SipgateMessageConnector;
use Platform\UserConnectors\Services\Vodafone\VodafoneApiService;
use Platform\UserConnectors\Services\Vodafone\VodafoneCallConnector;
use Platform\UserConnectors\Services\Vodafone\VodafoneConnectorService;
use Platform\UserConnectors\Services\Vodafone\VodafoneMessageConnector;
use Platform\UserConnectors\Tools\ConnectorTool;

/**
 * Basis der Telefonie-Tools (SMS + Anrufe). Ein Tool pro Funktion für alle
 * Anbieter — welcher Anbieter, ergibt sich aus der Connection.
 */
abstract class TelephonyTool extends ConnectorTool
{
    public const PROVIDERS = ['sipgate', 'ringcentral', 'vodafone'];

    private const CLASSES = [
        'sipgate' => [SipgateApiService::class, SipgateCallConnector::class, SipgateMessageConnector::class, SipgateConnectorService::class],
        'ringcentral' => [RingCentralApiService::class, RingCentralCallConnector::class, RingCentralMessageConnector::class, RingCentralConnectorService::class],
        'vodafone' => [VodafoneApiService::class, VodafoneCallConnector::class, VodafoneMessageConnector::class, VodafoneConnectorService::class],
    ];

    protected function connection(User $user, array $arguments): UserConnectorConnection
    {
        return $this->resolveConnection($user, $arguments, self::PROVIDERS);
    }

    protected function provider(UserConnectorConnection $connection): string
    {
        return (string) $connection->connector?->key;
    }

    protected function calls(UserConnectorConnection $connection): CallConnector
    {
        [$api, $calls] = self::CLASSES[$this->provider($connection)];

        return new $calls(app($api)->forConnection((int) $connection->id));
    }

    protected function messages(UserConnectorConnection $connection): MessageConnector
    {
        [$api, , $messages] = self::CLASSES[$this->provider($connection)];

        return new $messages(app($api)->forConnection((int) $connection->id));
    }

    protected function connectorService(UserConnectorConnection $connection): object
    {
        return app(self::CLASSES[$this->provider($connection)][3]);
    }

    protected function connectionInfo(UserConnectorConnection $connection): array
    {
        return ['connection_id' => (int) $connection->id, 'provider' => $this->provider($connection)];
    }

    protected function presentMessage(Message $message): array
    {
        $data = $message->toArray();
        unset($data['provider'], $data['subject'], $data['body_type'], $data['attachments']);

        return $data;
    }

    protected static function connectionProperties(): array
    {
        return [
            'connection_id' => [
                'type' => 'integer',
                'description' => 'Optional. Telefonie-Connection (user-connectors.connections.list). Ohne: erste aktive eigene, sonst geteilte.',
            ],
            'connector_key' => [
                'type' => 'string',
                'enum' => self::PROVIDERS,
                'description' => 'Optional. Anbieter, wenn mehrere Verbindungen existieren und keine connection_id angegeben ist.',
            ],
        ];
    }

    protected static function writeMetadata(array $tags, array $extra = []): array
    {
        return parent::writeMetadata(array_merge(['telephony', 'sipgate', 'ringcentral', 'vodafone'], $tags), $extra);
    }

    protected static function readMetadata(array $tags, array $extra = []): array
    {
        return parent::readMetadata(
            array_merge(['telephony', 'sipgate', 'ringcentral', 'vodafone'], $tags),
            array_merge(['cost_class' => 'external_api_free'], $extra),
        );
    }
}
