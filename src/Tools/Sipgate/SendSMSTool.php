<?php

namespace Platform\UserConnectors\Tools\Sipgate;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Services\Sipgate\SipgateApiService;
use Platform\UserConnectors\Services\Sipgate\SipgateCallConnector;

class SendSMSTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'user-connectors.sipgate.sms.send';
    }

    public function getDescription(): string
    {
        return 'Sendet eine SMS über das Sipgate-Konto des Users. Benötigt Empfänger-Telefonnummer und Nachrichtentext.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer', 'description' => 'Optionale Connection-ID.'],
                'to' => ['type' => 'string', 'description' => 'Empfänger-Telefonnummer (E.164 Format, z.B. +4915123456789).'],
                'body' => ['type' => 'string', 'description' => 'SMS-Text.'],
            ],
            'required' => ['to', 'body'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('AUTH_ERROR', 'Benutzer nicht authentifiziert.');
        }

        try {
            $api = app(SipgateApiService::class);
            if (!empty($arguments['connection_id'])) {
                $api = $api->forConnection($arguments['connection_id']);
            }
            $connector = new SipgateCallConnector($api);

            $result = $connector->sendSMS($context->user, $arguments['to'], $arguments['body']);

            return ToolResult::success([
                'id' => $result['id'],
                'status' => $result['status'],
                'message' => 'SMS gesendet.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['sipgate', 'sms', 'send', 'nachricht'],
            'read_only' => false,
            'requires_auth' => true,
            'risk_level' => 'write',
            'confirmation_required' => true,
            'cost_class' => 'external_api_paid',
        ];
    }
}
