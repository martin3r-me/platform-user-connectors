<?php

namespace Platform\UserConnectors\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Platform\UserConnectors\Models\UserConnectorInboundEvent;
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

        // Shared mailbox resources (users/{email}/...) need app token
        $isSharedMailbox = str_starts_with($resourcePath, 'users/');
        $token = null;

        if ($isSharedMailbox) {
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

                Log::info('MS365 Enrichment: Event angereichert', [
                    'event_id' => $event->id,
                    'event_type' => $eventType,
                ]);
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
                '$select' => 'id,subject,bodyPreview,from,toRecipients,ccRecipients,receivedDateTime,isRead,conversationId,hasAttachments,isDraft,sentDateTime',
            ]);

        if (!$response->successful()) {
            Log::warning('MS365 Enrichment: Mail-Fetch fehlgeschlagen', [
                'status' => $response->status(),
                'resource' => $resourcePath,
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

        $start = $data['start']['dateTime'] ?? null;
        $end = $data['end']['dateTime'] ?? null;
        $location = $data['location']['displayName'] ?? null;

        return [
            'from' => $organizerName ? "{$organizerName} <{$organizer}>" : $organizer,
            'to' => implode(', ', $attendees),
            'external_id' => $data['id'] ?? $resourceId,
            'event_timestamp' => $start ? \Carbon\Carbon::parse($start) : null,
            'meta' => [
                'subject' => $data['subject'] ?? null,
                'bodyPreview' => $data['bodyPreview'] ?? null,
                'start' => $start,
                'end' => $end,
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

        return [
            'from' => $from,
            'to' => null, // Chat messages don't have a single "to"
            'external_id' => $data['id'] ?? null,
            'direction' => $direction,
            'event_timestamp' => $timestamp ? \Carbon\Carbon::parse($timestamp) : null,
            'meta' => [
                'bodyPreview' => $bodyPreview,
                'chatId' => $chatId,
                'messageType' => $data['messageType'] ?? null,
                'importance' => $data['importance'] ?? null,
                'fromUserId' => $fromUserId,
                'fromDisplayName' => $from,
            ],
        ];
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
}
