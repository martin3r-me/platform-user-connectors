<?php

namespace Platform\UserConnectors\Tools\RingCentral;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Services\RingCentral\RingCentralCallConnector;

class InitiateCallTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'user-connectors.ringcentral.calls.initiate';
    }

    public function getDescription(): string
    {
        return 'Startet einen Anruf (RingOut) über RingCentral. Klingelt zuerst beim Absender-Gerät, verbindet dann mit dem Ziel. Beide Nummern im E.164-Format angeben.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer', 'description' => 'Optionale Connection-ID.'],
                'from' => ['type' => 'string', 'description' => 'Absender-Telefonnummer (E.164), z.B. die Durchwahl des Users.'],
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
            $connector = app(RingCentralCallConnector::class);
            if (!empty($arguments['connection_id'])) {
                $connector = new RingCentralCallConnector(
                    app(\Platform\UserConnectors\Services\RingCentral\RingCentralApiService::class)
                        ->forConnection($arguments['connection_id'])
                );
            }

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
            'tags' => ['ringcentral', 'call', 'ringout', 'telefonie', 'anruf'],
            'read_only' => false,
            'requires_auth' => true,
            'risk_level' => 'write',
            'confirmation_required' => true,
            'cost_class' => 'external_api_paid',
        ];
    }
}
