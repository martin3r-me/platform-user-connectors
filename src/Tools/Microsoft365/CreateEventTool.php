<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365EventTime;

class CreateEventTool extends Microsoft365Tool
{
    use CalendarEventSchema;

    public function getName(): string
    {
        return 'user-connectors.microsoft365.calendar.create';
    }

    public function getDescription(): string
    {
        return 'Legt einen Termin im Outlook-Kalender an — mit Uhrzeit oder ganztags, optional mit Teilnehmern, Räumen und Teams-Link. '
            . 'GANZTAGS: start als reines Datum ("2026-11-03"), end = letzter Tag (einschließlich) oder weglassen — nicht 00:00 bis 00:00 in UTC. '
            . 'show_as steuert frei/belegt (Ganztags-Default: free). Die Antwort enthält summary zum Gegenlesen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                ['connection_id' => self::connectionProperty(), 'title' => ['type' => 'string', 'description' => 'Betreff des Termins.']],
                self::timingProperties(),
                self::detailProperties(),
            ),
            'required' => ['title', 'start'],
        ];
    }

    protected function errorPrefix(): string
    {
        return 'Termin anlegen fehlgeschlagen';
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $title = $this->requireString($arguments, 'title');
        $tz = Microsoft365EventTime::resolveTimezone($arguments['timezone'] ?? null);
        $range = Microsoft365EventTime::resolveRange(
            $this->requireString($arguments, 'start'),
            isset($arguments['end']) ? (string) $arguments['end'] : null,
            $this->optionalBool($arguments, 'all_day'),
            $tz,
        );

        $event = $this->calendar($arguments)->createEvent(
            $context->user,
            $title,
            $range['start'],
            $range['end'],
            (array) ($arguments['attendees'] ?? []),
            $this->detailOptions($arguments) + [
                'all_day' => $range['all_day'],
                'timezone' => $tz,
                'rooms' => $this->stringList($arguments['rooms'] ?? []),
            ],
        );

        $data = $event->toArray();

        return ToolResult::success([
            'status' => 'created',
            'summary' => $this->summarize($data),
            'event' => $data,
        ]);
    }

    public function getMetadata(): array
    {
        return self::writeMetadata(['outlook', 'calendar', 'event', 'termin', 'create', 'all_day', 'ganztags'], [
            'examples' => [
                'Ganztags (1 Tag, frei): {"title": "Urlaub", "start": "2026-11-03", "show_as": "oof"}',
                'Ganztags über 3 Tage: {"title": "Messe", "start": "2026-11-03", "end": "2026-11-05", "show_as": "busy"}',
                'Mit Uhrzeit: {"title": "Call", "start": "2026-11-03T09:30", "end": "2026-11-03T10:00", "attendees": ["a@firma.de"], "online_meeting": true}',
            ],
            'related_tools' => ['user-connectors.microsoft365.calendar.find_availability', 'user-connectors.microsoft365.calendar.rooms.list'],
        ]);
    }
}
