<?php

namespace Platform\UserConnectors\Tools\RingCentral;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\DTOs\Pagination;
use Platform\UserConnectors\Services\RingCentral\RingCentralCallConnector;

class GetCallLogTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'user-connectors.ringcentral.calls.log';
    }

    public function getDescription(): string
    {
        return 'Ruft das Anrufprotokoll (Call Log) des Users ab. Filterbar nach Datum, Richtung (Inbound/Outbound) und Typ (Voice/Fax).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer', 'description' => 'Optionale Connection-ID.'],
                'date_from' => ['type' => 'string', 'description' => 'Start-Datum (ISO-8601).'],
                'date_to' => ['type' => 'string', 'description' => 'End-Datum (ISO-8601).'],
                'direction' => ['type' => 'string', 'description' => 'Inbound oder Outbound.'],
                'type' => ['type' => 'string', 'description' => 'Voice oder Fax.'],
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
            $connector = app(RingCentralCallConnector::class);
            if (!empty($arguments['connection_id'])) {
                $connector = new RingCentralCallConnector(
                    app(\Platform\UserConnectors\Services\RingCentral\RingCentralApiService::class)
                        ->forConnection($arguments['connection_id'])
                );
            }

            $filters = [];
            if (!empty($arguments['date_from'])) $filters['dateFrom'] = $arguments['date_from'];
            if (!empty($arguments['date_to'])) $filters['dateTo'] = $arguments['date_to'];
            if (!empty($arguments['direction'])) $filters['direction'] = $arguments['direction'];
            if (!empty($arguments['type'])) $filters['type'] = $arguments['type'];

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
            'tags' => ['ringcentral', 'calls', 'calllog', 'telefonie'],
            'read_only' => true,
            'requires_auth' => true,
            'risk_level' => 'safe',
            'cost_class' => 'external_api_free',
        ];
    }
}
