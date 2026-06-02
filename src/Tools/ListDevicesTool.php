<?php

namespace Platform\UserConnectors\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Models\UserConnectorDevice;
use Platform\UserConnectors\Services\ConnectionResolver;

class ListDevicesTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'user-connectors.devices.list';
    }

    public function getDescription(): string
    {
        return 'Listet alle Geräte (Telefone, Softphones) einer Connector-Verbindung auf. Zeigt Name, Typ (softphone/deskphone/mobile/webrtc), Online-Status und Provider-ID. Filterbar nach Connector und Online-Status.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer', 'description' => 'Connection-ID. Wenn leer, werden Geräte aller Verbindungen des Users zurückgegeben.'],
                'connector_key' => ['type' => 'string', 'description' => 'Filter nach Connector: sipgate, ringcentral, vodafone.'],
                'online_only' => ['type' => 'boolean', 'description' => 'Nur online Geräte anzeigen.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('AUTH_ERROR', 'Benutzer nicht authentifiziert.');
        }

        try {
            $resolver = app(ConnectionResolver::class);

            if (!empty($arguments['connection_id'])) {
                $connection = $resolver->resolveById((int) $arguments['connection_id'], $context->user);
                if (!$connection) {
                    return ToolResult::error('NOT_FOUND', 'Verbindung nicht gefunden oder kein Zugriff.');
                }
                $connectionIds = [$connection->id];
            } elseif (!empty($arguments['connector_key'])) {
                $connections = $resolver->resolveAllForUser($arguments['connector_key'], $context->user);
                $connectionIds = $connections->pluck('id')->all();
            } else {
                $connectionIds = UserConnectorConnection::query()
                    ->where('owner_user_id', $context->user->id)
                    ->where('status', 'active')
                    ->pluck('id')
                    ->all();
            }

            if (empty($connectionIds)) {
                return ToolResult::success(['devices' => [], 'total' => 0]);
            }

            $query = UserConnectorDevice::query()
                ->whereIn('connection_id', $connectionIds)
                ->with('connection.connector');

            if (!empty($arguments['online_only'])) {
                $query->online();
            }

            $devices = $query->orderBy('name')->get();

            $result = $devices->map(fn (UserConnectorDevice $device) => [
                'id' => $device->id,
                'connection_id' => $device->connection_id,
                'connector' => $device->connection?->connector?->key,
                'connection_name' => $device->connection?->name,
                'name' => $device->name,
                'type' => $device->type,
                'external_id' => $device->external_id,
                'is_online' => $device->is_online,
            ])->all();

            return ToolResult::success([
                'devices' => $result,
                'total' => count($result),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['telefonie', 'geräte', 'devices', 'phone', 'softphone', 'sipgate', 'ringcentral', 'vodafone'],
            'read_only' => true,
            'requires_auth' => true,
            'risk_level' => 'safe',
            'cost_class' => 'database',
        ];
    }
}
