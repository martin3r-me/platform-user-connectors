<?php

namespace Platform\UserConnectors\DTOs;

use Carbon\Carbon;

class CalendarEvent
{
    public function __construct(
        public readonly string $id,
        public readonly string $provider,
        public readonly string $title,
        public readonly ?string $description,
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly bool $isAllDay,
        public readonly string $status,
        public readonly ?string $location,
        /** @var Attendee[] */
        public readonly array $attendees,
        public readonly ?string $onlineMeetingUrl,
        public readonly array $raw,
        // free | tentative | busy | oof | workingElsewhere | unknown
        public readonly ?string $showAs = null,
        public readonly ?string $timezone = null,
        public readonly ?string $organizer = null,
        // Eigene Antwort auf die Einladung: organizer | accepted | declined | tentativelyAccepted | notResponded | none
        public readonly ?string $responseStatus = null,
        public readonly bool $isCancelled = false,
        // singleInstance | occurrence | exception | seriesMaster
        public readonly ?string $type = null,
        public readonly ?string $seriesMasterId = null,
        public readonly ?string $webLink = null,
    ) {}

    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'provider' => $this->provider,
            'title' => $this->title,
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
            'is_all_day' => $this->isAllDay,
        ];

        // Ganztags: Datum statt Zeitpunkt — sonst liest ein Agent 00:00+01:00
        // als 23:00 UTC des Vortags und der Termin "läuft über zwei Tage".
        if ($this->isAllDay) {
            $data['start_date'] = $this->start->format('Y-m-d');
            $data['end_date'] = $this->end->copy()->subDay()->format('Y-m-d');
            $data['days'] = max(1, (int) round($this->start->diffInDays($this->end, true)));
        }

        return $data + array_filter([
            'timezone' => $this->timezone,
            'show_as' => $this->showAs,
            'status' => $this->status,
            'response_status' => $this->responseStatus,
            'is_cancelled' => $this->isCancelled ?: null,
            'location' => $this->location,
            'organizer' => $this->organizer,
            'attendees' => $this->attendees ? array_map(fn (Attendee $a) => $a->toArray(), $this->attendees) : null,
            'online_meeting_url' => $this->onlineMeetingUrl,
            'description' => $this->description,
            'type' => $this->type,
            'series_master_id' => $this->seriesMasterId,
            'web_link' => $this->webLink,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
