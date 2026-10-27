<?php

namespace Platform\UserConnectors\Tools\Telephony;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;

class SendSmsTool extends TelephonyTool
{
    public function getName(): string
    {
        return 'user-connectors.telephony.sms.send';
    }

    public function getDescription(): string
    {
        return 'Sendet eine SMS über die Telefonie-Verbindung des Users (Sipgate, RingCentral oder Vodafone — ergibt sich aus der Connection). '
            . 'Absender ist die SMS-fähige Nummer der Verbindung.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => self::connectionProperties() + [
                'to' => ['type' => 'string', 'description' => 'Empfänger im E.164-Format, z. B. +4915123456789.'],
                'body' => ['type' => 'string', 'description' => 'SMS-Text.'],
            ],
            'required' => ['to', 'body'],
        ];
    }

    protected function errorPrefix(): string
    {
        return 'SMS-Versand fehlgeschlagen';
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $to = $this->requireString($arguments, 'to');
        $body = $this->requireString($arguments, 'body');
        $connection = $this->connection($context->user, $arguments);

        $result = $this->calls($connection)->sendSMS($context->user, $to, $body);

        return ToolResult::success($this->connectionInfo($connection) + [
            'status' => 'sent',
            'id' => $result['id'] ?? null,
            'provider_status' => $result['status'] ?? null,
            'to' => $to,
        ]);
    }

    public function getMetadata(): array
    {
        return self::writeMetadata(['sms', 'send', 'channel:sms', 'action:send'], ['cost_class' => 'external_api_paid']);
    }
}
