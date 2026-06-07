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

    /**
     * RSVP to a meeting invite. $response is one of:
     *   'accept' | 'decline' | 'tentative' (provider-agnostic aliases).
     * Each implementation maps to its provider's specific verbs.
     */
    public function respondToEvent(User $user, string $eventId, string $response, ?string $comment = null, bool $sendResponse = true): bool;

    /**
     * Find common free time slots across multiple attendees in the given
     * window. Provider-agnostic shape — each implementation calls its own
     * availability API (Graph findMeetingTimes, Google freeBusy, etc.) and
     * normalises the result.
     *
     * Return shape:
     *   [
     *     'suggestions' => [
     *       [
     *         'start' => Carbon,
     *         'end'   => Carbon,
     *         'confidence' => int (0-100),
     *         'reason' => string|null,
     *         'attendee_availability' => [
     *            ['email' => string, 'availability' => 'free'|'busy'|'tentative'|'unknown'],
     *            ...
     *         ],
     *       ],
     *       ...
     *     ],
     *   ]
     *
     * @param array<int, string> $participants  email addresses of the additional attendees
     */
    public function findMeetingTimes(
        User $user,
        array $participants,
        Carbon $after,
        Carbon $before,
        int $durationMinutes = 30,
        int $maxCandidates = 5,
    ): array;
}
