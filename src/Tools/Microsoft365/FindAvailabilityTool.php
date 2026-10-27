<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365EventTime;

class FindAvailabilityTool extends Microsoft365Tool
{
    public function getName(): string
    {
        return 'user-connectors.microsoft365.calendar.find_availability';
    }

    public function getDescription(): string
    {
        return 'Verfügbarkeit im Outlook-Kalender. mode=suggestions (Default): gemeinsame freie Slots für den User + participants '
            . '(Dauer duration_minutes, standardmäßig nur in Arbeitszeit). mode=schedule: belegte Blöcke (inkl. Ganztags-Abwesenheiten) '
            . 'und Arbeitszeiten je Person/Raum — für "Ist X am Dienstag da?" oder Raum-Belegung.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => self::connectionProperty(),
                'mode' => ['type' => 'string', 'enum' => ['suggestions', 'schedule'], 'description' => 'Default: suggestions.'],
                'participants' => [
                    'type' => ['array', 'string'],
                    'items' => ['type' => 'string'],
                    'description' => 'E-Mail-Adressen (Personen oder Räume). suggestions: weitere Teilnehmer, der User ist implizit dabei. '
                        . 'schedule: wessen Kalender geprüft wird (Default: der User selbst).',
                ],
                'after' => ['type' => 'string', 'description' => 'Zeitraum-Beginn (2026-11-03 oder 2026-11-03T08:00). Default: jetzt.'],
                'before' => ['type' => 'string', 'description' => 'Zeitraum-Ende. Reines Datum = einschließlich dieses Tages. Default: after + 7 Tage.'],
                'timezone' => self::timezoneProperty(),
                'duration_minutes' => ['type' => 'integer', 'description' => 'suggestions: Dauer des Slots. Default 30.'],
                'max_candidates' => ['type' => 'integer', 'description' => 'suggestions: max. Vorschläge (1–50). Default 5.'],
                'work_hours_only' => ['type' => 'boolean', 'description' => 'suggestions: nur innerhalb der Arbeitszeit. Default true.'],
                'minimum_attendee_percentage' => ['type' => 'integer', 'description' => 'suggestions: Anteil Teilnehmer, die frei sein müssen. Default 100.'],
            ],
            'required' => [],
        ];
    }

    protected function errorPrefix(): string
    {
        return 'Verfügbarkeit fehlgeschlagen';
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $tz = Microsoft365EventTime::resolveTimezone($arguments['timezone'] ?? null);
        $participants = $this->stringList($arguments['participants'] ?? []);

        $after = !empty($arguments['after']) ? Microsoft365EventTime::parse((string) $arguments['after'], $tz) : now($tz);
        if (!empty($arguments['before'])) {
            $before = Microsoft365EventTime::parse((string) $arguments['before'], $tz);
            if (Microsoft365EventTime::isDateOnly((string) $arguments['before'])) {
                $before = $before->copy()->addDay();
            }
        } else {
            $before = $after->copy()->addDays(7);
        }
        if ($before->lte($after)) {
            throw new \InvalidArgumentException('before muss nach after liegen.');
        }

        $connector = $this->calendar($arguments);

        if (($arguments['mode'] ?? 'suggestions') === 'schedule') {
            if ($participants === []) {
                $me = $this->api($arguments)->get($context->user, '/me', ['$select' => 'mail,userPrincipalName']);
                $participants = array_filter([$me['mail'] ?? $me['userPrincipalName'] ?? null]);
            }

            return ToolResult::success([
                'mode' => 'schedule',
                'range' => ['from' => $after->toIso8601String(), 'to' => $before->toIso8601String(), 'timezone' => $tz],
                'schedules' => $connector->getSchedule($context->user, $participants, $after, $before, $tz),
            ]);
        }

        if ($participants === []) {
            throw new \InvalidArgumentException('participants darf bei mode=suggestions nicht leer sein (für den eigenen Kalender mode=schedule nutzen).');
        }

        $result = $connector->findMeetingTimes(
            $context->user,
            $participants,
            $after,
            $before,
            (int) ($arguments['duration_minutes'] ?? 30),
            (int) ($arguments['max_candidates'] ?? 5),
            [
                'timezone' => $tz,
                'work_hours_only' => $this->optionalBool($arguments, 'work_hours_only') ?? true,
                'minimum_attendee_percentage' => $arguments['minimum_attendee_percentage'] ?? 100,
            ],
        );

        $suggestions = array_map(fn ($s) => [
            'start' => $s['start']->toIso8601String(),
            'end' => $s['end']->toIso8601String(),
            'confidence' => $s['confidence'],
            'reason' => $s['reason'],
            'attendee_availability' => $s['attendee_availability'],
        ], $result['suggestions']);

        return ToolResult::success(array_filter([
            'mode' => 'suggestions',
            'count' => count($suggestions),
            'suggestions' => $suggestions,
            'empty_reason' => $result['empty_reason'] ?? null,
            'timezone' => $tz,
        ], fn ($v) => $v !== null));
    }

    public function getMetadata(): array
    {
        return self::readMetadata(['outlook', 'calendar', 'availability', 'free_busy', 'schedule', 'find_meeting_times', 'planning'], [
            'category' => 'inspection',
            'examples' => [
                'Slot finden: {"participants": ["a@firma.de"], "after": "2026-11-03", "before": "2026-11-06", "duration_minutes": 60}',
                'Wer ist wann belegt: {"mode": "schedule", "participants": ["a@firma.de", "raum1@firma.de"], "after": "2026-11-03", "before": "2026-11-03"}',
            ],
            'related_tools' => ['user-connectors.microsoft365.calendar.create'],
        ]);
    }
}
