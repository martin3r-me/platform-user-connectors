<?php

namespace Platform\UserConnectors\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Platform\UserConnectors\Events\InboundCallEvent;
use Platform\UserConnectors\Events\InboundEventReceived;
use Platform\UserConnectors\Events\InboundMessageEvent;
use Platform\UserConnectors\Jobs\FetchCallRecordingJob;
use Platform\UserConnectors\Models\UserConnectorCallSession;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Models\UserConnectorInboundEvent;
use Platform\UserConnectors\Models\UserConnectorMailSession;
use Platform\UserConnectors\Models\UserConnectorMeetingSession;
use Platform\UserConnectors\Models\UserConnectorMessageSession;

class InboundEventService
{
    /**
     * Ingest a raw webhook payload, deduplicate, persist, and dispatch events.
     *
     * @return UserConnectorInboundEvent|null  null if duplicate
     */
    public function ingest(
        string $connectorKey,
        string $eventType,
        array $payload,
        ?string $idempotencyKey = null,
        ?int $connectionId = null,
    ): ?UserConnectorInboundEvent {
        // Idempotency check
        if ($idempotencyKey) {
            $cacheKey = "uc_inbound:{$idempotencyKey}";

            if (Cache::has($cacheKey)) {
                Log::debug('UserConnectors: Duplicate inbound event skipped', [
                    'connector_key' => $connectorKey,
                    'idempotency_key' => $idempotencyKey,
                ]);
                return null;
            }

            // Check DB as well
            $existing = UserConnectorInboundEvent::where('idempotency_key', $idempotencyKey)->exists();
            if ($existing) {
                Cache::put($cacheKey, true, 86400);
                return null;
            }

            Cache::put($cacheKey, true, 86400);
        }

        // Extract common fields from payload
        $direction = $this->extractDirection($payload);
        $fromId = $this->extractFrom($payload);
        $toId = $this->extractTo($payload);
        $externalId = $this->extractExternalId($eventType, $payload);
        $eventTimestamp = $this->extractTimestamp($payload);

        $event = UserConnectorInboundEvent::create([
            'connection_id' => $connectionId,
            'connector_key' => $connectorKey,
            'event_type' => $eventType,
            'direction' => $direction,
            'external_id' => $externalId,
            'idempotency_key' => $idempotencyKey,
            'from_identifier' => $fromId,
            'to_identifier' => $toId,
            'payload' => $payload,
            'processing_status' => 'processing',
            'event_timestamp' => $eventTimestamp,
        ]);

        // Dispatch generic event
        InboundEventReceived::dispatch($event);

        // Dispatch typed events
        $this->dispatchTypedEvent($event, $eventType);

        // Correlate call events into sessions (immediate — payload has all data)
        if (str_starts_with($eventType, 'call.')) {
            $this->updateCallSession($event);
        }

        // Mail/Calendar/Teams sessions are correlated AFTER enrichment
        // (see EnrichMicrosoft365EventJob) because the raw Graph notification
        // only contains subscriptionId + resource path, not the actual data.

        $event->markProcessed();

        return $event;
    }

    /**
     * Correlate call webhook events into a single CallSession.
     */
    protected function updateCallSession(UserConnectorInboundEvent $event): void
    {
        if (!$event->external_id) {
            return;
        }

        $this->withSessionLock("uc_call:{$event->external_id}", fn () => $this->doUpdateCallSession($event));
    }

    protected function doUpdateCallSession(UserConnectorInboundEvent $event): void
    {
        $session = UserConnectorCallSession::where('external_call_id', $event->external_id)->first();

        $eventType = $event->event_type;
        $payload = $event->payload ?? [];
        $timestamp = $event->event_timestamp ?? $event->created_at;

        if (!$session && $eventType === 'call.new') {
            UserConnectorCallSession::create([
                'connection_id' => $event->connection_id,
                'connector_key' => $event->connector_key,
                'external_call_id' => $event->external_id,
                'direction' => $event->direction,
                'status' => 'ringing',
                'from_number' => $event->from_identifier,
                'to_number' => $event->to_identifier,
                'started_at' => $timestamp,
            ]);
            return;
        }

        if (!$session) {
            // Late event without a session (e.g. missed the newCall) — create one retroactively
            $session = UserConnectorCallSession::create([
                'connection_id' => $event->connection_id,
                'connector_key' => $event->connector_key,
                'external_call_id' => $event->external_id,
                'direction' => $event->direction,
                'status' => 'ringing',
                'from_number' => $event->from_identifier,
                'to_number' => $event->to_identifier,
                'started_at' => $timestamp,
            ]);
        }

        if ($eventType === 'call.answered') {
            $session->update([
                'status' => 'active',
                'answered_at' => $timestamp,
                'answering_number' => $payload['answeringNumber'] ?? null,
            ]);
            return;
        }

        if ($eventType === 'call.voicemail') {
            $session->update([
                'status' => 'voicemail',
            ]);
            return;
        }

        if ($eventType === 'call.hangup') {
            $cause = $payload['cause'] ?? null;

            $status = match (true) {
                $session->status === 'voicemail' => 'voicemail',
                $session->answered_at !== null => 'completed',
                $cause === 'busy' => 'busy',
                $cause === 'cancel' => 'cancelled',
                default => 'missed',
            };

            $duration = null;
            if ($status === 'completed' && $session->answered_at) {
                $duration = $session->answered_at->diffInSeconds($timestamp);
            }

            $session->update([
                'status' => $status,
                'ended_at' => $timestamp,
                'duration_seconds' => $duration,
                'hangup_cause' => $cause,
            ]);

            if ($status === 'completed') {
                FetchCallRecordingJob::dispatch($session->id)->delay(now()->addSeconds(15));
            }
        }
    }

    /**
     * Correlate mail webhook events into a MailSession.
     */
    public function updateMailSession(UserConnectorInboundEvent $event): void
    {
        if (!$event->external_id) {
            return;
        }

        $this->withSessionLock("uc_mail:{$event->external_id}", fn () => $this->doUpdateMailSession($event));
    }

    protected function doUpdateMailSession(UserConnectorInboundEvent $event): void
    {
        // After enrichment, data lives in $event->meta (merged by EnrichMicrosoft365EventJob)
        $meta = $event->meta ?? [];
        $eventType = $event->event_type;

        if ($eventType === 'mail.deleted') {
            UserConnectorMailSession::where('external_mail_id', $event->external_id)->delete();
            return;
        }

        $isRead = (bool) ($meta['isRead'] ?? false);

        $data = [
            'connection_id' => $event->connection_id,
            'connector_key' => $event->connector_key,
            'direction' => $event->direction ?? ($meta['direction'] ?? null),
            'status' => $isRead ? 'read' : 'new',
            'from_address' => $meta['fromAddress'] ?? $event->from_identifier,
            'from_name' => $meta['fromName'] ?? null,
            'to_addresses' => $this->extractAddressList($meta['recipients'] ?? $meta['toRecipients'] ?? null),
            'cc_addresses' => $this->extractAddressList($meta['ccRecipients'] ?? null),
            'subject' => $meta['subject'] ?? null,
            'body_preview' => $meta['bodyPreview'] ?? null,
            'conversation_id' => $meta['conversationId'] ?? null,
            'is_read' => $isRead,
            'has_attachments' => (bool) ($meta['hasAttachments'] ?? false),
            'is_draft' => (bool) ($meta['isDraft'] ?? false),
            'shared_mailbox' => $meta['sharedMailbox'] ?? null,
            'received_at' => $event->event_timestamp,
            'sent_at' => isset($meta['sentDateTime']) ? \Carbon\Carbon::parse($meta['sentDateTime']) : null,
            'meta' => $meta,
        ];

        UserConnectorMailSession::updateOrCreate(
            ['external_mail_id' => $event->external_id],
            $data,
        );
    }

    /**
     * Correlate calendar webhook events into a MeetingSession.
     */
    public function updateMeetingSession(UserConnectorInboundEvent $event): void
    {
        if (!$event->external_id) {
            return;
        }

        $this->withSessionLock("uc_meeting:{$event->external_id}", fn () => $this->doUpdateMeetingSession($event));
    }

    protected function doUpdateMeetingSession(UserConnectorInboundEvent $event): void
    {
        // After enrichment, data lives in $event->meta
        $meta = $event->meta ?? [];
        $eventType = $event->event_type;

        if ($eventType === 'calendar.deleted') {
            UserConnectorMeetingSession::where('external_event_id', $event->external_id)
                ->update(['status' => 'deleted']);
            return;
        }

        $startAt = isset($meta['start']) ? \Carbon\Carbon::parse($meta['start']) : null;
        $endAt = isset($meta['end']) ? \Carbon\Carbon::parse($meta['end']) : null;

        $durationMinutes = null;
        if ($startAt && $endAt) {
            $durationMinutes = (int) $startAt->diffInMinutes($endAt);
        }

        // Determine direction: outbound if user is organizer, inbound if attendee
        $organizerAddress = $meta['organizer'] ?? null;
        $direction = $event->direction;
        if (!$direction && $organizerAddress && $event->connection_id) {
            $connection = UserConnectorConnection::find($event->connection_id);
            if ($connection) {
                $userEmail = $connection->credentials['profile']['mail'] ?? $connection->credentials['profile']['userPrincipalName'] ?? null;
                if ($userEmail && strcasecmp($organizerAddress, $userEmail) === 0) {
                    $direction = 'outbound';
                } else {
                    $direction = 'inbound';
                }
            }
        }

        // Determine status from time
        $status = $meta['status'] ?? null;
        if ($status === 'cancelled') {
            $status = 'cancelled';
        } elseif ($startAt && $endAt) {
            $now = now();
            if ($now->lt($startAt)) {
                $status = 'upcoming';
            } elseif ($now->between($startAt, $endAt)) {
                $status = 'in_progress';
            } else {
                $status = 'completed';
            }
        } else {
            $status = 'upcoming';
        }

        $attendees = $this->extractAddressList($meta['attendees'] ?? null);

        $data = [
            'connection_id' => $event->connection_id,
            'connector_key' => $event->connector_key,
            'direction' => $direction,
            'status' => $status,
            'organizer_address' => $organizerAddress,
            'organizer_name' => $meta['organizerName'] ?? null,
            'attendee_addresses' => $attendees,
            'subject' => $meta['subject'] ?? null,
            'body_preview' => $meta['bodyPreview'] ?? null,
            'location' => $meta['location'] ?? null,
            'is_online_meeting' => (bool) ($meta['isOnlineMeeting'] ?? false),
            'online_meeting_url' => $meta['onlineMeetingUrl'] ?? null,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'duration_minutes' => $durationMinutes,
            'meta' => $meta,
        ];

        UserConnectorMeetingSession::updateOrCreate(
            ['external_event_id' => $event->external_id],
            $data,
        );
    }

    /**
     * Correlate message webhook events (Teams Chat / SMS) into a MessageSession.
     */
    public function updateMessageSession(UserConnectorInboundEvent $event): void
    {
        if (!$event->external_id) {
            return;
        }

        $this->withSessionLock(
            "uc_message:{$event->connection_id}:{$event->external_id}",
            fn () => $this->doUpdateMessageSession($event),
        );
    }

    protected function doUpdateMessageSession(UserConnectorInboundEvent $event): void
    {
        // Messages are immutable — skip if already exists for this connection
        if (UserConnectorMessageSession::where('external_message_id', $event->external_id)
            ->where('connection_id', $event->connection_id)
            ->exists()
        ) {
            return;
        }

        // After enrichment, data lives in $event->meta
        $meta = $event->meta ?? [];
        $eventType = $event->event_type;

        $messageType = str_starts_with($eventType, 'teams.') ? 'teams_chat' : 'sms';

        UserConnectorMessageSession::create([
            'connection_id' => $event->connection_id,
            'connector_key' => $event->connector_key,
            'external_message_id' => $event->external_id,
            'message_type' => $messageType,
            'direction' => $event->direction,
            'from_identifier' => $meta['fromDisplayName'] ?? $event->from_identifier,
            'from_user_id' => $meta['fromUserId'] ?? null,
            'to_identifier' => $meta['toNames'] ?? $event->to_identifier,
            'body_preview' => $meta['bodyPreview'] ?? null,
            'chat_id' => $meta['chatId'] ?? null,
            'importance' => $meta['importance'] ?? null,
            'sent_at' => $event->event_timestamp,
            'meta' => $meta,
        ]);
    }

    /**
     * Serialize concurrent correlation attempts for the same external_id so
     * that 5 webhook events for one mail can't race each other on the
     * UNIQUE constraint of external_mail_id. Lock waits up to 5s; if it
     * can't acquire, falls back to running without the lock (better to risk
     * a race than to drop the event entirely).
     */
    protected function withSessionLock(string $key, \Closure $callback): void
    {
        try {
            Cache::lock($key, 10)->block(5, $callback);
        } catch (\Throwable $e) {
            Log::warning('UserConnectors: session lock failed, running without lock', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            $callback();
        }
    }

    /**
     * Extract a comma-separated address list from Graph API recipients array or string.
     */
    protected function extractAddressList(mixed $recipients): ?string
    {
        if ($recipients === null) {
            return null;
        }

        if (is_string($recipients)) {
            return $recipients;
        }

        if (is_array($recipients)) {
            $addresses = [];
            foreach ($recipients as $recipient) {
                if (is_string($recipient)) {
                    $addresses[] = $recipient;
                } elseif (isset($recipient['emailAddress']['address'])) {
                    $addresses[] = $recipient['emailAddress']['address'];
                } elseif (isset($recipient['address'])) {
                    $addresses[] = $recipient['address'];
                }
            }
            return !empty($addresses) ? implode(', ', $addresses) : null;
        }

        return null;
    }

    /**
     * Resolve connection from Sipgate user/account info in webhook payload.
     */
    public function resolveConnectionFromSipgate(array $payload): ?UserConnectorConnection
    {
        // Sipgate sends userId[] and fullUserId[] as arrays
        $userId = $payload['userId'] ?? $payload['fullUserId'] ?? null;
        if (is_array($userId)) {
            $userId = $userId[0] ?? null;
        }
        $fullUserId = $payload['fullUserId'] ?? null;
        if (is_array($fullUserId)) {
            $fullUserId = $fullUserId[0] ?? null;
        }

        if (!$userId && !$fullUserId) {
            return null;
        }

        // Search connections by sipgate_sub in credentials
        return UserConnectorConnection::query()
            ->whereHas('connector', fn ($q) => $q->where('key', 'sipgate'))
            ->where('status', 'active')
            ->get()
            ->first(function (UserConnectorConnection $conn) use ($userId, $fullUserId) {
                $sub = $conn->credentials['oauth']['sipgate_sub'] ?? null;
                if (!$sub) {
                    return false;
                }
                return $sub === $userId || $sub === $fullUserId;
            });
    }

    /**
     * Resolve connection from MS365 notification via subscriptionId + clientState validation.
     */
    public function resolveConnectionFromMicrosoft365(array $notification): ?UserConnectorConnection
    {
        $subscriptionId = $notification['subscriptionId'] ?? null;
        if (!$subscriptionId) {
            return null;
        }

        $manager = app(WebhookSubscriptionManager::class);
        $connection = $manager->resolveConnectionBySubscriptionId('microsoft365', $subscriptionId);

        if (!$connection) {
            return null;
        }

        // Validate clientState
        $clientState = $notification['clientState'] ?? '';
        $subscriptions = $connection->credentials['subscriptions'] ?? [];
        foreach ($subscriptions as $sub) {
            if (($sub['id'] ?? '') === $subscriptionId) {
                $expectedState = $sub['client_state'] ?? '';
                if ($expectedState && !hash_equals($expectedState, $clientState)) {
                    Log::warning('MS365 Webhook: clientState mismatch', [
                        'connection_id' => $connection->id,
                        'subscription_id' => $subscriptionId,
                    ]);
                    return null;
                }
                break;
            }
        }

        return $connection;
    }

    /**
     * Resolve connection from RingCentral/Vodafone webhook payload via subscriptionId.
     */
    public function resolveConnectionFromRingCentral(array $payload, string $connectorKey = 'ringcentral'): ?UserConnectorConnection
    {
        $subscriptionId = $payload['subscriptionId'] ?? null;
        if (!$subscriptionId) {
            return null;
        }

        $manager = app(WebhookSubscriptionManager::class);

        return $manager->resolveConnectionBySubscriptionId($connectorKey, $subscriptionId);
    }

    protected function dispatchTypedEvent(UserConnectorInboundEvent $event, string $eventType): void
    {
        // Call events
        if (str_starts_with($eventType, 'call.')) {
            $status = match ($eventType) {
                'call.new' => 'ringing',
                'call.answered' => 'answered',
                'call.hangup' => 'hangup',
                default => $eventType,
            };
            InboundCallEvent::dispatch($event, $status);
            return;
        }

        // Message events
        if (str_starts_with($eventType, 'sms.') || str_starts_with($eventType, 'mail.') || str_starts_with($eventType, 'teams.')) {
            $type = explode('.', $eventType)[0];
            InboundMessageEvent::dispatch($event, $type);
            return;
        }
    }

    protected function extractDirection(array $payload): ?string
    {
        $dir = $payload['direction'] ?? null;
        if (!$dir) {
            return null;
        }

        return match (strtolower($dir)) {
            'in', 'inbound', 'incoming' => 'inbound',
            'out', 'outbound', 'outgoing' => 'outbound',
            default => strtolower($dir),
        };
    }

    protected function extractFrom(array $payload): ?string
    {
        return $payload['from'] ?? $payload['caller'] ?? $payload['from_identifier'] ?? null;
    }

    protected function extractTo(array $payload): ?string
    {
        return $payload['to'] ?? $payload['callee'] ?? $payload['to_identifier'] ?? null;
    }

    protected function extractExternalId(string $eventType, array $payload): ?string
    {
        return $payload['callId'] ?? $payload['id'] ?? $payload['messageId'] ?? null;
    }

    protected function extractTimestamp(array $payload): ?\Carbon\Carbon
    {
        $ts = $payload['timestamp'] ?? $payload['createdDateTime'] ?? null;
        if (!$ts) {
            return now();
        }

        try {
            return \Carbon\Carbon::parse($ts);
        } catch (\Exception) {
            return now();
        }
    }
}
