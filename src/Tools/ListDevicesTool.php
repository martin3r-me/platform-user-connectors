<?php

namespace Platform\UserConnectors\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Models\UserConnectorDevice;
use Platform\UserConnectors\Tools\Telephony\TelephonyTool;

class ListDevicesTool extends ConnectorTool
{
    public function getName(): string
    {
        return 'user-connectors.devices.list';
    }

    public function getDescription(): string
    {
        return 'Listet die Telefonie-Geräte (Softphone, Tischtelefon, Mobil) der Verbindungen mit Online-Status. '
            . 'external_id ist bei Sipgate das from für telephony.calls.initiate. Leer? → user-connectors.profile.sync.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer'],
                'connector_key' => ['type' => 'string', 'enum' => TelephonyTool::PROVIDERS],
                'include_shared' => ['type' => 'boolean', 'description' => 'Default true.'],
                'online_only' => ['type' => 'boolean'],
            ],
        ];
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $query = UserConnectorDevice::query()
            ->whereIn('connection_id', $this->scopedConnectionIds($context->user, $arguments, TelephonyTool::PROVIDERS))
            ->with('connection.connector')
            ->orderByDesc('is_online')
            ->orderBy('name');

        if ($this->optionalBool($arguments, 'online_only')) {
            $query->online();
        }

        $devices = $query->get()->map(fn (UserConnectorDevice $device) => [
            'id' => $device->id,
            'connection_id' => $device->connection_id,
            'provider' => $device->connection?->connector?->key,
            'name' => $device->name,
            'type' => $device->type,
            'external_id' => $device->external_id,
            'is_online' => (bool) $device->is_online,
        ])->values()->all();

        return ToolResult::success(['devices' => $devices, 'total' => count($devices)]);
    }

    public function getMetadata(): array
    {
        return self::readMetadata(['devices', 'telephony', 'sipgate', 'ringcentral', 'vodafone']);
    }
}
