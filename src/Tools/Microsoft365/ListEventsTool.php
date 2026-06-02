<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Carbon\Carbon;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\DTOs\Pagination;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365CalendarConnector;

class ListEventsTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'user-connectors.microsoft365.calendar.list';
    }

    public function getDescription(): string
    {
        return 'Listet Kalender-Events aus dem Outlook-Kalender des Users auf. Zeitraum muss angegeben werden (from/to als ISO-8601).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer', 'description' => 'Optionale Connection-ID.'],
                'from' => ['type' => 'string', 'description' => 'Start-Zeitpunkt (ISO-8601). Standard: jetzt.'],
                'to' => ['type' => 'string', 'description' => 'End-Zeitpunkt (ISO-8601). Standard: +7 Tage.'],
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
            $connector = app(Microsoft365CalendarConnector::class);
            if (!empty($arguments['connection_id'])) {
                $connector = new Microsoft365CalendarConnector(
                    app(\Platform\UserConnectors\Services\Microsoft365\Microsoft365ApiService::class)
                        ->forConnection($arguments['connection_id'])
                );
            }

            $from = !empty($arguments['from']) ? Carbon::parse($arguments['from']) : now();
            $to = !empty($arguments['to']) ? Carbon::parse($arguments['to']) : now()->addDays(7);

            $pagination = new Pagination(
                perPage: $arguments['per_page'] ?? 25,
            );

            $result = $connector->listEvents($context->user, $from, $to, $pagination);

            return ToolResult::success([
                'events' => array_map(fn ($e) => $e->toArray(), $result['events']),
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
            'tags' => ['microsoft365', 'outlook', 'calendar', 'events', 'list'],
            'read_only' => true,
            'requires_auth' => true,
            'risk_level' => 'safe',
            'cost_class' => 'external_api_free',
        ];
    }
}
