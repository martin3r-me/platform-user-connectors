<?php

namespace Platform\UserConnectors\Tools\RingCentral;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Services\RingCentral\RingCentralCallConnector;

class SendSMSTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'user-connectors.ringcentral.sms.send';
    }

    public function getDescription(): string
    {
        return 'Sendet eine SMS über das RingCentral-Konto des Users. Benötigt Empfänger-Telefonnummer und Nachrichtentext.';
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
            $connector = app(RingCentralCallConnector::class);
            if (!empty($arguments['connection_id'])) {
                $connector = new RingCentralCallConnector(
                    app(\Platform\UserConnectors\Services\RingCentral\RingCentralApiService::class)
                        ->forConnection($arguments['connection_id'])
                );
            }

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
            'tags' => ['ringcentral', 'sms', 'send', 'nachricht'],
            'read_only' => false,
            'requires_auth' => true,
            'risk_level' => 'write',
            'confirmation_required' => true,
            'cost_class' => 'external_api_paid',
        ];
    }
}
