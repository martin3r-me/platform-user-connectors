<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Carbon\Carbon;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365ApiService;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365CalendarConnector;

class UpdateEventTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string { return 'user-connectors.microsoft365.calendar.update'; }

    public function getDescription(): string
    {
        return 'Aktualisiert einen Outlook-Kalender-Event. Übergebene Felder werden gepatcht — '
            . 'title, start, end, location, description. Alles optional außer event_id.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer'],
                'event_id' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'start' => ['type' => 'string', 'description' => 'ISO-8601.'],
                'end' => ['type' => 'string', 'description' => 'ISO-8601.'],
                'location' => ['type' => 'string'],
                'description' => ['type' => 'string'],
            ],
            'required' => ['event_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('AUTH_ERROR', 'Benutzer nicht authentifiziert.');
        }

        $changes = [];
        if (isset($arguments['title'])) {
            $changes['title'] = (string) $arguments['title'];
        }
        if (isset($arguments['start'])) {
            $changes['start'] = Carbon::parse((string) $arguments['start']);
        }
        if (isset($arguments['end'])) {
            $changes['end'] = Carbon::parse((string) $arguments['end']);
        }
        if (isset($arguments['location'])) {
            $changes['location'] = (string) $arguments['location'];
        }
        if (isset($arguments['description'])) {
            $changes['description'] = (string) $arguments['description'];
        }
        if (empty($changes)) {
            return ToolResult::error('VALIDATION_ERROR', 'Mindestens ein Feld zum Updaten erforderlich.');
        }

        try {
            $connector = app(Microsoft365CalendarConnector::class);
            if (!empty($arguments['connection_id'])) {
                $connector = new Microsoft365CalendarConnector(
                    app(Microsoft365ApiService::class)->forConnection((int) $arguments['connection_id'])
                );
            }
            $event = $connector->updateEvent($context->user, (string) $arguments['event_id'], $changes);
            return ToolResult::success(['event' => $event->toArray(), 'status' => 'updated']);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Update fehlgeschlagen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['category' => 'action', 'tags' => ['microsoft365', 'calendar', 'event', 'update'],
            'read_only' => false, 'requires_auth' => true, 'risk_level' => 'write',
            'confirmation_required' => true, 'cost_class' => 'external_api_free'];
    }
}
