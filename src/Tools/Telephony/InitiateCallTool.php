<?php

namespace Platform\UserConnectors\Tools\Telephony;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Models\UserConnectorDevice;
use Platform\UserConnectors\Models\UserConnectorPhoneNumber;

class InitiateCallTool extends TelephonyTool
{
    public function getName(): string
    {
        return 'user-connectors.telephony.calls.initiate';
    }

    public function getDescription(): string
    {
        return 'Startet einen Anruf (Click-to-Call): erst klingelt das eigene Gerät bzw. die eigene Nummer, nach Annahme wird zum Ziel verbunden. '
            . 'from ist optional — ohne wird das Standardgerät (Sipgate) bzw. die Standardnummer (RingCentral/Vodafone) genutzt; '
            . 'Auswahl über user-connectors.devices.list / phone-numbers.list.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => self::connectionProperties() + [
                'to' => ['type' => 'string', 'description' => 'Zielnummer (E.164).'],
                'from' => ['type' => 'string', 'description' => 'Optional. Sipgate: Geräte-ID (z. B. "e0", "p0"); RingCentral/Vodafone: eigene Nummer (E.164).'],
            ],
            'required' => ['to'],
        ];
    }

    protected function errorPrefix(): string
    {
        return 'Anruf fehlgeschlagen';
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $to = $this->requireString($arguments, 'to');
        $connection = $this->connection($context->user, $arguments);
        $from = trim((string) ($arguments['from'] ?? '')) ?: $this->defaultFrom($connection);

        $result = $this->calls($connection)->initiateCall($context->user, $from, $to);

        return ToolResult::success($this->connectionInfo($connection) + [
            'status' => 'initiated',
            'session_id' => $result['sessionId'] ?? null,
            'provider_status' => $result['status'] ?? null,
            'from' => $from,
            'to' => $to,
            'message' => 'Anruf wird aufgebaut — zuerst klingelt ' . $from . '.',
        ]);
    }

    /**
     * Sipgate braucht eine Geräte-ID (online bevorzugt), RingCentral/Vodafone
     * eine eigene Sprach-Nummer (Default bevorzugt).
     */
    protected function defaultFrom(UserConnectorConnection $connection): string
    {
        if ($this->provider($connection) === 'sipgate') {
            $device = UserConnectorDevice::query()
                ->forConnection((int) $connection->id)
                ->orderByDesc('is_online')
                ->orderBy('external_id')
                ->first();
            if ($device?->external_id) {
                return (string) $device->external_id;
            }
        } else {
            $number = UserConnectorPhoneNumber::query()
                ->forConnection((int) $connection->id)
                ->withCapability('voice')
                ->orderByDesc('is_default')
                ->first()
                ?? UserConnectorPhoneNumber::query()->forConnection((int) $connection->id)->orderByDesc('is_default')->first();
            if ($number?->number) {
                return (string) $number->number;
            }
        }

        throw new \InvalidArgumentException('Kein Standard-Absender bekannt — bitte from angeben oder user-connectors.profile.sync ausführen.');
    }

    public function getMetadata(): array
    {
        return self::writeMetadata(['calls', 'call', 'initiate', 'click-to-call', 'callback', 'channel:call'], ['cost_class' => 'external_api_paid']);
    }
}
