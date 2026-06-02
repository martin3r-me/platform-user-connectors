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
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'title' => $this->title,
            'description' => $this->description,
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
            'is_all_day' => $this->isAllDay,
            'status' => $this->status,
            'location' => $this->location,
            'attendees' => array_map(fn (Attendee $a) => $a->toArray(), $this->attendees),
            'online_meeting_url' => $this->onlineMeetingUrl,
        ];
    }
}
