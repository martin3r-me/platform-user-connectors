<?php

namespace Platform\UserConnectors\Tools\Sipgate;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\DTOs\Pagination;
use Platform\UserConnectors\Services\Sipgate\SipgateApiService;
use Platform\UserConnectors\Services\Sipgate\SipgateCallConnector;

class GetCallLogTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'user-connectors.sipgate.calls.log';
    }

    public function getDescription(): string
    {
        return 'Ruft das Anrufprotokoll (Call History) des Sipgate-Kontos ab. Filterbar nach Richtung, Typ und Telefonnummer.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer', 'description' => 'Optionale Connection-ID.'],
                'direction' => ['type' => 'string', 'description' => 'Incoming oder Outgoing.'],
                'type' => ['type' => 'string', 'description' => 'CALL, SMS, FAX, VOICEMAIL.'],
                'phonenumber' => ['type' => 'string', 'description' => 'Nach Telefonnummer filtern.'],
                'page' => ['type' => 'integer', 'description' => 'Seite. Standard: 1.'],
                'per_page' => ['type' => 'integer', 'description' => 'Einträge pro Seite. Standard: 25.'],
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
            $api = app(SipgateApiService::class);
            if (!empty($arguments['connection_id'])) {
                $api = $api->forConnection($arguments['connection_id']);
            }
            $connector = new SipgateCallConnector($api);

            $filters = [];
            if (!empty($arguments['direction'])) $filters['direction'] = $arguments['direction'];
            if (!empty($arguments['type'])) $filters['type'] = $arguments['type'];
            if (!empty($arguments['phonenumber'])) $filters['phonenumber'] = $arguments['phonenumber'];

            $pagination = new Pagination(
                page: $arguments['page'] ?? 1,
                perPage: $arguments['per_page'] ?? 25,
            );

            $result = $connector->getCallLog($context->user, $filters, $pagination);

            return ToolResult::success([
                'calls' => array_map(fn ($c) => $c->toArray(), $result['calls']),
                'pagination' => $result['pagination']->toArray(),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['sipgate', 'calls', 'calllog', 'telefonie', 'history'],
            'read_only' => true,
            'requires_auth' => true,
            'risk_level' => 'safe',
            'cost_class' => 'external_api_free',
        ];
    }
}
