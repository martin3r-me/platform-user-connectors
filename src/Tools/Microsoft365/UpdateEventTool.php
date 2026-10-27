<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;

class UpdateEventTool extends Microsoft365Tool
{
    use CalendarEventSchema;

    public function getName(): string
    {
        return 'user-connectors.microsoft365.calendar.update';
    }

    public function getDescription(): string
    {
        return 'Ändert einen bestehenden Outlook-Termin — nur die übergebenen Felder. '
            . 'Verschieben: nur start angeben, die Dauer bleibt erhalten. Ganztags umstellen: all_day=true (oder start als reines Datum). '
            . 'Frei/belegt ändern: show_as. attendees ersetzt die komplette Teilnehmerliste (Teilnehmer werden benachrichtigt).';
    }

    public function getSchema(): array
    {
        $timing = self::timingProperties();
        $timing['show_as']['description'] = 'Anzeigen als: free, busy, tentative, oof, workingElsewhere.';

        return [
            'type' => 'object',
            'properties' => array_merge(
                [
                    'connection_id' => self::connectionProperty(),
                    'event_id' => ['type' => 'string', 'description' => 'Termin-ID (id aus calendar.list).'],
                    'title' => ['type' => 'string'],
                ],
                $timing,
                self::detailProperties(),
            ),
            'required' => ['event_id'],
        ];
    }

    protected function errorPrefix(): string
    {
        return 'Termin ändern fehlgeschlagen';
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $eventId = $this->requireString($arguments, 'event_id');

        $changes = $this->detailOptions($arguments) + array_filter([
            'title' => isset($arguments['title']) ? (string) $arguments['title'] : null,
            'start' => $arguments['start'] ?? null,
            'end' => $arguments['end'] ?? null,
            'all_day' => $this->optionalBool($arguments, 'all_day'),
            'timezone' => $arguments['timezone'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if (array_key_exists('attendees', $arguments)) {
            $changes['attendees'] = (array) $arguments['attendees'];
            $changes['rooms'] = $this->stringList($arguments['rooms'] ?? []);
        } elseif (!empty($arguments['rooms'])) {
            throw new \InvalidArgumentException('rooms nur zusammen mit attendees ändern (die Teilnehmerliste wird komplett ersetzt).');
        }

        if (array_diff(array_keys($changes), ['timezone']) === []) {
            throw new \InvalidArgumentException('Mindestens ein Feld zum Ändern angeben.');
        }

        $event = $this->calendar($arguments)->updateEvent($context->user, $eventId, $changes);
        $data = $event->toArray();

        return ToolResult::success([
            'status' => 'updated',
            'summary' => $this->summarize($data),
            'event' => $data,
        ]);
    }

    public function getMetadata(): array
    {
        return self::writeMetadata(['outlook', 'calendar', 'event', 'termin', 'update', 'reschedule'], [
            'examples' => [
                'Auf ganztags + frei: {"event_id": "AAMk…", "all_day": true, "show_as": "free"}',
                'Verschieben (Dauer bleibt): {"event_id": "AAMk…", "start": "2026-11-04T14:00"}',
            ],
        ]);
    }
}
