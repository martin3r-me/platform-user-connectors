<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Carbon\Carbon;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365CalendarConnector;

class CreateEventTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'user-connectors.microsoft365.calendar.create';
    }

    public function getDescription(): string
    {
        return 'Erstellt einen Kalender-Event im Outlook-Kalender des Users. Unterstützt Teilnehmer, Online-Meeting (Teams), Ort und Beschreibung.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer', 'description' => 'Optionale Connection-ID.'],
                'title' => ['type' => 'string', 'description' => 'Titel/Betreff des Events.'],
                'start' => ['type' => 'string', 'description' => 'Start-Zeitpunkt (ISO-8601).'],
                'end' => ['type' => 'string', 'description' => 'End-Zeitpunkt (ISO-8601).'],
                'attendees' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'E-Mail-Adressen der Teilnehmer.',
                ],
                'rooms' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'E-Mail-Adressen der Raum-Ressourcen, die als Resource-Attendee mit-gebucht werden. Discovery via calendar.rooms.list.',
                ],
                'description' => ['type' => 'string', 'description' => 'Beschreibung (HTML erlaubt).'],
                'location' => ['type' => 'string', 'description' => 'Ort des Events. Bei genau einem Raum + leerer Location wird automatisch die Raum-Mailbox als Anzeige-Location übernommen.'],
                'online_meeting' => ['type' => 'boolean', 'description' => 'Teams-Meeting erstellen?'],
            ],
            'required' => ['title', 'start', 'end'],
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

            $event = $connector->createEvent(
                $context->user,
                $arguments['title'],
                Carbon::parse($arguments['start']),
                Carbon::parse($arguments['end']),
                $arguments['attendees'] ?? [],
                [
                    'description' => $arguments['description'] ?? null,
                    'location' => $arguments['location'] ?? null,
                    'online_meeting' => $arguments['online_meeting'] ?? false,
                    'rooms' => $arguments['rooms'] ?? [],
                ],
            );

            return ToolResult::success([
                'event' => $event->toArray(),
                'status' => 'created',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['microsoft365', 'outlook', 'calendar', 'event', 'create'],
            'read_only' => false,
            'requires_auth' => true,
            'risk_level' => 'write',
            'confirmation_required' => true,
            'cost_class' => 'external_api_free',
        ];
    }
}
