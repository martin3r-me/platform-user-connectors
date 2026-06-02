<?php

namespace Platform\UserConnectors\Contracts;

use Carbon\Carbon;
use Platform\Core\Models\User;
use Platform\UserConnectors\DTOs\Availability;
use Platform\UserConnectors\DTOs\CalendarEvent;
use Platform\UserConnectors\DTOs\Pagination;

interface CalendarConnector
{
    /**
     * @return array{events: CalendarEvent[], pagination: Pagination}
     */
    public function listEvents(User $user, Carbon $from, Carbon $to, ?Pagination $pagination = null): array;

    public function getEvent(User $user, string $eventId): CalendarEvent;

    public function createEvent(User $user, string $title, Carbon $start, Carbon $end, array $attendees = [], array $options = []): CalendarEvent;

    public function updateEvent(User $user, string $eventId, array $changes): CalendarEvent;

    public function deleteEvent(User $user, string $eventId): bool;

    /**
     * @return Availability[]
     */
    public function getAvailability(User $user, Carbon $from, Carbon $to): array;
}
