<?php

namespace Platform\UserConnectors\Contracts;

use Platform\Core\Models\User;
use Platform\UserConnectors\DTOs\CallLogEntry;
use Platform\UserConnectors\DTOs\Pagination;

interface CallConnector
{
    /**
     * @return array{calls: CallLogEntry[], pagination: Pagination}
     */
    public function getCallLog(User $user, array $filters = [], ?Pagination $pagination = null): array;

    /**
     * @return array{voicemails: array, pagination: Pagination}
     */
    public function getVoicemails(User $user, ?Pagination $pagination = null): array;

    /**
     * Send an SMS message.
     *
     * @return array{id: string, status: string}
     */
    public function sendSMS(User $user, string $to, string $body): array;

    /**
     * Initiate a call (RingOut) — rings the caller's device first, then connects to the target.
     *
     * @param string $from  Caller's phone number or device
     * @param string $to    Target phone number
     * @return array{sessionId: string, status: string}
     */
    public function initiateCall(User $user, string $from, string $to): array;
}
