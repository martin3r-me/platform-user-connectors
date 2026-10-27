<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;

class DeleteEventTool extends Microsoft365Tool
{
    public function getName(): string
    {
        return 'user-connectors.microsoft365.calendar.delete';
    }

    public function getDescription(): string
    {
        return 'Löscht einen Outlook-Termin. Als Organisator mit Teilnehmern: notify_attendees=true (Default) sendet eine Absage '
            . 'mit optionalem comment. Für eine Einladung eines anderen stattdessen calendar.respond (decline) nutzen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => self::connectionProperty(),
                'event_id' => ['type' => 'string', 'description' => 'Termin-ID (id aus calendar.list).'],
                'notify_attendees' => ['type' => 'boolean', 'description' => 'Default true: Teilnehmer erhalten eine Absage (nur als Organisator). false = still löschen.'],
                'comment' => ['type' => 'string', 'description' => 'Optional. Text der Absage an die Teilnehmer.'],
                'confirm' => ['type' => 'boolean', 'description' => 'Muss true sein.'],
            ],
            'required' => ['event_id', 'confirm'],
        ];
    }

    protected function errorPrefix(): string
    {
        return 'Löschen fehlgeschlagen';
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        if (!$this->optionalBool($arguments, 'confirm')) {
            throw new \InvalidArgumentException('confirm=true erforderlich.');
        }
        $eventId = $this->requireString($arguments, 'event_id');
        $connector = $this->calendar($arguments);

        $mode = 'deleted';
        if ($this->optionalBool($arguments, 'notify_attendees') ?? true) {
            $event = $connector->getEvent($context->user, $eventId, ['body_format' => 'none']);
            $isOrganizer = ($event->responseStatus ?? 'organizer') === 'organizer';
            if ($isOrganizer && $event->attendees !== []) {
                $connector->cancelEvent($context->user, $eventId, isset($arguments['comment']) ? (string) $arguments['comment'] : null);
                $mode = 'cancelled';
            }
        }
        if ($mode === 'deleted') {
            $connector->deleteEvent($context->user, $eventId);
        }

        return ToolResult::success(['status' => $mode, 'event_id' => $eventId]);
    }

    public function getMetadata(): array
    {
        return self::writeMetadata(['outlook', 'calendar', 'event', 'delete', 'cancel', 'destructive'], [
            'risk_level' => 'destructive',
        ]);
    }
}
