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
    public function __construct(
        protected Microsoft365ApiService $api,
    ) {}

    public function listEvents(User $user, Carbon $from, Carbon $to, ?Pagination $pagination = null): array
    {
        $top = $pagination?->perPage ?? 25;

        $data = $this->api->get($user, '/me/calendarview', [
            'startdatetime' => $from->toIso8601String(),
            'enddatetime' => $to->toIso8601String(),
            '$top' => $top,
            '$orderby' => 'start/dateTime',
            '$select' => 'id,subject,body,start,end,isAllDay,showAs,location,attendees,onlineMeeting,webLink',
        ]);

        $events = array_map(
            fn (array $e) => $this->mapEvent($e),
            $data['value'] ?? []
        );

        $resultPagination = new Pagination(
            page: $pagination?->page ?? 1,
            perPage: $top,
            total: $data['@odata.count'] ?? null,
            nextLink: $data['@odata.nextLink'] ?? null,
        );

        return ['events' => $events, 'pagination' => $resultPagination];
    }

    public function getEvent(User $user, string $eventId): CalendarEvent
    {
        $data = $this->api->get($user, "/me/events/{$eventId}", [
            '$select' => 'id,subject,body,start,end,isAllDay,showAs,location,attendees,onlineMeeting,webLink',
        ]);

        return $this->mapEvent($data);
    }

    public function createEvent(User $user, string $title, Carbon $start, Carbon $end, array $attendees = [], array $options = []): CalendarEvent
    {
        // Graph akzeptiert nur IANA-Zeitzonen (Europe/Berlin, UTC, …) — nicht
        // Offsets wie "+02:00", die Carbon zurückgibt, wenn der Input mit
        // numerischem Offset geparst wurde. Fallback auf UTC + Konvertierung.
        $startTz = $this->normaliseTimeZone($start);
        $endTz = $this->normaliseTimeZone($end);

        $body = [
            'subject' => $title,
            'start' => [
                'dateTime' => $startTz === 'UTC' ? $start->copy()->utc()->format('Y-m-d\TH:i:s') : $start->format('Y-m-d\TH:i:s'),
                'timeZone' => $startTz,
            ],
            'end' => [
                'dateTime' => $endTz === 'UTC' ? $end->copy()->utc()->format('Y-m-d\TH:i:s') : $end->format('Y-m-d\TH:i:s'),
                'timeZone' => $endTz,
            ],
        ];

        if (!empty($attendees)) {
            $body['attendees'] = array_map(fn ($a) => [
                'emailAddress' => [
                    'address' => is_string($a) ? $a : ($a['email'] ?? ''),
                    'name' => is_string($a) ? null : ($a['name'] ?? null),
                ],
                'type' => 'required',
            ], $attendees);
        }

        if (!empty($options['description'])) {
            $body['body'] = [
                'contentType' => 'HTML',
                'content' => $options['description'],
            ];
        }

        if (!empty($options['location'])) {
            $body['location'] = ['displayName' => $options['location']];
        }

        if (!empty($options['is_all_day'])) {
            $body['isAllDay'] = true;
            $body['start'] = ['dateTime' => $start->format('Y-m-d'), 'timeZone' => 'UTC'];
            $body['end'] = ['dateTime' => $end->format('Y-m-d'), 'timeZone' => 'UTC'];
        }

        if (!empty($options['online_meeting'])) {
            $body['isOnlineMeeting'] = true;
            $body['onlineMeetingProvider'] = 'teamsForBusiness';
        }

        $data = $this->api->post($user, '/me/events', $body);

        return $this->mapEvent($data);
    }

    /**
     * Graph braucht IANA-TZ-Namen ("Europe/Berlin", "UTC", …). Wenn Carbon
     * den TZ-Namen als Offset ("+02:00") zurückgibt — was passiert, wenn
     * der Input mit numerischem Offset geparst wurde — wechseln wir auf
     * UTC und konvertieren die Zeit entsprechend.
     */
    protected function normaliseTimeZone(Carbon $time): string
    {
        $name = $time->timezone->getName();
        // Graph rejects offset-form ("+02:00") and the "Z" military designator.
        // Fall back to UTC in either case — the caller's wall-clock intent is
        // preserved because we convert the dateTime alongside the timeZone.
        if ($name === 'Z' || preg_match('/^[+-]\d{2}:?\d{2}$/', $name)) {
            return 'UTC';
        }
        return $name;
    }

    public function updateEvent(User $user, string $eventId, array $changes): CalendarEvent
    {
        $body = [];

        if (isset($changes['title'])) {
            $body['subject'] = $changes['title'];
        }
        if (isset($changes['start'])) {
            $start = $changes['start'] instanceof Carbon ? $changes['start'] : Carbon::parse($changes['start']);
            $body['start'] = [
                'dateTime' => $start->format('Y-m-d\TH:i:s'),
                'timeZone' => $start->timezone->getName(),
            ];
        }
        if (isset($changes['end'])) {
            $end = $changes['end'] instanceof Carbon ? $changes['end'] : Carbon::parse($changes['end']);
            $body['end'] = [
                'dateTime' => $end->format('Y-m-d\TH:i:s'),
                'timeZone' => $end->timezone->getName(),
            ];
        }
        if (isset($changes['description'])) {
            $body['body'] = ['contentType' => 'HTML', 'content' => $changes['description']];
        }
        if (isset($changes['location'])) {
            $body['location'] = ['displayName' => $changes['location']];
        }

        $data = $this->api->patch($user, "/me/events/{$eventId}", $body);

        return $this->mapEvent($data);
    }

    public function deleteEvent(User $user, string $eventId): bool
    {
        return $this->api->delete($user, "/me/events/{$eventId}");
    }

    /**
     * Antwort auf eine Termin-Einladung: accept / decline / tentativelyAccept.
     * Optional: kurzer Kommentar an den Organisator + sendResponse-Flag.
     */
    public function respondToEvent(User $user, string $eventId, string $response, ?string $comment = null, bool $sendResponse = true): bool
    {
        $action = match (strtolower($response)) {
            'accept', 'accepted', 'ja' => 'accept',
            'decline', 'declined', 'nein' => 'decline',
            'tentative', 'tentativelyaccept', 'vielleicht', 'maybe' => 'tentativelyAccept',
            default => throw new \InvalidArgumentException("Unknown response: $response"),
        };

        $payload = ['sendResponse' => $sendResponse];
        if ($comment !== null && $comment !== '') {
            $payload['comment'] = $comment;
        }

        $this->api->post($user, "/me/events/{$eventId}/{$action}", $payload);

        return true;
    }

    public function getAvailability(User $user, Carbon $from, Carbon $to): array
    {
        $data = $this->api->get($user, '/me/calendarview', [
            'startdatetime' => $from->toIso8601String(),
            'enddatetime' => $to->toIso8601String(),
            '$select' => 'start,end,showAs',
            '$top' => 100,
        ]);

        $slots = [];
        foreach ($data['value'] ?? [] as $event) {
            $showAs = strtolower($event['showAs'] ?? 'busy');

            $slots[] = new Availability(
                status: match ($showAs) {
                    'free' => 'free',
                    'tentative' => 'tentative',
                    'oof' => 'out_of_office',
                    default => 'busy',
                },
                start: Carbon::parse($event['start']['dateTime'] ?? now()),
                end: Carbon::parse($event['end']['dateTime'] ?? now()),
            );
        }

        return $slots;
    }

    protected function mapEvent(array $data): CalendarEvent
    {
        $attendees = array_map(fn (array $a) => new Attendee(
            email: $a['emailAddress']['address'] ?? '',
            name: $a['emailAddress']['name'] ?? null,
            status: $a['status']['response'] ?? 'none',
            type: $a['type'] ?? 'required',
        ), $data['attendees'] ?? []);

        $showAs = strtolower($data['showAs'] ?? 'busy');
        $status = match ($showAs) {
            'free' => 'confirmed',
            'tentative' => 'tentative',
            'oof' => 'confirmed',
            default => 'confirmed',
        };

        $onlineMeetingUrl = $data['onlineMeeting']['joinUrl'] ?? null;

        return new CalendarEvent(
            id: $data['id'] ?? '',
            provider: 'microsoft365',
            title: $data['subject'] ?? '',
            description: $data['body']['content'] ?? null,
            start: $this->parseGraphDateTime($data['start'] ?? null),
            end: $this->parseGraphDateTime($data['end'] ?? null),
            isAllDay: $data['isAllDay'] ?? false,
            status: $status,
            location: $data['location']['displayName'] ?? null,
            attendees: $attendees,
            onlineMeetingUrl: $onlineMeetingUrl,
            raw: $data,
        );
    }

    /**
     * Graph schickt start/end als { dateTime, timeZone } und der dateTime-
     * String ist *ohne* Offset (z. B. "2026-06-08T08:00:00.0000000"). Wenn
     * man den nur durch Carbon::parse() jagt, landet er in PHPs Default-TZ
     * — der Wall-Clock stimmt dann nicht mehr. Wir parsen explizit mit der
     * mitgeschickten timeZone und kippen das Ergebnis in UTC, damit das
     * abgehende ISO8601 mit klarem Offset rauskommt.
     */
    protected function parseGraphDateTime(?array $slot): Carbon
    {
        if (!$slot || empty($slot['dateTime'])) {
            return Carbon::now();
        }
        try {
            $tz = $slot['timeZone'] ?? 'UTC';
            return Carbon::parse($slot['dateTime'], $tz)->utc();
        } catch (\Throwable) {
            return Carbon::parse($slot['dateTime']);
        }
    }
}
