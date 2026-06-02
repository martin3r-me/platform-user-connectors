<?php

namespace Platform\UserConnectors\Tools\Sipgate;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Services\Sipgate\SipgateApiService;
use Platform\UserConnectors\Services\Sipgate\SipgateCallConnector;

class InitiateCallTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'user-connectors.sipgate.calls.initiate';
    }

    public function getDescription(): string
    {
        return 'Startet einen Anruf (Click-to-Call) über Sipgate. Klingelt zuerst beim Absender-Gerät, verbindet dann mit dem Ziel. Beide Nummern im E.164-Format angeben.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer', 'description' => 'Optionale Connection-ID.'],
                'from' => ['type' => 'string', 'description' => 'Absender-Telefonnummer oder Sipgate-Device-ID (z.B. "e0").'],
                'to' => ['type' => 'string', 'description' => 'Ziel-Telefonnummer (E.164).'],
            ],
            'required' => ['from', 'to'],
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

            $result = $connector->initiateCall($context->user, $arguments['from'], $arguments['to']);

            return ToolResult::success([
                'session_id' => $result['sessionId'],
                'status' => $result['status'],
                'message' => 'Anruf wird aufgebaut.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['sipgate', 'call', 'click-to-call', 'telefonie', 'anruf'],
            'read_only' => false,
            'requires_auth' => true,
            'risk_level' => 'write',
            'confirmation_required' => true,
            'cost_class' => 'external_api_paid',
        ];
    }
}
