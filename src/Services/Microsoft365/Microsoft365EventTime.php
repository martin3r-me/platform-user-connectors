<?php

namespace Platform\UserConnectors\Services\Microsoft365;

use Carbon\Carbon;

/**
 * Zeit-Logik für Outlook-Events: Zeitzone, Ganztags-Erkennung und das
 * Graph-Format { dateTime, timeZone }.
 *
 * Hintergrund: Agenten legen Ganztags-Termine gern als "00:00 bis 00:00" in
 * UTC an. In Europe/Berlin ist das 01:00 bis 01:00 und der Termin läuft über
 * zwei Tage. Deshalb gilt hier:
 *   - naive Zeitangaben (ohne Offset) werden in der Ziel-Zeitzone gelesen,
 *   - Ganztags-Termine werden über das *Datum* (Wanduhr der Eingabe) bestimmt
 *     und in der Ziel-Zeitzone von 00:00 bis 00:00 angelegt,
 *   - Mitternacht-bis-Mitternacht wird automatisch als ganztägig erkannt.
 */
final class Microsoft365EventTime
{
    /**
     * Graph liefert je nach Postfach-Einstellung Windows-Zonennamen zurück.
     * Die gängigen hier, alles andere fällt auf die Default-Zone zurück.
     */
    private const WINDOWS_ZONES = [
        'W. Europe Standard Time' => 'Europe/Berlin',
        'Central Europe Standard Time' => 'Europe/Budapest',
        'Romance Standard Time' => 'Europe/Paris',
        'GMT Standard Time' => 'Europe/London',
        'Greenwich Standard Time' => 'Atlantic/Reykjavik',
        'E. Europe Standard Time' => 'Europe/Chisinau',
        'FLE Standard Time' => 'Europe/Kiev',
        'Eastern Standard Time' => 'America/New_York',
        'Central Standard Time' => 'America/Chicago',
        'Pacific Standard Time' => 'America/Los_Angeles',
        'tzone://Microsoft/Utc' => 'UTC',
        'Coordinated Universal Time' => 'UTC',
    ];

    public static function defaultTimezone(): string
    {
        $tz = (string) config('user-connectors.microsoft365.timezone', 'Europe/Berlin');

        return self::isValidTimezone($tz) ? $tz : 'Europe/Berlin';
    }

    /**
     * Explizit übergebene Zone validieren, sonst Default.
     */
    public static function resolveTimezone(?string $timezone): string
    {
        $timezone = $timezone !== null ? trim($timezone) : '';
        if ($timezone === '') {
            return self::defaultTimezone();
        }
        if (isset(self::WINDOWS_ZONES[$timezone])) {
            return self::WINDOWS_ZONES[$timezone];
        }
        if (!self::isValidTimezone($timezone)) {
            throw new \InvalidArgumentException("Unbekannte Zeitzone \"{$timezone}\" — IANA-Name erwartet, z. B. Europe/Berlin.");
        }

        return $timezone;
    }

    public static function isValidTimezone(string $timezone): bool
    {
        return in_array($timezone, \DateTimeZone::listIdentifiers(), true) || $timezone === 'UTC';
    }

    public static function isDateOnly(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value));
    }

    public static function hasOffset(string $value): bool
    {
        return (bool) preg_match('/\d{2}:\d{2}(:\d{2}(\.\d+)?)?\s*(Z|[+-]\d{2}:?\d{2})$/i', trim($value));
    }

    /**
     * Liest einen Zeitpunkt. Mit Offset bleibt dessen Wanduhr erhalten,
     * ohne Offset wird er in $timezone interpretiert.
     */
    public static function parse(string $value, string $timezone): Carbon
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException('Leere Zeitangabe.');
        }

        try {
            return self::hasOffset($value) ? Carbon::parse($value) : Carbon::parse($value, $timezone);
        } catch (\Throwable) {
            throw new \InvalidArgumentException("Ungültige Zeitangabe \"{$value}\" — ISO-8601 erwartet (2026-11-03 oder 2026-11-03T09:30).");
        }
    }

    /**
     * Bestimmt Start/Ende eines Events.
     *
     * Ganztags (all_day=true, Start als reines Datum, oder Mitternacht bis
     * Mitternacht bzw. 23:59): end ist bei reinem Datum der *letzte Tag inklusive*;
     * bei Zeitangabe 00:00 das exklusive Ende (Graph-Konvention). Fehlt end
     * oder liegt es nicht nach dem Start, dauert der Termin einen Tag.
     *
     * @return array{start: Carbon, end: Carbon, all_day: bool, timezone: string}
     */
    public static function resolveRange(string $start, ?string $end, ?bool $allDay, string $timezone): array
    {
        $end = $end !== null && trim($end) !== '' ? trim($end) : null;
        $startAt = self::parse($start, $timezone);
        $endAt = $end !== null ? self::parse($end, $timezone) : null;

        if ($allDay === null) {
            $allDay = self::isDateOnly($start)
                || (self::isMidnight($startAt) && ($endAt === null || ($end !== null && self::isDateOnly($end)) || self::isMidnight($endAt) || self::isEndOfDay($endAt)));
        }

        if ($allDay) {
            $firstDay = Carbon::parse($startAt->format('Y-m-d'), $timezone)->startOfDay();

            if ($endAt === null) {
                $endExclusive = $firstDay->copy()->addDay();
            } elseif (self::isDateOnly((string) $end)) {
                $endExclusive = Carbon::parse($endAt->format('Y-m-d'), $timezone)->startOfDay()->addDay();
            } else {
                $endExclusive = Carbon::parse($endAt->format('Y-m-d'), $timezone)->startOfDay();
                if (!self::isMidnight($endAt)) {
                    $endExclusive->addDay();
                }
            }

            if ($endExclusive->lte($firstDay)) {
                $endExclusive = $firstDay->copy()->addDay();
            }

            return ['start' => $firstDay, 'end' => $endExclusive, 'all_day' => true, 'timezone' => $timezone];
        }

        if (self::isDateOnly($start)) {
            throw new \InvalidArgumentException('Start ist ein reines Datum, aber all_day=false — bitte Uhrzeit angeben (2026-11-03T09:30).');
        }
        if ($endAt === null) {
            throw new \InvalidArgumentException('end ist für Termine mit Uhrzeit erforderlich.');
        }

        $startAt = $startAt->copy()->setTimezone($timezone);
        $endAt = $endAt->copy()->setTimezone($timezone);
        if ($endAt->lte($startAt)) {
            throw new \InvalidArgumentException('end muss nach start liegen.');
        }

        return ['start' => $startAt, 'end' => $endAt, 'all_day' => false, 'timezone' => $timezone];
    }

    /**
     * Carbon → Graph dateTimeTimeZone (Wanduhr in $timezone, ohne Offset).
     */
    public static function toGraph(Carbon $time, string $timezone): array
    {
        return [
            'dateTime' => $time->copy()->setTimezone($timezone)->format('Y-m-d\TH:i:s'),
            'timeZone' => $timezone,
        ];
    }

    /**
     * Graph dateTimeTimeZone → Carbon in $timezone. Der dateTime-String hat
     * keinen Offset; ohne die mitgeschickte Zone landet er sonst in der
     * PHP-Default-Zone und die Wanduhr stimmt nicht mehr.
     */
    public static function fromGraph(?array $slot, string $timezone): ?Carbon
    {
        if (!$slot || empty($slot['dateTime'])) {
            return null;
        }

        $sourceZone = (string) ($slot['timeZone'] ?? 'UTC');
        $sourceZone = self::WINDOWS_ZONES[$sourceZone] ?? $sourceZone;
        if (!self::isValidTimezone($sourceZone)) {
            $sourceZone = $timezone;
        }

        // Graph schickt 7 Nachkommastellen ("2026-11-03T00:00:00.0000000").
        $raw = preg_replace('/(\.\d{6})\d+$/', '$1', (string) $slot['dateTime']);

        return Carbon::parse($raw, $sourceZone)->setTimezone($timezone);
    }

    private static function isMidnight(Carbon $time): bool
    {
        return $time->format('H:i:s') === '00:00:00';
    }

    /** 23:59 / 23:59:59 — die andere übliche Schreibweise für "bis Tagesende". */
    private static function isEndOfDay(Carbon $time): bool
    {
        return $time->format('H:i') === '23:59';
    }
}
