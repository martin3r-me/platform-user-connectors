<?php

namespace Platform\UserConnectors\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Models\UserConnectorPhoneNumber;
use Platform\UserConnectors\Tools\Telephony\TelephonyTool;

class ListPhoneNumbersTool extends ConnectorTool
{
    public function getName(): string
    {
        return 'user-connectors.phone-numbers.list';
    }

    public function getDescription(): string
    {
        return 'Listet die Telefonnummern der Telefonie-Verbindungen (eigene + geteilte) mit Fähigkeiten (voice, sms, fax) und Standardnummer. '
            . 'Nutzbar als from in telephony.calls.initiate (RingCentral/Vodafone). Leer? → user-connectors.profile.sync.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer'],
                'connector_key' => ['type' => 'string', 'enum' => TelephonyTool::PROVIDERS],
                'include_shared' => ['type' => 'boolean', 'description' => 'Default true.'],
                'capability' => ['type' => 'string', 'enum' => ['voice', 'sms', 'fax']],
            ],
        ];
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $query = UserConnectorPhoneNumber::query()
            ->whereIn('connection_id', $this->scopedConnectionIds($context->user, $arguments, TelephonyTool::PROVIDERS))
            ->with('connection.connector')
            ->orderByDesc('is_default')
            ->orderBy('number');

        if (!empty($arguments['capability'])) {
            $query->withCapability((string) $arguments['capability']);
        }

        $numbers = $query->get()->map(fn (UserConnectorPhoneNumber $phone) => array_filter([
            'id' => $phone->id,
            'connection_id' => $phone->connection_id,
            'provider' => $phone->connection?->connector?->key,
            'number' => $phone->number,
            'label' => $phone->label,
            'type' => $phone->type,
            'capabilities' => $phone->capabilities,
            'is_default' => (bool) $phone->is_default,
            'external_id' => $phone->external_id,
            'phoneline' => $phone->meta['phonelineAlias'] ?? null,
        ], fn ($v) => $v !== null))->values()->all();

        return ToolResult::success(['phone_numbers' => $numbers, 'total' => count($numbers)]);
    }

    public function getMetadata(): array
    {
        return self::readMetadata(['phone-numbers', 'telephony', 'sipgate', 'ringcentral', 'vodafone']);
    }
}
