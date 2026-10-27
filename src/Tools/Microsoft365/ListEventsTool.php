<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\DTOs\CalendarEvent;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365CalendarConnector;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365EventTime;

class ListEventsTool extends Microsoft365Tool
{
    public function getName(): string
    {
        return 'user-connectors.microsoft365.calendar.list';
    }

    public function getDescription(): string
    {
        return 'Listet Termine aus dem Outlook-Kalender im Zeitraum from–to (Serien als Einzeltermine) — oder liest mit event_id einen einzelnen Termin. '
            . 'Zeiten kommen in der Plattform-Zeitzone (mit Offset). Ganztags-Termine haben zusätzlich start_date/end_date '
            . '(end_date = letzter Tag, einschließlich) — für sie diese Felder statt start/end lesen. '
            . 'show_as zeigt frei/belegt (free, tentative, busy, oof, workingElsewhere).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => self::connectionProperty(),
                'event_id' => ['type' => 'string', 'description' => 'Optional. Einzelnen Termin per ID lesen (inkl. Beschreibung als Text).'],
                'from' => ['type' => 'string', 'description' => 'Beginn des Zeitraums (2026-11-03 oder ISO-8601). Default: heute 00:00.'],
                'to' => ['type' => 'string', 'description' => 'Ende des Zeitraums. Reines Datum = einschließlich dieses Tages. Default: from + 7 Tage.'],
                'timezone' => self::timezoneProperty(),
                'body_format' => ['type' => 'string', 'enum' => ['none', 'preview', 'text', 'html'], 'description' => 'Beschreibung der Termine. Default: none in Listen, text bei event_id.'],
                'limit' => ['type' => 'integer', 'description' => 'Max. Termine (1–' . Microsoft365CalendarConnector::MAX_LIST_LIMIT . '). Default 100. Mehr vorhanden → truncated=true.'],
                'per_page' => ['type' => 'integer', 'description' => 'Alias für limit.'],
                'include_cancelled' => ['type' => 'boolean', 'description' => 'Default false: abgesagte Termine ausblenden.'],
                'include_attendees' => ['type' => 'boolean', 'description' => 'Default false: in Listen nur attendee_count (Teilnehmerlisten machen sonst den Großteil der Antwort aus). Bei event_id immer vollständig.'],
            ],
            'required' => [],
        ];
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $tz = Microsoft365EventTime::resolveTimezone($arguments['timezone'] ?? null);
        $connector = $this->calendar($arguments);

        if (!empty($arguments['event_id'])) {
            $event = $connector->getEvent($context->user, trim((string) $arguments['event_id']), [
                'timezone' => $tz,
                'body_format' => $arguments['body_format'] ?? 'text',
            ]);

            return ToolResult::success(['event' => $event->toArray(), 'timezone' => $tz]);
        }

        $from = !empty($arguments['from'])
            ? Microsoft365EventTime::parse((string) $arguments['from'], $tz)
            : now($tz)->startOfDay();
        if (!empty($arguments['to'])) {
            $to = Microsoft365EventTime::parse((string) $arguments['to'], $tz);
            if (Microsoft365EventTime::isDateOnly((string) $arguments['to'])) {
                $to = $to->copy()->addDay();
            }
        } else {
            $to = $from->copy()->addDays(7);
        }
        if ($to->lte($from)) {
            throw new \InvalidArgumentException('to muss nach from liegen.');
        }

        $result = $connector->listEvents($context->user, $from, $to, null, [
            'timezone' => $tz,
            'body_format' => $arguments['body_format'] ?? 'none',
            'limit' => (int) ($arguments['limit'] ?? $arguments['per_page'] ?? 100),
        ]);

        $events = $result['events'];
        if (!$this->optionalBool($arguments, 'include_cancelled')) {
            $events = array_values(array_filter($events, fn (CalendarEvent $e) => !$e->isCancelled));
        }

        $withAttendees = (bool) $this->optionalBool($arguments, 'include_attendees');

        return ToolResult::success([
            'count' => count($events),
            'events' => array_map(function (CalendarEvent $e) use ($withAttendees) {
                $data = $e->toArray();
                unset($data['provider']);
                if (!$withAttendees && isset($data['attendees'])) {
                    $data['attendee_count'] = count($data['attendees']);
                    unset($data['attendees']);
                }

                return $data;
            }, $events),
            'truncated' => $result['truncated'],
            'range' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'timezone' => $tz],
        ]);
    }

    public function getMetadata(): array
    {
        return self::readMetadata(['outlook', 'calendar', 'events', 'termine', 'list', 'read'], [
            'examples' => [
                'Diese Woche: {"from": "2026-11-02", "to": "2026-11-08"}',
                'Einzelner Termin: {"event_id": "AAMk…"}',
            ],
            'related_tools' => ['user-connectors.microsoft365.calendar.create', 'user-connectors.microsoft365.calendar.update', 'user-connectors.microsoft365.calendar.find_availability'],
        ]);
    }
}
