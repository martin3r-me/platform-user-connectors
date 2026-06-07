<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365ApiService;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365CalendarConnector;

class DeleteEventTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string { return 'user-connectors.microsoft365.calendar.delete'; }

    public function getDescription(): string
    {
        return 'Löscht einen Outlook-Kalender-Event endgültig. Benötigt confirm=true.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer'],
                'event_id' => ['type' => 'string'],
                'confirm' => ['type' => 'boolean', 'description' => 'Muss true sein.'],
            ],
            'required' => ['event_id', 'confirm'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('AUTH_ERROR', 'Benutzer nicht authentifiziert.');
        }
        if (empty($arguments['confirm'])) {
            return ToolResult::error('VALIDATION_ERROR', 'confirm=true erforderlich.');
        }
        try {
            $connector = app(Microsoft365CalendarConnector::class);
            if (!empty($arguments['connection_id'])) {
                $connector = new Microsoft365CalendarConnector(
                    app(Microsoft365ApiService::class)->forConnection((int) $arguments['connection_id'])
                );
            }
            $ok = $connector->deleteEvent($context->user, (string) $arguments['event_id']);
            return $ok
                ? ToolResult::success(['status' => 'deleted', 'event_id' => $arguments['event_id']])
                : ToolResult::error('EXECUTION_ERROR', 'Delete fehlgeschlagen.');
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Delete fehlgeschlagen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['category' => 'action', 'tags' => ['microsoft365', 'calendar', 'event', 'delete', 'destructive'],
            'read_only' => false, 'requires_auth' => true, 'risk_level' => 'destructive',
            'confirmation_required' => true, 'cost_class' => 'external_api_free'];
    }
}
