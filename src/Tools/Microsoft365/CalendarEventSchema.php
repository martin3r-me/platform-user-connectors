<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Carbon\Carbon;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365CalendarConnector;

/**
 * Gemeinsame Schema-Felder und Argument-Mapping für calendar.create/update.
 */
trait CalendarEventSchema
{
    protected static function timingProperties(): array
    {
        return [
            'start' => [
                'type' => 'string',
                'description' => 'Beginn. Mit Uhrzeit: "2026-11-03T09:30" (ohne Offset = Plattform-Zeitzone). '
                    . 'Ganztags: reines Datum "2026-11-03".',
            ],
            'end' => [
                'type' => 'string',
                'description' => 'Ende. Mit Uhrzeit: "2026-11-03T10:30". Ganztags: letzter Tag als reines Datum (einschließlich) — '
                    . 'für einen einzelnen Tag weglassen oder gleich start setzen.',
            ],
            'all_day' => [
                'type' => 'boolean',
                'description' => 'Ganztags-Termin. Wird automatisch erkannt, wenn start ein reines Datum ist oder 00:00–00:00/23:59. '
                    . 'Ganztags-Termine liegen immer von 00:00 bis 00:00 in der Zeitzone — nie über einen Tag hinaus.',
            ],
            'show_as' => [
                'type' => 'string',
                'enum' => Microsoft365CalendarConnector::SHOW_AS,
                'description' => 'Anzeigen als: free (frei), busy (beschäftigt), tentative (mit Vorbehalt), oof (abwesend), workingElsewhere. '
                    . 'Default: busy; Ganztags-Termine: free.',
            ],
            'timezone' => self::timezoneProperty(),
        ];
    }

    protected static function detailProperties(): array
    {
        return [
            'attendees' => [
                'type' => 'array',
                'description' => 'Teilnehmer als E-Mail-Strings oder {email, name, type: required|optional}. Bekommen eine Einladung.',
                'items' => ['type' => ['string', 'object']],
            ],
            'rooms' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Raum-Mailboxen (aus calendar.rooms.list), werden als Ressource mitgebucht.',
            ],
            'location' => ['type' => 'string', 'description' => 'Ort (Freitext). Bei genau einem Raum ohne location wird der Raum als Ort gesetzt.'],
            'description' => ['type' => 'string', 'description' => 'Beschreibung/Agenda (Plain Text oder HTML).'],
            'body_type' => ['type' => 'string', 'enum' => ['text', 'html'], 'description' => 'Format von description. Default: automatisch.'],
            'online_meeting' => ['type' => 'boolean', 'description' => 'Teams-Besprechungslink erzeugen.'],
            'reminder_minutes' => ['type' => 'integer', 'description' => 'Erinnerung X Minuten vorher; -1 = keine Erinnerung.'],
            'sensitivity' => ['type' => 'string', 'enum' => ['normal', 'personal', 'private', 'confidential'], 'description' => 'private = Details für andere verborgen.'],
            'importance' => ['type' => 'string', 'enum' => ['low', 'normal', 'high']],
            'categories' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Outlook-Kategorien (Namen).'],
        ];
    }

    /**
     * Optionale Felder 1:1 an den Connector durchreichen.
     */
    protected function detailOptions(array $arguments): array
    {
        $keys = ['location', 'description', 'body_type', 'reminder_minutes', 'sensitivity', 'importance', 'categories', 'show_as', 'online_meeting'];

        return array_intersect_key(
            array_filter($arguments, fn ($v) => $v !== null && $v !== ''),
            array_flip($keys),
        );
    }

    /**
     * Kurzfassung zum Gegenlesen, z. B. "Ganztags Di 03.11.2026 (1 Tag) · frei".
     */
    protected function summarize(array $event): string
    {
        $showAs = [
            'free' => 'frei', 'busy' => 'beschäftigt', 'tentative' => 'mit Vorbehalt',
            'oof' => 'abwesend', 'workingElsewhere' => 'woanders tätig',
        ][$event['show_as'] ?? ''] ?? ($event['show_as'] ?? '');
        $tz = $event['timezone'] ?? 'UTC';

        if (!empty($event['is_all_day'])) {
            $first = Carbon::parse($event['start_date'], $tz);
            $last = Carbon::parse($event['end_date'], $tz);
            $when = 'Ganztags ' . $first->locale('de')->isoFormat('dd DD.MM.YYYY')
                . ($event['days'] > 1 ? ' – ' . $last->locale('de')->isoFormat('dd DD.MM.YYYY') : '')
                . ' (' . $event['days'] . ($event['days'] === 1 ? ' Tag' : ' Tage') . ')';
        } else {
            $start = Carbon::parse($event['start'])->setTimezone($tz);
            $end = Carbon::parse($event['end'])->setTimezone($tz);
            $when = $start->locale('de')->isoFormat('dd DD.MM.YYYY HH:mm') . '–'
                . ($start->isSameDay($end) ? $end->format('H:i') : $end->locale('de')->isoFormat('dd DD.MM.YYYY HH:mm'))
                . ' (' . $tz . ')';
        }

        return trim($when . ($showAs !== '' ? ' · ' . $showAs : ''));
    }
}
