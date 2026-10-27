<?php

namespace Platform\UserConnectors\Services\Microsoft365;

use Carbon\Carbon;
use Platform\Core\Models\User;
use Platform\UserConnectors\Contracts\CalendarConnector;
use Platform\UserConnectors\DTOs\Attendee;
use Platform\UserConnectors\DTOs\Availability;
use Platform\UserConnectors\DTOs\CalendarEvent;
use Platform\UserConnectors\DTOs\Pagination;

class Microsoft365CalendarConnector implements CalendarConnector
{
    public const SHOW_AS = ['free', 'tentative', 'busy', 'oof', 'workingElsewhere'];

    /** Obergrenze für das automatische Nachladen von calendarView-Seiten. */
    public const MAX_LIST_LIMIT = 500;

    private const EVENT_SELECT = 'id,iCalUId,subject,start,end,isAllDay,showAs,location,attendees,organizer,'
        . 'responseStatus,isCancelled,onlineMeeting,webLink,seriesMasterId,type';

    public function __construct(
        protected Microsoft365ApiService $api,
    ) {}

    /**
     * Events im Zeitraum (calendarView: Serien sind in Einzeltermine aufgelöst).
     * Folgt @odata.nextLink bis $options['limit'] erreicht ist.
     *
     * Options: timezone, body_format (none|preview|text|html), limit.
     */
    public function listEvents(User $user, Carbon $from, Carbon $to, ?Pagination $pagination = null, array $options = []): array
    {
        $tz = Microsoft365EventTime::resolveTimezone($options['timezone'] ?? null);
        $bodyFormat = $options['body_format'] ?? 'preview';
        $limit = max(1, min(self::MAX_LIST_LIMIT, (int) ($options['limit'] ?? $pagination?->perPage ?? 50)));

        $data = $this->api->get($user, '/me/calendarView', [
            'startDateTime' => $from->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'endDateTime' => $to->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            '$top' => min($limit, 100),
            '$orderby' => 'start/dateTime',
            '$select' => $this->selectFor($bodyFormat),
        ], $this->headersFor($tz, $bodyFormat));

        $rows = $data['value'] ?? [];
        $nextLink = $data['@odata.nextLink'] ?? null;
        while ($nextLink && count($rows) < $limit) {
            $data = $this->api->getNextPage($user, $nextLink, $this->headersFor($tz, $bodyFormat));
            $rows = array_merge($rows, $data['value'] ?? []);
            $nextLink = $data['@odata.nextLink'] ?? null;
        }

        $truncated = count($rows) > $limit || $nextLink !== null;
        $rows = array_slice($rows, 0, $limit);

        return [
            'events' => array_map(fn (array $e) => $this->mapEvent($e, $tz, $bodyFormat), $rows),
            'pagination' => new Pagination(
                page: 1,
                perPage: $limit,
                total: $truncated ? null : count($rows),
                nextLink: $truncated ? $nextLink : null,
            ),
            'truncated' => $truncated,
            'timezone' => $tz,
        ];
    }

    public function getEvent(User $user, string $eventId, array $options = []): CalendarEvent
    {
        $tz = Microsoft365EventTime::resolveTimezone($options['timezone'] ?? null);
        $bodyFormat = $options['body_format'] ?? 'text';

        $data = $this->api->get(
            $user,
            '/me/events/' . Microsoft365ApiService::segment($eventId),
            ['$select' => $this->selectFor($bodyFormat)],
            $this->headersFor($tz, $bodyFormat),
        );

        return $this->mapEvent($data, $tz, $bodyFormat);
    }

    /**
     * Options: all_day (bool|null = automatisch erkennen), timezone, show_as,
     * description, body_type (text|html), location, rooms[], online_meeting,
     * reminder_minutes, sensitivity, importance, categories[].
     *
     * Ganztags-Termine werden in der Ziel-Zeitzone von 00:00 bis 00:00
     * angelegt und stehen ohne explizites show_as auf "free" (Outlook-Default).
     */
    public function createEvent(User $user, string $title, Carbon $start, Carbon $end, array $attendees = [], array $options = []): CalendarEvent
    {
        $tz = Microsoft365EventTime::resolveTimezone($options['timezone'] ?? null);
        $allDay = $options['all_day'] ?? $options['is_all_day'] ?? null;
        $range = Microsoft365EventTime::resolveRange(
            $start->toIso8601String(),
            $end->toIso8601String(),
            $allDay === null ? null : (bool) $allDay,
            $tz,
        );

        $body = array_merge(
            ['subject' => $title],
            $this->timePayload($range),
        );

        $showAs = $options['show_as'] ?? ($range['all_day'] ? 'free' : null);
        if ($showAs !== null) {
            $body['showAs'] = $this->validShowAs($showAs);
        }

        $body['attendees'] = $this->mapAttendees($attendees, $options['rooms'] ?? []);
        if ($body['attendees'] === []) {
            unset($body['attendees']);
        }

        if (!empty($options['location'])) {
            $body['location'] = ['displayName' => (string) $options['location']];
        } elseif (count($rooms = array_filter((array) ($options['rooms'] ?? []))) === 1) {
            // Ein Raum ohne explizite Location → Raum als Ort anzeigen.
            $body['location'] = [
                'displayName' => trim((string) reset($rooms)),
                'locationEmailAddress' => trim((string) reset($rooms)),
            ];
        }

        $body += $this->optionalFields($options);

        if (!empty($options['online_meeting'])) {
            $body['isOnlineMeeting'] = true;
            $body['onlineMeetingProvider'] = 'teamsForBusiness';
        }

        $data = $this->api->post($user, '/me/events', $body, $this->headersFor($tz, 'text'));

        return $this->mapEvent($data, $tz, 'text');
    }

    /**
     * Changes: title, start, end (string|Carbon), all_day, timezone, show_as,
     * description, body_type, location, attendees (ersetzt alle), online_meeting,
     * reminder_minutes, sensitivity, importance, categories.
     *
     * Wird nur ein Teil der Zeit geändert (nur start, nur end, nur all_day),
     * wird der Rest aus dem bestehenden Event ergänzt — beim Verschieben
     * bleibt die Dauer erhalten.
     */
    public function updateEvent(User $user, string $eventId, array $changes): CalendarEvent
    {
        $tz = Microsoft365EventTime::resolveTimezone($changes['timezone'] ?? null);
        $body = [];

        if (isset($changes['title'])) {
            $body['subject'] = (string) $changes['title'];
        }

        $start = $this->timeString($changes['start'] ?? null);
        $end = $this->timeString($changes['end'] ?? null);
        $allDay = array_key_exists('all_day', $changes) && $changes['all_day'] !== null ? (bool) $changes['all_day'] : null;

        if ($start !== null || $end !== null || $allDay !== null) {
            $range = $this->resolveUpdateRange($user, $eventId, $start, $end, $allDay, $tz);
            $body += $this->timePayload($range);
        }

        if (isset($changes['show_as'])) {
            $body['showAs'] = $this->validShowAs((string) $changes['show_as']);
        }
        if (array_key_exists('location', $changes) && $changes['location'] !== null) {
            $body['location'] = ['displayName' => (string) $changes['location']];
        }
        if (isset($changes['attendees'])) {
            $body['attendees'] = $this->mapAttendees((array) $changes['attendees'], (array) ($changes['rooms'] ?? []));
        }
        if (isset($changes['online_meeting'])) {
            $body['isOnlineMeeting'] = (bool) $changes['online_meeting'];
            if ($body['isOnlineMeeting']) {
                $body['onlineMeetingProvider'] = 'teamsForBusiness';
            }
        }

        $body += $this->optionalFields($changes);

        if ($body === []) {
            throw new \InvalidArgumentException('Keine Änderungen übergeben.');
        }

        $data = $this->api->patch(
            $user,
            '/me/events/' . Microsoft365ApiService::segment($eventId),
            $body,
            $this->headersFor($tz, 'text'),
        );

        return $this->mapEvent($data, $tz, 'text');
    }

    public function deleteEvent(User $user, string $eventId): bool
    {
        return $this->api->delete($user, '/me/events/' . Microsoft365ApiService::segment($eventId));
    }

    /**
     * Absage als Organisator: Teilnehmer bekommen eine Absage-Mail (statt
     * eines stillen Löschens).
     */
    public function cancelEvent(User $user, string $eventId, ?string $comment = null): bool
    {
        $payload = $comment !== null && $comment !== '' ? ['comment' => $comment] : [];
        $this->api->post($user, '/me/events/' . Microsoft365ApiService::segment($eventId) . '/cancel', $payload);

        return true;
    }

    /**
     * Listet alle Raum-Listen (RoomList-Resources) im Tenant — Container für
     * Räume, typischerweise pro Gebäude oder Standort.
     */
    public function listRoomLists(User $user): array
    {
        $data = $this->api->get($user, '/places/microsoft.graph.roomlist', []);

        return array_map(fn (array $rl) => [
            'id' => (string) ($rl['id'] ?? ''),
            'name' => (string) ($rl['displayName'] ?? ''),
            'email' => (string) ($rl['emailAddress'] ?? ''),
        ], $data['value'] ?? []);
    }

    /**
     * Räume (Resource-Mailboxen) — alle im Tenant oder nur aus einer Raum-Liste.
     */
    public function listRooms(User $user, ?string $roomListEmail = null): array
    {
        $endpoint = $roomListEmail
            ? '/places/' . Microsoft365ApiService::segment($roomListEmail) . '/microsoft.graph.roomlist/rooms'
            : '/places/microsoft.graph.room';

        $data = $this->api->get($user, $endpoint, ['$top' => 100]);

        return array_map(fn (array $r) => [
            'id' => (string) ($r['id'] ?? ''),
            'name' => (string) ($r['displayName'] ?? ''),
            'email' => (string) ($r['emailAddress'] ?? ''),
            'capacity' => isset($r['capacity']) ? (int) $r['capacity'] : null,
            'building' => $r['building'] ?? null,
            'floor' => isset($r['floorNumber']) ? (string) $r['floorNumber'] : ($r['floorLabel'] ?? null),
        ], $data['value'] ?? []);
    }

    /**
     * Gemeinsame freie Slots via POST /me/findMeetingTimes.
     *
     * Options: timezone, work_hours_only (Default true), minimum_attendee_percentage.
     */
    public function findMeetingTimes(
        User $user,
        array $participants,
        Carbon $after,
        Carbon $before,
        int $durationMinutes = 30,
        int $maxCandidates = 5,
        array $options = [],
    ): array {
        $tz = Microsoft365EventTime::resolveTimezone($options['timezone'] ?? null);
        $attendees = array_values(array_map(fn ($email) => [
            'type' => 'required',
            'emailAddress' => ['address' => trim((string) $email)],
        ], array_filter($participants)));

        $payload = [
            'attendees' => $attendees,
            'timeConstraint' => [
                'activityDomain' => ($options['work_hours_only'] ?? true) ? 'work' : 'unrestricted',
                'timeSlots' => [[
                    'start' => Microsoft365EventTime::toGraph($after, $tz),
                    'end' => Microsoft365EventTime::toGraph($before, $tz),
                ]],
            ],
            'meetingDuration' => 'PT' . max(5, $durationMinutes) . 'M',
            'maxCandidates' => max(1, min(50, $maxCandidates)),
            'isOrganizerOptional' => false,
            'returnSuggestionReasons' => true,
            'minimumAttendeePercentage' => (int) ($options['minimum_attendee_percentage'] ?? 100),
        ];

        $data = $this->api->post($user, '/me/findMeetingTimes', $payload, $this->headersFor($tz, 'none'));

        $suggestions = [];
        foreach ($data['meetingTimeSuggestions'] ?? [] as $row) {
            $slot = $row['meetingTimeSlot'] ?? [];
            $suggestions[] = [
                'start' => Microsoft365EventTime::fromGraph($slot['start'] ?? null, $tz) ?? $after->copy(),
                'end' => Microsoft365EventTime::fromGraph($slot['end'] ?? null, $tz) ?? $after->copy(),
                'confidence' => (int) ($row['confidence'] ?? 0),
                'reason' => $row['suggestionReason'] ?? null,
                'attendee_availability' => array_map(fn ($a) => [
                    'email' => $a['attendee']['emailAddress']['address'] ?? '',
                    'availability' => $a['availability'] ?? 'unknown',
                ], $row['attendeeAvailability'] ?? []),
            ];
        }

        return [
            'suggestions' => $suggestions,
            'empty_reason' => $suggestions === [] ? ($data['emptySuggestionsReason'] ?? null) : null,
        ];
    }

    /**
     * Frei/Belegt-Zeiten mehrerer Personen/Räume via POST /me/calendar/getSchedule.
     * Liefert pro Adresse die belegten Blöcke (inkl. Ganztags-Abwesenheiten)
     * und die Arbeitszeiten.
     */
    public function getSchedule(User $user, array $emails, Carbon $from, Carbon $to, string $timezone, int $intervalMinutes = 30): array
    {
        $emails = array_values(array_filter(array_map(fn ($e) => trim((string) $e), $emails)));

        $data = $this->api->post($user, '/me/calendar/getSchedule', [
            'schedules' => $emails,
            'startTime' => Microsoft365EventTime::toGraph($from, $timezone),
            'endTime' => Microsoft365EventTime::toGraph($to, $timezone),
            'availabilityViewInterval' => max(5, min(1440, $intervalMinutes)),
        ], $this->headersFor($timezone, 'none'));

        return array_map(function (array $s) use ($timezone) {
            if (!empty($s['error'])) {
                return [
                    'email' => $s['scheduleId'] ?? '',
                    'error' => $s['error']['message'] ?? 'Kalender nicht abrufbar.',
                ];
            }

            return [
                'email' => $s['scheduleId'] ?? '',
                'busy' => array_map(function (array $item) use ($timezone) {
                    $start = Microsoft365EventTime::fromGraph($item['start'] ?? null, $timezone);
                    $end = Microsoft365EventTime::fromGraph($item['end'] ?? null, $timezone);

                    return array_filter([
                        'status' => $item['status'] ?? 'busy',
                        'start' => $start?->toIso8601String(),
                        'end' => $end?->toIso8601String(),
                        'subject' => $item['subject'] ?? null,
                        'location' => $item['location'] ?? null,
                        'is_private' => $item['isPrivate'] ?? null,
                    ], fn ($v) => $v !== null && $v !== '');
                }, $s['scheduleItems'] ?? []),
                'working_hours' => isset($s['workingHours']) ? [
                    'days' => $s['workingHours']['daysOfWeek'] ?? [],
                    'start' => $s['workingHours']['startTime'] ?? null,
                    'end' => $s['workingHours']['endTime'] ?? null,
                    'timezone' => $s['workingHours']['timeZone']['name'] ?? null,
                ] : null,
            ];
        }, $data['value'] ?? []);
    }

    /**
     * Antwort auf eine Termin-Einladung: accept / decline / tentativelyAccept.
     */
    public function respondToEvent(User $user, string $eventId, string $response, ?string $comment = null, bool $sendResponse = true): bool
    {
        $action = match (strtolower(trim($response))) {
            'accept', 'accepted', 'ja', 'zusagen' => 'accept',
            'decline', 'declined', 'nein', 'absagen' => 'decline',
            'tentative', 'tentativelyaccept', 'vielleicht', 'maybe' => 'tentativelyAccept',
            default => throw new \InvalidArgumentException("Unbekannte Antwort \"{$response}\" — erwartet: accept, decline, tentative."),
        };

        $payload = ['sendResponse' => $sendResponse];
        if ($comment !== null && $comment !== '') {
            $payload['comment'] = $comment;
        }

        $this->api->post($user, '/me/events/' . Microsoft365ApiService::segment($eventId) . '/' . $action, $payload);

        return true;
    }

    public function getAvailability(User $user, Carbon $from, Carbon $to): array
    {
        $tz = Microsoft365EventTime::defaultTimezone();
        $result = $this->listEvents($user, $from, $to, null, ['timezone' => $tz, 'body_format' => 'none', 'limit' => 200]);

        return array_map(fn (CalendarEvent $e) => new Availability(
            status: match ($e->showAs) {
                'free' => 'free',
                'tentative' => 'tentative',
                'oof' => 'out_of_office',
                default => 'busy',
            },
            start: $e->start,
            end: $e->end,
        ), $result['events']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Fehlende Teile der Zeitänderung aus dem bestehenden Event ergänzen.
     */
    protected function resolveUpdateRange(User $user, string $eventId, ?string $start, ?string $end, ?bool $allDay, string $tz): array
    {
        if ($start !== null && $end !== null) {
            return Microsoft365EventTime::resolveRange($start, $end, $allDay, $tz);
        }

        $current = $this->getEvent($user, $eventId, ['timezone' => $tz, 'body_format' => 'none']);
        $currentDays = max(1, (int) round($current->start->diffInDays($current->end, true)));

        if ($start !== null) {
            $startAt = Microsoft365EventTime::parse($start, $tz);
            // Reines Datum → ganztags; Mitternacht bleibt ganztags, wenn es das schon war.
            $targetAllDay = $allDay ?? (Microsoft365EventTime::isDateOnly($start)
                || ($current->isAllDay && $startAt->format('H:i:s') === '00:00:00'));

            if ($targetAllDay) {
                $days = $current->isAllDay ? $currentDays : 1;
                $first = Carbon::parse($startAt->format('Y-m-d'), $tz);

                return Microsoft365EventTime::resolveRange($first->format('Y-m-d'), $first->copy()->addDays($days - 1)->format('Y-m-d'), true, $tz);
            }

            // Verschieben: Dauer bleibt erhalten (aus Ganztags → 1 Stunde).
            $minutes = $current->isAllDay ? 60 : max(1, (int) round($current->start->diffInMinutes($current->end, true)));

            return Microsoft365EventTime::resolveRange($start, $startAt->copy()->addMinutes($minutes)->toIso8601String(), false, $tz);
        }

        if ($end !== null) {
            $startString = $current->isAllDay ? $current->start->format('Y-m-d') : $current->start->toIso8601String();

            return Microsoft365EventTime::resolveRange($startString, $end, $allDay ?? ($current->isAllDay ? true : false), $tz);
        }

        // Nur all_day umgestellt.
        if ($allDay === true) {
            return Microsoft365EventTime::resolveRange(
                $current->start->format('Y-m-d'),
                $current->isAllDay ? $current->end->copy()->subDay()->format('Y-m-d') : $current->end->toIso8601String(),
                true,
                $tz,
            );
        }
        if ($current->isAllDay) {
            throw new \InvalidArgumentException('Ganztags → mit Uhrzeit: bitte start und end mit Uhrzeit angeben.');
        }

        return [
            'start' => $current->start,
            'end' => $current->end,
            'all_day' => false,
            'timezone' => $tz,
        ];
    }

    protected function timePayload(array $range): array
    {
        return [
            'start' => Microsoft365EventTime::toGraph($range['start'], $range['timezone']),
            'end' => Microsoft365EventTime::toGraph($range['end'], $range['timezone']),
            'isAllDay' => $range['all_day'],
        ];
    }

    protected function timeString(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    protected function validShowAs(string $showAs): string
    {
        $map = array_combine(array_map('strtolower', self::SHOW_AS), self::SHOW_AS);
        $map += ['frei' => 'free', 'belegt' => 'busy', 'abwesend' => 'oof', 'mit vorbehalt' => 'tentative', 'out_of_office' => 'oof'];
        $key = strtolower(trim($showAs));
        if (!isset($map[$key])) {
            throw new \InvalidArgumentException("show_as \"{$showAs}\" unbekannt — erlaubt: " . implode(', ', self::SHOW_AS) . '.');
        }

        return $map[$key];
    }

    /**
     * Attendees als Strings oder {email, name, type}; Räume als resource.
     */
    protected function mapAttendees(array $attendees, array $rooms = []): array
    {
        $mapped = [];
        foreach ($attendees as $a) {
            $email = is_string($a) ? trim($a) : trim((string) ($a['email'] ?? ''));
            if ($email === '') {
                continue;
            }
            $type = is_array($a) && in_array($a['type'] ?? null, ['optional', 'resource'], true) ? $a['type'] : 'required';
            $entry = ['emailAddress' => ['address' => $email], 'type' => $type];
            if (is_array($a) && !empty($a['name'])) {
                $entry['emailAddress']['name'] = (string) $a['name'];
            }
            $mapped[] = $entry;
        }
        foreach (array_filter($rooms) as $room) {
            $mapped[] = ['emailAddress' => ['address' => trim((string) $room)], 'type' => 'resource'];
        }

        return $mapped;
    }

    protected function optionalFields(array $options): array
    {
        $body = [];
        if (isset($options['description'])) {
            $body['body'] = [
                'contentType' => 'HTML',
                'content' => Microsoft365MailConnector::normalizeBody((string) $options['description'], $options['body_type'] ?? null),
            ];
        }
        if (isset($options['reminder_minutes'])) {
            $minutes = (int) $options['reminder_minutes'];
            $body['isReminderOn'] = $minutes >= 0;
            if ($minutes >= 0) {
                $body['reminderMinutesBeforeStart'] = $minutes;
            }
        }
        if (isset($options['sensitivity'])) {
            $body['sensitivity'] = in_array($options['sensitivity'], ['normal', 'personal', 'private', 'confidential'], true)
                ? $options['sensitivity'] : 'normal';
        }
        if (isset($options['importance'])) {
            $body['importance'] = in_array($options['importance'], ['low', 'normal', 'high'], true) ? $options['importance'] : 'normal';
        }
        if (isset($options['categories'])) {
            $body['categories'] = array_values(array_map('strval', (array) $options['categories']));
        }

        return $body;
    }

    protected function selectFor(string $bodyFormat): string
    {
        return self::EVENT_SELECT . match ($bodyFormat) {
            'none' => '',
            'preview' => ',bodyPreview',
            default => ',body',
        };
    }

    /**
     * Prefer-Header: Zeiten in der Ziel-Zone statt UTC, Body optional als Text.
     */
    protected function headersFor(string $tz, string $bodyFormat): array
    {
        $prefer = ['outlook.timezone="' . $tz . '"'];
        if ($bodyFormat === 'text') {
            $prefer[] = 'outlook.body-content-type="text"';
        }

        return ['Prefer' => implode(', ', $prefer)];
    }

    protected function mapEvent(array $data, ?string $tz = null, string $bodyFormat = 'html'): CalendarEvent
    {
        $tz ??= Microsoft365EventTime::defaultTimezone();

        $attendees = array_map(fn (array $a) => new Attendee(
            email: $a['emailAddress']['address'] ?? '',
            name: $a['emailAddress']['name'] ?? null,
            status: $a['status']['response'] ?? 'none',
            type: $a['type'] ?? 'required',
        ), $data['attendees'] ?? []);

        $showAs = $data['showAs'] ?? null;
        $isCancelled = (bool) ($data['isCancelled'] ?? false);

        $description = match ($bodyFormat) {
            'none' => null,
            'preview' => $data['bodyPreview'] ?? null,
            default => $data['body']['content'] ?? null,
        };
        if ($description !== null && trim($description) === '') {
            $description = null;
        }

        $organizer = $data['organizer']['emailAddress'] ?? null;

        return new CalendarEvent(
            id: $data['id'] ?? '',
            provider: 'microsoft365',
            title: $data['subject'] ?? '',
            description: $description,
            start: Microsoft365EventTime::fromGraph($data['start'] ?? null, $tz) ?? Carbon::now($tz),
            end: Microsoft365EventTime::fromGraph($data['end'] ?? null, $tz) ?? Carbon::now($tz),
            isAllDay: (bool) ($data['isAllDay'] ?? false),
            status: $isCancelled ? 'cancelled' : ($showAs === 'tentative' ? 'tentative' : 'confirmed'),
            location: ($data['location']['displayName'] ?? '') ?: null,
            attendees: $attendees,
            onlineMeetingUrl: $data['onlineMeeting']['joinUrl'] ?? null,
            raw: $data,
            showAs: $showAs,
            timezone: $tz,
            organizer: $organizer ? trim(($organizer['name'] ?? '') . ' <' . ($organizer['address'] ?? '') . '>') : null,
            responseStatus: $data['responseStatus']['response'] ?? null,
            isCancelled: $isCancelled,
            type: $data['type'] ?? null,
            seriesMasterId: $data['seriesMasterId'] ?? null,
            webLink: $data['webLink'] ?? null,
        );
    }
}
