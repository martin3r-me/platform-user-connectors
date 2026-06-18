<?php

namespace Platform\UserConnectors\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Models\UserConnectorInboundEvent;
use Platform\UserConnectors\Services\InboundEventService;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365AppTokenService;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365ConnectorService;

class EnrichMicrosoft365EventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(
        protected int $eventId,
    ) {}

    public function handle(Microsoft365ConnectorService $connectorService): void
    {
        $event = UserConnectorInboundEvent::find($this->eventId);
        if (!$event || !$event->connection_id) {
            return;
        }

        $connection = $event->connection;
        if (!$connection) {
            return;
        }

        $baseUrl = config('user-connectors.microsoft365.graph_base_url', 'https://graph.microsoft.com/v1.0');
        $eventType = $event->event_type;
        $payload = $event->payload ?? [];
        $resourcePath = $payload['resource'] ?? '';
        $resourceId = $payload['resourceData']['id'] ?? $this->extractResourceId($resourcePath);

        // Shared mailbox resources (users/{email}/...) and app-level resources (chats/...)
        // need an app token instead of the user's delegated token
        $isSharedMailbox = str_starts_with($resourcePath, 'users/');
        $isAppLevel = str_starts_with(strtolower($resourcePath), 'chats/');
        $token = null;

        if ($isSharedMailbox || $isAppLevel) {
            $oauthApp = $connection->oauthApp;
            if ($oauthApp && !empty($oauthApp->settings['tenant_id'])) {
                $token = app(Microsoft365AppTokenService::class)->getAppToken($oauthApp);
            }
        }

        if (!$token) {
            $token = $connectorService->getValidAccessToken($connection);
        }

        if (!$token) {
            Log::warning('MS365 Enrichment: Kein gültiger Token', [
                'event_id' => $event->id,
                'connection_id' => $connection->id,
            ]);
            return;
        }

        if (!$resourceId && !str_contains($eventType, 'deleted')) {
            Log::debug('MS365 Enrichment: Keine Resource-ID', ['event_id' => $event->id]);
            return;
        }

        // Get the connection user's email for direction detection
        $userEmail = $connection->credentials['profile']['mail']
            ?? $connection->credentials['profile']['userPrincipalName']
            ?? null;

        // For shared mailboxes, extract the mailbox address from the resource path
        $sharedMailbox = null;
        if ($isSharedMailbox && preg_match('#^users/([^/]+)/#', $resourcePath, $m)) {
            $sharedMailbox = $m[1];
        }

        try {
            $enriched = match (true) {
                str_starts_with($eventType, 'mail.') => $this->enrichMail($baseUrl, $token, $resourcePath, $resourceId, $userEmail, $sharedMailbox),
                str_starts_with($eventType, 'calendar.') && !str_contains($eventType, 'deleted') => $this->enrichCalendar($baseUrl, $token, $resourceId),
                str_starts_with($eventType, 'calendar.') && str_contains($eventType, 'deleted') => $this->enrichCalendarDeleted($resourcePath, $resourceId),
                str_starts_with($eventType, 'teams.') => $this->enrichTeamsChat($baseUrl, $token, $resourcePath, $connection->credentials['ms365_user_id'] ?? null),
                default => null,
            };

            if ($enriched) {
                $update = array_filter([
                    'from_identifier' => $enriched['from'] ?? null,
                    'to_identifier' => $enriched['to'] ?? null,
                    'external_id' => $enriched['external_id'] ?? $event->external_id,
                    'direction' => $enriched['direction'] ?? $event->direction,
                    'event_timestamp' => $enriched['event_timestamp'] ?? $event->event_timestamp,
                ], fn ($v) => $v !== null);

                $update['meta'] = array_merge($event->meta ?? [], $enriched['meta'] ?? []);

                $event->update($update);

                $meta = $enriched['meta'] ?? [];
                Log::info('MS365 Enrichment: Event angereichert', [
                    'event_id' => $event->id,
                    'event_type' => $eventType,
                    'connection_id' => $event->connection_id,
                    'direction' => $enriched['direction'] ?? null,
                    'from' => $enriched['from'] ?? null,
                    'subject' => $meta['subject'] ?? null,
                    'body_preview' => mb_substr($meta['bodyPreview'] ?? $meta['body_preview'] ?? '', 0, 80) ?: null,
                    'shared_mailbox' => $meta['sharedMailbox'] ?? null,
                ]);

                // Fan-out Teams messages to all chat participants, or correlate normally
                if (str_starts_with($eventType, 'teams.') && !empty($enriched['meta']['memberUserIds'])) {
                    $this->fanOutTeamsMessageToUsers($event, $enriched);
                } else {
                    $this->correlateSession($event);
                }
            } else {
                Log::debug('MS365 Enrichment: Keine Daten zurückgegeben', [
                    'event_id' => $event->id,
                    'event_type' => $eventType,
                    'resource' => $resourcePath,
                ]);

                // For Teams events from app-level subscriptions: if enrichment failed,
                // delete the event to avoid empty rows in the log
                if (str_starts_with($eventType, 'teams.') && $isAppLevel) {
                    $event->delete();
                }
            }
        } catch (\Exception $e) {
            Log::error('MS365 Enrichment fehlgeschlagen', [
                'event_id' => $event->id,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);

            // Re-throw for retry
            if ($this->attempts() < $this->tries) {
                throw $e;
            }
        }
    }

    protected function enrichMail(string $baseUrl, string $token, string $resourcePath, ?string $resourceId, ?string $userEmail = null, ?string $sharedMailbox = null): ?array
    {
        // Use the full resource path if available, otherwise construct from ID
        $url = $resourcePath
            ? "{$baseUrl}/{$resourcePath}"
            : "{$baseUrl}/me/messages/{$resourceId}";

        $response = Http::withToken($token)
            ->timeout(15)
            ->get($url, [
                '$select' => 'id,subject,bodyPreview,body,from,toRecipients,ccRecipients,receivedDateTime,isRead,conversationId,hasAttachments,isDraft,sentDateTime',
            ]);

        if (!$response->successful()) {
            Log::warning('MS365 Enrichment: Mail-Fetch fehlgeschlagen', [
                'status' => $response->status(),
                'resource' => $resourcePath,
                'error' => mb_substr($response->body(), 0, 300),
            ]);
            return null;
        }

        $data = $response->json();
        $from = $data['from']['emailAddress']['address'] ?? null;
        $fromName = $data['from']['emailAddress']['name'] ?? null;
        $recipients = collect($data['toRecipients'] ?? [])
            ->pluck('emailAddress.address')
            ->filter()
            ->values()
            ->all();
        $ccRecipients = collect($data['ccRecipients'] ?? [])
            ->pluck('emailAddress.address')
            ->filter()
            ->values()
            ->all();

        // Full body — strip HTML if Graph returned content-type "html" so
        // downstream LLM enrichment gets clean text instead of markup noise.
        $bodyRaw = $data['body']['content'] ?? '';
        $bodyContentType = strtolower((string) ($data['body']['contentType'] ?? 'text'));
        if ($bodyRaw !== '' && $bodyContentType === 'html') {
            // Decode entities first, then strip tags, then collapse whitespace.
            $bodyText = html_entity_decode(strip_tags($bodyRaw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $bodyText = preg_replace("/[ \t]+/", ' ', $bodyText);
            $bodyText = preg_replace("/(\r?\n[ \t]*){3,}/", "\n\n", $bodyText);
            $bodyText = trim($bodyText);
        } else {
            $bodyText = trim($bodyRaw);
        }
        // Guard rail — extreme outliers (newsletters with embedded styles) can
        // blow the row size; full body remains accessible via Graph if needed.
        if (mb_strlen($bodyText) > 200000) {
            $bodyText = mb_substr($bodyText, 0, 200000);
        }

        // Direction detection:
        // - For shared mailboxes: if from matches the shared mailbox address, it's outbound
        // - For personal: if from matches the user's email, it's outbound
        $isDraft = $data['isDraft'] ?? false;
        $matchEmail = $sharedMailbox ?? $userEmail;
        $direction = 'inbound';
        if ($isDraft) {
            $direction = 'outbound';
        } elseif ($matchEmail && $from && str_contains(strtolower($from), strtolower($matchEmail))) {
            $direction = 'outbound';
        }

        // Timestamp: use receivedDateTime for inbound, sentDateTime for outbound
        $timestamp = $data['receivedDateTime'] ?? $data['sentDateTime'] ?? null;

        $meta = [
            'subject' => $data['subject'] ?? null,
            'bodyPreview' => $data['bodyPreview'] ?? null,
            'body' => $bodyText !== '' ? $bodyText : null,
            'bodyContentType' => $bodyContentType,
            'isRead' => $data['isRead'] ?? null,
            'conversationId' => $data['conversationId'] ?? null,
            'recipients' => $recipients,
            'ccRecipients' => $ccRecipients,
            'hasAttachments' => $data['hasAttachments'] ?? false,
            'isDraft' => $isDraft,
            'fromName' => $fromName,
            'fromAddress' => $from,
        ];

        if ($sharedMailbox) {
            $meta['sharedMailbox'] = $sharedMailbox;
        }

        return [
            'from' => $fromName ? "{$fromName} <{$from}>" : $from,
            'to' => implode(', ', $recipients),
            'external_id' => $data['id'] ?? $resourceId,
            'direction' => $direction,
            'event_timestamp' => $timestamp ? \Carbon\Carbon::parse($timestamp) : null,
            'meta' => $meta,
        ];
    }

    protected function enrichCalendar(string $baseUrl, string $token, ?string $resourceId): ?array
    {
        $response = Http::withToken($token)
            ->timeout(15)
            ->get("{$baseUrl}/me/events/{$resourceId}", [
                '$select' => 'id,subject,organizer,attendees,start,end,location,isOnlineMeeting,onlineMeetingUrl,bodyPreview,createdDateTime',
            ]);

        if (!$response->successful()) {
            Log::warning('MS365 Enrichment: Calendar-Fetch fehlgeschlagen', [
                'status' => $response->status(),
                'resourceId' => $resourceId,
                'error' => mb_substr($response->body(), 0, 300),
            ]);
            return null;
        }

        $data = $response->json();
        $organizer = $data['organizer']['emailAddress']['address'] ?? null;
        $organizerName = $data['organizer']['emailAddress']['name'] ?? null;
        $attendees = collect($data['attendees'] ?? [])
            ->pluck('emailAddress.address')
            ->filter()
            ->values()
            ->all();

        // Graph returns dateTime without offset + a separate timeZone (Windows name)
        $startRaw = $data['start']['dateTime'] ?? null;
        $startTz = $data['start']['timeZone'] ?? null;
        $endRaw = $data['end']['dateTime'] ?? null;
        $endTz = $data['end']['timeZone'] ?? null;
        $location = $data['location']['displayName'] ?? null;

        $start = $this->parseGraphDateTime($startRaw, $startTz);
        $end = $this->parseGraphDateTime($endRaw, $endTz);

        // Store UTC ISO strings in meta for downstream consumers
        $startUtc = $start?->toIso8601String();
        $endUtc = $end?->toIso8601String();

        return [
            'from' => $organizerName ? "{$organizerName} <{$organizer}>" : $organizer,
            'to' => implode(', ', $attendees),
            'external_id' => $data['id'] ?? $resourceId,
            'event_timestamp' => $start,
            'meta' => [
                'subject' => $data['subject'] ?? null,
                'bodyPreview' => $data['bodyPreview'] ?? null,
                'start' => $startUtc,
                'end' => $endUtc,
                'startTimeZone' => $startTz,
                'endTimeZone' => $endTz,
                'location' => $location,
                'isOnlineMeeting' => $data['isOnlineMeeting'] ?? false,
                'onlineMeetingUrl' => $data['onlineMeetingUrl'] ?? null,
                'attendees' => $attendees,
                'organizer' => $organizer,
                'organizerName' => $organizerName,
            ],
        ];
    }

    protected function enrichCalendarDeleted(string $resourcePath, ?string $resourceId): array
    {
        return [
            'external_id' => $resourceId,
            'meta' => [
                'deleted' => true,
                'resource' => $resourcePath,
            ],
        ];
    }

    protected function enrichTeamsChat(string $baseUrl, string $token, string $resourcePath, ?string $ms365UserId = null): ?array
    {
        // Resource path for chat messages: chats('{chatId}')/messages('{messageId}')
        // or chats/{chatId}/messages/{messageId}
        $url = "{$baseUrl}/{$resourcePath}";

        $response = Http::withToken($token)
            ->timeout(15)
            ->get($url);

        if (!$response->successful()) {
            Log::warning('MS365 Enrichment: Teams-Chat-Fetch fehlgeschlagen', [
                'status' => $response->status(),
                'resource' => $resourcePath,
                'error' => mb_substr($response->body(), 0, 300),
            ]);
            return null;
        }

        $data = $response->json();
        $from = $data['from']['user']['displayName']
            ?? $data['from']['application']['displayName']
            ?? null;
        $fromUserId = $data['from']['user']['id'] ?? null;
        $chatId = $data['chatId'] ?? null;

        // Extract body preview (strip HTML if content type is html)
        $body = $data['body']['content'] ?? '';
        $bodyType = $data['body']['contentType'] ?? 'text';
        if ($bodyType === 'html') {
            $body = strip_tags($body);
        }
        $bodyPreview = mb_substr(trim($body), 0, 500);

        $timestamp = $data['createdDateTime'] ?? null;

        // Direction: if sender matches the connection user, it's outbound
        $direction = ($ms365UserId && $fromUserId === $ms365UserId) ? 'outbound' : 'inbound';

        // Fetch chat members for "An" field and fan-out
        $members = [];
        $chatTopic = null;
        $chatType = null;
        if ($chatId) {
            $membersUrl = "{$baseUrl}/chats/{$chatId}/members";
            $membersResponse = Http::withToken($token)->timeout(15)->get($membersUrl);
            $members = $membersResponse->successful() ? $membersResponse->json('value', []) : [];

            // Chat-Metadaten holen — Graph kapselt topic + chatType separat
            // unter /chats/{id}. Ohne diese können wir kein stabiles Display
            // (DM-Partner vs. Gruppen-Topic) berechnen, weil das Message-
            // Payload selbst diese Felder nicht trägt.
            $chatUrl = "{$baseUrl}/chats/{$chatId}";
            $chatResponse = Http::withToken($token)->timeout(15)->get($chatUrl, [
                '$select' => 'id,topic,chatType',
            ]);
            if ($chatResponse->successful()) {
                $chatData = $chatResponse->json();
                $chatTopic = $chatData['topic'] ?? null;
                $chatType = $chatData['chatType'] ?? null;
            }
        }

        // "An" = all member display names except the sender
        $toNames = collect($members)
            ->filter(fn ($m) => ($m['userId'] ?? null) !== $fromUserId)
            ->map(fn ($m) => $m['displayName'] ?? $m['email'] ?? null)
            ->filter()->values()->implode(', ');

        // Member user IDs for fan-out
        $memberUserIds = collect($members)
            ->pluck('userId')
            ->filter()->values()->all();

        $memberNames = collect($members)
            ->map(fn ($m) => ['userId' => $m['userId'] ?? null, 'displayName' => $m['displayName'] ?? null])
            ->filter(fn ($m) => $m['userId'])
            ->values()->all();

        // Chat-Display berechnen: stabil pro Chat (im Gegensatz zu fromDisplay
        // das pro Message wechselt). InboundEventService persistiert es als
        // chat_display_name, damit die Inbox nicht den letzten Sender zeigt.
        //   - oneOnOne: der EINE andere Teilnehmer
        //   - group:    topic (falls gesetzt) sonst joined memberNames ohne self
        //   - meeting:  topic falls vorhanden, sonst "Meeting-Chat"
        $chatDisplayName = $this->computeChatDisplayName(
            chatType: $chatType,
            chatTopic: $chatTopic,
            members: $memberNames,
            selfUserId: $ms365UserId,
        );

        return [
            'from' => $from,
            'to' => $toNames ?: null,
            'external_id' => $data['id'] ?? null,
            'direction' => $direction,
            'event_timestamp' => $timestamp ? \Carbon\Carbon::parse($timestamp) : null,
            'meta' => [
                'bodyPreview' => $bodyPreview,
                'chatId' => $chatId,
                'chatTopic' => $chatTopic,
                'chatType' => $chatType,
                'chatDisplayName' => $chatDisplayName,
                'messageType' => $data['messageType'] ?? null,
                'importance' => $data['importance'] ?? null,
                'fromUserId' => $fromUserId,
                'fromDisplayName' => $from,
                'toNames' => $toNames ?: null,
                'memberUserIds' => $memberUserIds,
                'memberNames' => $memberNames,
            ],
        ];
    }

    /**
     * Stable per-chat display string. Falls back through chat-type-specific
     * heuristics so the Inbox label remains useful even when topic is empty
     * or chatType is missing (older subscriptions don't always return it).
     *
     * @param  array<int, array{userId: ?string, displayName: ?string}>  $members
     */
    protected function computeChatDisplayName(
        ?string $chatType,
        ?string $chatTopic,
        array $members,
        ?string $selfUserId,
    ): ?string {
        $topic = is_string($chatTopic) ? trim($chatTopic) : '';

        if ($chatType === 'oneOnOne') {
            foreach ($members as $m) {
                if (!empty($m['userId']) && $m['userId'] !== $selfUserId && !empty($m['displayName'])) {
                    return $m['displayName'];
                }
            }
            return $topic !== '' ? $topic : null;
        }

        if ($chatType === 'meeting') {
            return $topic !== '' ? $topic : 'Meeting-Chat';
        }

        // group, OR unknown chatType — prefer topic, then a joined member
        // listing without self for context. Capped so a 20-people chat
        // doesn't blow the Inbox row.
        if ($topic !== '') {
            return $topic;
        }
        $others = [];
        foreach ($members as $m) {
            if (!empty($m['userId']) && $m['userId'] !== $selfUserId && !empty($m['displayName'])) {
                $others[] = $m['displayName'];
            }
        }
        if (empty($others)) {
            return null;
        }
        $joined = implode(', ', array_slice($others, 0, 3));
        if (count($others) > 3) {
            $joined .= ' +' . (count($others) - 3);
        }
        return 'Gruppe: ' . $joined;
    }

    /**
     * Parse a Graph API dateTime + timeZone into a UTC Carbon instance.
     *
     * Graph returns calendar times as "2026-06-03T14:30:00.0000000" (no offset)
     * plus a Windows timezone like "W. Europe Standard Time". Without conversion
     * these get interpreted as UTC, causing 1-2h offsets for European users.
     */
    protected function parseGraphDateTime(?string $dateTime, ?string $windowsTimeZone): ?\Carbon\Carbon
    {
        if (!$dateTime) {
            return null;
        }

        try {
            $iana = $windowsTimeZone ? $this->windowsToIanaTimezone($windowsTimeZone) : null;

            if ($iana) {
                return \Carbon\Carbon::parse($dateTime, $iana)->utc();
            }

            return \Carbon\Carbon::parse($dateTime);
        } catch (\Exception $e) {
            Log::warning('MS365 Enrichment: DateTime-Parsing fehlgeschlagen', [
                'dateTime' => $dateTime,
                'timeZone' => $windowsTimeZone,
                'error' => $e->getMessage(),
            ]);
            return \Carbon\Carbon::parse($dateTime);
        }
    }

    /**
     * Convert a Windows timezone name to an IANA timezone identifier.
     * Uses IntlTimeZone when available, otherwise falls back to a mapping table.
     */
    protected function windowsToIanaTimezone(string $windowsTimezone): ?string
    {
        // Try PHP intl extension first (most accurate)
        if (class_exists(\IntlTimeZone::class)) {
            $iana = \IntlTimeZone::getIDForWindowsID($windowsTimezone);
            if ($iana) {
                return $iana;
            }
        }

        // Already an IANA name or "UTC"
        if ($windowsTimezone === 'UTC' || str_contains($windowsTimezone, '/')) {
            return $windowsTimezone;
        }

        // Fallback mapping for common Windows timezone names
        $map = [
            'W. Europe Standard Time' => 'Europe/Berlin',
            'Central European Standard Time' => 'Europe/Warsaw',
            'Central Europe Standard Time' => 'Europe/Budapest',
            'Romance Standard Time' => 'Europe/Paris',
            'GMT Standard Time' => 'Europe/London',
            'Greenwich Standard Time' => 'Atlantic/Reykjavik',
            'E. Europe Standard Time' => 'Europe/Chisinau',
            'FLE Standard Time' => 'Europe/Kiev',
            'GTB Standard Time' => 'Europe/Bucharest',
            'Russian Standard Time' => 'Europe/Moscow',
            'Eastern Standard Time' => 'America/New_York',
            'Central Standard Time' => 'America/Chicago',
            'Mountain Standard Time' => 'America/Denver',
            'Pacific Standard Time' => 'America/Los_Angeles',
            'China Standard Time' => 'Asia/Shanghai',
            'Tokyo Standard Time' => 'Asia/Tokyo',
            'AUS Eastern Standard Time' => 'Australia/Sydney',
            'India Standard Time' => 'Asia/Kolkata',
            'Arab Standard Time' => 'Asia/Riyadh',
            'Turkey Standard Time' => 'Europe/Istanbul',
        ];

        return $map[$windowsTimezone] ?? null;
    }

    /**
     * Extract resource ID from Graph resource path.
     * e.g. "me/messages/AAMk..." → "AAMk..."
     * e.g. "me/events/AAMk..." → "AAMk..."
     */
    protected function extractResourceId(string $resourcePath): ?string
    {
        if (!$resourcePath) {
            return null;
        }

        // Match patterns like messages/ID or events/ID or messages('ID')
        if (preg_match("/(?:messages|events|callRecords)[\/\(]'?([^'\)\/]+)'?\)?$/", $resourcePath, $matches)) {
            return $matches[1];
        }

        // Fallback: last segment
        $segments = explode('/', rtrim($resourcePath, '/'));
        $last = end($segments);

        return $last ?: null;
    }

    /**
     * Fan out a Teams message to all chat participants that have an active MS365 connection.
     * Creates a separate InboundEvent per connection so each user sees the message in their log.
     */
    protected function fanOutTeamsMessageToUsers(
        UserConnectorInboundEvent $originalEvent,
        array $enriched,
    ): void {
        $memberUserIds = $enriched['meta']['memberUserIds'] ?? [];
        if (empty($memberUserIds)) {
            $this->correlateSession($originalEvent);

            return;
        }

        $fromUserId = $enriched['meta']['fromUserId'] ?? null;

        // Find all active MS365 connections whose ms365_user_id is in the member list
        $connections = UserConnectorConnection::query()
            ->whereHas('connector', fn ($q) => $q->where('key', 'microsoft365'))
            ->where('status', 'active')
            ->get()
            ->filter(fn ($conn) => in_array(
                $conn->credentials['ms365_user_id'] ?? null,
                $memberUserIds,
                true,
            ));

        if ($connections->isEmpty()) {
            // No active connection is involved in this chat — delete the event
            // to keep logs clean (app-level subscription sees all tenant messages)
            Log::debug('MS365 Fan-Out: Keine Connection beteiligt, Event wird gelöscht', [
                'event_id' => $originalEvent->id,
                'memberUserIds' => $memberUserIds,
            ]);
            $originalEvent->delete();

            return;
        }

        $service = app(InboundEventService::class);

        // Check if the original event's connection is actually involved in this chat
        $originalConnectionInvolved = $connections->contains('id', $originalEvent->connection_id);

        foreach ($connections as $connection) {
            $isOriginalConnection = $connection->id === $originalEvent->connection_id;
            $connMs365UserId = $connection->credentials['ms365_user_id'] ?? null;
            $direction = ($fromUserId && $connMs365UserId === $fromUserId) ? 'outbound' : 'inbound';

            if ($isOriginalConnection) {
                // Original event: correct direction + correlate session
                $originalEvent->update(['direction' => $direction]);
                $service->updateMessageSession($originalEvent);
            } else {
                // Create a new event for this connection
                $fanOutEvent = UserConnectorInboundEvent::create([
                    'connection_id' => $connection->id,
                    'connector_key' => 'microsoft365',
                    'event_type' => $originalEvent->event_type,
                    'direction' => $direction,
                    'external_id' => $originalEvent->external_id,
                    'idempotency_key' => $originalEvent->idempotency_key . ':fan:' . $connection->id,
                    'from_identifier' => $originalEvent->from_identifier,
                    'to_identifier' => $originalEvent->to_identifier,
                    'payload' => $originalEvent->payload,
                    'processing_status' => 'processed',
                    'event_timestamp' => $originalEvent->event_timestamp,
                    'meta' => $originalEvent->meta,
                ]);
                $service->updateMessageSession($fanOutEvent);
            }
        }

        // If the original connection wasn't involved in the chat, delete the orphaned event
        // (fan-out already created dedicated events for the actual participants)
        if (!$originalConnectionInvolved) {
            Log::debug('MS365 Fan-Out: Original-Connection nicht im Chat, Event wird gelöscht', [
                'event_id' => $originalEvent->id,
                'connection_id' => $originalEvent->connection_id,
            ]);
            $originalEvent->delete();
        }
    }

    /**
     * After enrichment, correlate the event into the appropriate session table.
     * On success the event is marked as session_correlated_at=now; on failure
     * the throwable's message is stored in processing_error so the bug is
     * visible in the DB/UI without depending on log retention.
     */
    protected function correlateSession(UserConnectorInboundEvent $event): void
    {
        try {
            $service = app(InboundEventService::class);
            $eventType = $event->event_type;

            $handled = true;
            if (str_starts_with($eventType, 'mail.')) {
                $service->updateMailSession($event);
            } elseif (str_starts_with($eventType, 'calendar.')) {
                $service->updateMeetingSession($event);
            } elseif (str_starts_with($eventType, 'teams.')) {
                $service->updateMessageSession($event);
            } else {
                $handled = false;
            }

            if ($handled) {
                $event->markSessionCorrelated();
            }
        } catch (\Throwable $e) {
            Log::warning('MS365 Enrichment: Session-Korrelation fehlgeschlagen', [
                'event_id' => $event->id,
                'event_type' => $event->event_type,
                'error' => $e->getMessage(),
                'trace_top' => array_slice(explode("\n", $e->getTraceAsString()), 0, 5),
            ]);
            $event->markCorrelationFailed(get_class($e) . ': ' . $e->getMessage());
        }
    }
}
