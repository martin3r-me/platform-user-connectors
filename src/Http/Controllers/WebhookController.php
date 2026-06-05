<?php

namespace Platform\UserConnectors\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Platform\UserConnectors\Jobs\EnrichMicrosoft365EventJob;
use Platform\UserConnectors\Models\UserConnectorCallSession;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Models\UserConnectorOAuthApp;
use Platform\UserConnectors\Services\InboundEventService;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365AppTokenService;

class WebhookController extends Controller
{
    public function __construct(
        protected InboundEventService $inboundService,
    ) {}

    /**
     * Sipgate Webhook Endpoint.
     *
     * Receives push notifications from Sipgate for calls, SMS, fax events.
     * This endpoint is UNAUTHENTICATED — Sipgate sends data here directly.
     */
    public function sipgate(Request $request)
    {
        $payload = $request->all();

        Log::info('UserConnectors Sipgate Webhook received', [
            'event' => $payload['event'] ?? 'unknown',
            'callId' => $payload['callId'] ?? null,
        ]);

        // Verify signature if configured
        if (config('user-connectors.sipgate.webhook.signature_enabled', false)) {
            $secret = config('user-connectors.sipgate.webhook.secret');
            if ($secret) {
                $signature = $request->header('X-Sipgate-Signature')
                    ?? $request->header('X-Signature')
                    ?? $request->header('Signature');

                $expectedSignature = hash_hmac('sha256', $request->getContent(), $secret);

                if (!$signature || !hash_equals($expectedSignature, $signature)) {
                    Log::warning('UserConnectors Sipgate Webhook: Invalid signature');
                    return response()->json(['error' => 'Invalid signature'], 403);
                }
            }
        }

        // Map Sipgate event types to our normalized types
        $sipgateEvent = $payload['event'] ?? '';
        // Sipgate sends 'newCall' on initial push, but callback URLs
        // receive 'answer'/'hangup' (without 'on' prefix).
        $eventType = match ($sipgateEvent) {
            'newCall' => 'call.new',
            'onAnswer', 'answer' => 'call.answered',
            'onHangup', 'hangup' => 'call.hangup',
            'dtmf' => 'call.dtmf',
            default => 'sipgate.' . $sipgateEvent,
        };

        // Build idempotency key
        $callId = $payload['callId'] ?? '';
        $timestamp = $payload['timestamp'] ?? '';
        $idempotencyKey = hash('sha256', "{$sipgateEvent}:{$callId}:{$timestamp}");

        // Resolve connection
        $connection = $this->inboundService->resolveConnectionFromSipgate($payload);

        $event = $this->inboundService->ingest(
            connectorKey: 'sipgate',
            eventType: $eventType,
            payload: $payload,
            idempotencyKey: $idempotencyKey,
            connectionId: $connection?->id,
        );

        if (!$event) {
            return $this->sipgateXmlResponse($sipgateEvent);
        }

        // Sipgate Push API: newCall response must declare onAnswer/onHangup URLs
        // to receive follow-up events for the call lifecycle.
        return $this->sipgateXmlResponse($sipgateEvent);
    }

    /**
     * Build Sipgate XML response.
     *
     * For newCall events, the response must include onAnswer and onHangup
     * attributes pointing back to this endpoint, otherwise Sipgate will
     * not send follow-up events for the call.
     */
    protected function sipgateXmlResponse(string $sipgateEvent)
    {
        $webhookUrl = route('user-connectors.webhooks.sipgate');

        if ($sipgateEvent === 'newCall') {
            $xml = '<?xml version="1.0" encoding="UTF-8"?>'
                . '<Response onAnswer="' . htmlspecialchars($webhookUrl) . '" onHangup="' . htmlspecialchars($webhookUrl) . '" />';
        } else {
            $xml = '<?xml version="1.0" encoding="UTF-8"?><Response />';
        }

        return response($xml, 200)
            ->header('Content-Type', 'application/xml');
    }

    /**
     * Microsoft 365 Graph Change Notification Endpoint.
     *
     * Handles subscription validation and change notifications.
     */
    public function microsoft365(Request $request)
    {
        // Subscription validation: Graph sends validationToken as query param
        $validationToken = $request->query('validationToken');
        if ($validationToken) {
            Log::info('UserConnectors MS365 Webhook: Subscription validation');
            return response($validationToken, 200)
                ->header('Content-Type', 'text/plain');
        }

        $notifications = $request->input('value', []);

        Log::info('UserConnectors MS365 Webhook received', [
            'count' => count($notifications),
        ]);

        foreach ($notifications as $notification) {
            $changeType = $notification['changeType'] ?? '';
            $resource = $notification['resource'] ?? '';
            $subscriptionId = $notification['subscriptionId'] ?? '';

            // Resolve connection via subscriptionId + clientState
            $connection = $this->inboundService->resolveConnectionFromMicrosoft365($notification);

            // Determine event type from resource path
            $eventType = $this->mapGraphResourceToEventType($resource, $changeType);

            Log::info('MS365 Webhook: Notification verarbeitet', [
                'event_type' => $eventType,
                'change_type' => $changeType,
                'resource' => $resource,
                'connection_id' => $connection?->id,
                'connection_name' => $connection?->name,
                'resolved' => $connection !== null,
            ]);

            $idempotencyKey = hash('sha256', "{$subscriptionId}:{$resource}:{$changeType}:" . json_encode($notification));

            $event = $this->inboundService->ingest(
                connectorKey: 'microsoft365',
                eventType: $eventType,
                payload: $notification,
                idempotencyKey: $idempotencyKey,
                connectionId: $connection?->id,
            );

            if ($event && !$connection) {
                Log::warning('MS365 Webhook: Event ohne Connection gespeichert (Subscription nicht aufgelöst)', [
                    'event_id' => $event->id,
                    'event_type' => $eventType,
                    'subscription_id' => $subscriptionId,
                ]);
            }

            // Dispatch enrichment job to fetch actual content from Graph API
            if ($event && $connection) {
                EnrichMicrosoft365EventJob::dispatch($event->id);
            }
        }

        return response('', 202);
    }

    /**
     * RingCentral Webhook Endpoint.
     */
    public function ringcentral(Request $request)
    {
        // Determine connector key from route (ringcentral or vodafone)
        $connectorKey = $request->route()->getName() === 'user-connectors.webhooks.vodafone'
            ? 'vodafone'
            : 'ringcentral';

        // Validation request
        $validationToken = $request->header('Validation-Token');
        if ($validationToken) {
            Log::info("UserConnectors {$connectorKey} Webhook: Validation");
            return response('', 200)
                ->header('Validation-Token', $validationToken);
        }

        $payload = $request->all();

        $rcEvent = $payload['event'] ?? '';

        // Resolve connection via subscriptionId — search both ringcentral and vodafone connectors
        $connection = $this->inboundService->resolveConnectionFromRingCentral($payload, $connectorKey);

        Log::info("UserConnectors {$connectorKey} Webhook received", [
            'event' => $rcEvent ?: 'unknown',
            'connection_id' => $connection?->id,
            'connection_name' => $connection?->name,
            'resolved' => $connection !== null,
            'subscription_id' => $payload['subscriptionId'] ?? null,
        ]);

        if (!$connection) {
            Log::warning("UserConnectors {$connectorKey} Webhook: Connection nicht aufgelöst", [
                'event' => $rcEvent,
                'subscription_id' => $payload['subscriptionId'] ?? null,
            ]);
        }

        // For telephony sessions, extract party status and emit per-party call events
        if (str_contains($rcEvent, 'telephony/sessions')) {
            $this->handleRingCentralTelephonySession($payload, $connection?->id, $connectorKey);
            return response('', 200);
        }

        $eventType = $this->mapRingCentralEventType($rcEvent);
        $subscriptionId = $payload['subscriptionId'] ?? '';
        $timestamp = $payload['timestamp'] ?? '';
        $idempotencyKey = hash('sha256', "{$subscriptionId}:{$rcEvent}:{$timestamp}");

        $this->inboundService->ingest(
            connectorKey: $connectorKey,
            eventType: $eventType,
            payload: $payload,
            idempotencyKey: $idempotencyKey,
            connectionId: $connection?->id,
        );

        return response('', 200);
    }

    /**
     * Handle RingCentral telephony session notifications.
     *
     * RingCentral sends a single event type for all call state changes.
     * The actual call status is in body.parties[].status.code.
     * We extract the primary party and map its status to our call.* events.
     */
    protected function handleRingCentralTelephonySession(array $payload, ?int $connectionId, string $connectorKey = 'ringcentral'): void
    {
        $body = $payload['body'] ?? [];
        $parties = $body['parties'] ?? [];
        $telephonySessionId = $body['telephonySessionId'] ?? null;
        $sequence = $body['sequence'] ?? 0;
        $eventTime = $body['eventTime'] ?? $payload['timestamp'] ?? null;
        $subscriptionId = $payload['subscriptionId'] ?? '';

        if (!$telephonySessionId || empty($parties)) {
            return;
        }

        // Process parties from the telephony session notification.
        // Prefer the subscriber's own party (has extensionId), but also process
        // parties without extensionId for terminal states (Disconnected/Gone)
        // so we don't miss hangup events.
        foreach ($parties as $party) {
            $statusCode = $party['status']['code'] ?? null;
            if (!$statusCode) {
                continue;
            }

            $hasExtensionId = !empty($party['extensionId']);
            $isTerminalState = in_array($statusCode, ['Disconnected', 'Gone', 'Voicemail']);

            // Skip parties without extensionId UNLESS it's a terminal state
            // (hangup can arrive on the external leg when the subscriber's party is gone)
            if (!$hasExtensionId && !$isTerminalState) {
                continue;
            }

            $eventType = match ($statusCode) {
                'Setup' => 'call.new',
                'Proceeding' => null, // Skip — Setup already created the call.new event
                'Answered' => 'call.answered',
                'Disconnected', 'Gone' => 'call.hangup',
                'Voicemail' => 'call.voicemail',
                default => null,
            };

            if (!$eventType) {
                continue;
            }

            $partyId = $party['id'] ?? '';
            $direction = strtolower($party['direction'] ?? '');
            $from = $party['from']['phoneNumber'] ?? $party['from']['name'] ?? null;
            $to = $party['to']['phoneNumber'] ?? $party['to']['name'] ?? null;

            // For terminal states without extensionId, deduplicate per session+status
            // (multiple parties may report Disconnected, we only need one)
            if (!$hasExtensionId && $isTerminalState) {
                $idempotencyKey = hash('sha256', "{$telephonySessionId}:terminal:{$statusCode}:{$sequence}");
            } else {
                $idempotencyKey = hash('sha256', "{$telephonySessionId}:{$partyId}:{$statusCode}:{$sequence}");
            }

            // Flatten payload for InboundEventService extraction
            $flatPayload = [
                'telephonySessionId' => $telephonySessionId,
                'callId' => $telephonySessionId,
                'partyId' => $partyId,
                'direction' => $direction,
                'from' => $from,
                'to' => $to,
                'timestamp' => $eventTime,
                'statusCode' => $statusCode,
                'cause' => $party['status']['reason'] ?? null,
                'answeringNumber' => $eventType === 'call.answered' ? ($to ?? null) : null,
                'subscriptionId' => $subscriptionId,
                '_rc_body' => $body,
                '_rc_party' => $party,
            ];

            $this->inboundService->ingest(
                connectorKey: $connectorKey,
                eventType: $eventType,
                payload: $flatPayload,
                idempotencyKey: $idempotencyKey,
                connectionId: $connectionId,
            );
        }
    }

    /**
     * Microsoft 365 Call Records Webhook Endpoint.
     *
     * Receives change notifications for /communications/callRecords (app-level subscription).
     * Fetches full call record, matches participants to connections, creates CallSessions.
     */
    public function microsoft365CallRecords(Request $request)
    {
        // Subscription validation
        $validationToken = $request->query('validationToken');
        if ($validationToken) {
            Log::info('UserConnectors MS365 CallRecords Webhook: Subscription validation');
            return response($validationToken, 200)
                ->header('Content-Type', 'text/plain');
        }

        $notifications = $request->input('value', []);

        Log::info('UserConnectors MS365 CallRecords Webhook received', [
            'count' => count($notifications),
        ]);

        foreach ($notifications as $notification) {
            $resourcePath = $notification['resource'] ?? '';
            $callRecordId = $notification['resourceData']['id']
                ?? $this->extractCallRecordId($resourcePath);

            if (!$callRecordId) {
                continue;
            }

            // Deduplicate
            $idempotencyKey = hash('sha256', "ms365-callrecord:{$callRecordId}");
            if (UserConnectorCallSession::where('external_call_id', $callRecordId)->exists()) {
                continue;
            }

            $this->processCallRecord($callRecordId);
        }

        return response('', 202);
    }

    protected function processCallRecord(string $callRecordId): void
    {
        // Find an MS365 OAuthApp that has tenant_id configured (needed for app token)
        $oauthApp = $this->findMs365OAuthAppWithTenant();
        if (!$oauthApp) {
            Log::warning('MS365 CallRecords: Keine OAuthApp mit tenant_id konfiguriert');
            return;
        }

        $appTokenService = app(Microsoft365AppTokenService::class);
        $token = $appTokenService->getAppToken($oauthApp);
        if (!$token) {
            Log::warning('MS365 CallRecords: Kein App-Token verfügbar');
            return;
        }

        $baseUrl = config('user-connectors.microsoft365.graph_base_url', 'https://graph.microsoft.com/v1.0');

        $response = Http::withToken($token)
            ->timeout(30)
            ->get("{$baseUrl}/communications/callRecords/{$callRecordId}", [
                '$expand' => 'sessions($expand=segments)',
            ]);

        if (!$response->successful()) {
            Log::error('MS365 CallRecords: Fetch fehlgeschlagen', [
                'callRecordId' => $callRecordId,
                'status' => $response->status(),
            ]);
            return;
        }

        $record = $response->json();
        $this->createCallSessionsFromRecord($record);
    }

    protected function createCallSessionsFromRecord(array $record): void
    {
        $callRecordId = $record['id'] ?? null;
        if (!$callRecordId) {
            return;
        }

        $type = $record['type'] ?? 'unknown';
        $startDateTime = isset($record['startDateTime']) ? Carbon::parse($record['startDateTime']) : null;
        $endDateTime = isset($record['endDateTime']) ? Carbon::parse($record['endDateTime']) : null;

        $durationSeconds = ($startDateTime && $endDateTime)
            ? $startDateTime->diffInSeconds($endDateTime)
            : null;

        $status = ($durationSeconds !== null && $durationSeconds === 0) ? 'missed' : 'completed';

        // Collect all participant user IDs from the call record
        $participantUserIds = $this->extractParticipantUserIds($record);

        // Find connections that match these participant IDs
        $connections = UserConnectorConnection::query()
            ->whereHas('connector', fn ($q) => $q->where('key', 'microsoft365'))
            ->where('status', 'active')
            ->get()
            ->filter(fn ($conn) => in_array($conn->credentials['ms365_user_id'] ?? null, $participantUserIds));

        if ($connections->isEmpty()) {
            Log::debug('MS365 CallRecords: Keine passende Connection für Participants', [
                'callRecordId' => $callRecordId,
                'participantIds' => $participantUserIds,
            ]);
            return;
        }

        // Extract caller/callee from sessions
        $sessions = $record['sessions'] ?? [];
        $firstSession = $sessions[0] ?? [];
        $caller = $firstSession['caller'] ?? $record['organizer'] ?? [];
        $callee = $firstSession['callee'] ?? [];

        // Graph session endpoints use identity.user, top-level organizer uses user directly
        $fromDisplay = $caller['identity']['user']['displayName']
            ?? $caller['user']['displayName']
            ?? $caller['identity']['phone']['displayName']
            ?? $caller['phone']['displayName']
            ?? $caller['phone']['id']
            ?? null;
        $toDisplay = $callee['identity']['user']['displayName']
            ?? $callee['user']['displayName']
            ?? $callee['identity']['phone']['displayName']
            ?? $callee['phone']['displayName']
            ?? $callee['phone']['id']
            ?? null;

        $callerUserId = $caller['identity']['user']['id']
            ?? $caller['user']['id']
            ?? null;

        foreach ($connections as $connection) {
            $userMs365Id = $connection->credentials['ms365_user_id'] ?? null;

            // Determine direction for this user
            $direction = ($userMs365Id === $callerUserId) ? 'outbound' : 'inbound';

            UserConnectorCallSession::create([
                'connection_id' => $connection->id,
                'connector_key' => 'microsoft365',
                'external_call_id' => $callRecordId,
                'direction' => $direction,
                'status' => $status,
                'from_number' => $fromDisplay,
                'to_number' => $toDisplay,
                'started_at' => $startDateTime,
                'answered_at' => $status === 'completed' ? $startDateTime : null,
                'ended_at' => $endDateTime,
                'duration_seconds' => $durationSeconds,
                'meta' => [
                    'callType' => $type,
                    'modalities' => $record['modalities'] ?? [],
                    'sessionCount' => count($sessions),
                    'organizer' => $record['organizer']['user']['displayName'] ?? null,
                    'participants' => collect($record['participants'] ?? [])
                        ->map(fn ($p) => $p['user']['displayName'] ?? $p['identity']['user']['displayName'] ?? null)
                        ->filter()->values()->all(),
                ],
            ]);

            Log::info('MS365 CallRecords: CallSession erstellt', [
                'callRecordId' => $callRecordId,
                'connection_id' => $connection->id,
                'direction' => $direction,
                'status' => $status,
            ]);
        }
    }

    protected function extractParticipantUserIds(array $record): array
    {
        $ids = [];

        // From organizer
        if ($orgId = ($record['organizer']['user']['id'] ?? null)) {
            $ids[] = $orgId;
        }

        // From participants array
        foreach ($record['participants'] ?? [] as $participant) {
            if ($userId = ($participant['user']['id'] ?? null)) {
                $ids[] = $userId;
            }
        }

        // From sessions caller/callee (Graph uses identity.user for session endpoints)
        foreach ($record['sessions'] ?? [] as $session) {
            if ($userId = ($session['caller']['identity']['user']['id'] ?? $session['caller']['user']['id'] ?? null)) {
                $ids[] = $userId;
            }
            if ($userId = ($session['callee']['identity']['user']['id'] ?? $session['callee']['user']['id'] ?? null)) {
                $ids[] = $userId;
            }
            // From segments
            foreach ($session['segments'] ?? [] as $segment) {
                if ($userId = ($segment['caller']['identity']['user']['id'] ?? $segment['caller']['user']['id'] ?? null)) {
                    $ids[] = $userId;
                }
                if ($userId = ($segment['callee']['identity']['user']['id'] ?? $segment['callee']['user']['id'] ?? null)) {
                    $ids[] = $userId;
                }
            }
        }

        return array_unique(array_filter($ids));
    }

    protected function extractCallRecordId(string $resourcePath): ?string
    {
        if (preg_match("/callRecords[\/\(]'?([^'\)\/]+)/", $resourcePath, $matches)) {
            return $matches[1];
        }
        return null;
    }

    protected function findMs365OAuthAppWithTenant(): ?UserConnectorOAuthApp
    {
        return UserConnectorOAuthApp::query()
            ->whereHas('connector', fn ($q) => $q->where('key', 'microsoft365'))
            ->where('is_enabled', true)
            ->get()
            ->first(fn ($app) => !empty($app->settings['tenant_id']));
    }

    protected function mapGraphResourceToEventType(string $resource, string $changeType): string
    {
        $r = strtolower($resource);

        // Teams chat messages: chats/{id}/messages/{id} — check before mail (also has 'messages')
        if (str_contains($r, 'chats') || str_contains($r, 'teams')) {
            return 'teams.' . $changeType;
        }

        // Mail: me/messages/{id}, Users/{id}/Messages/{id}, me/mailFolders/.../messages/...
        if (str_contains($r, 'messages') || str_contains($r, 'mailfolders')) {
            return 'mail.' . $changeType;
        }

        // Calendar: me/events/{id}, me/calendar/...
        if (str_contains($r, 'events') || str_contains($r, 'calendar')) {
            return 'calendar.' . $changeType;
        }

        return 'microsoft365.' . $changeType;
    }

    protected function mapRingCentralEventType(string $event): string
    {
        if (str_contains($event, 'telephony/sessions')) {
            return 'call.session';
        }
        if (str_contains($event, 'message-store')) {
            return 'sms.inbound';
        }
        if (str_contains($event, 'voicemail')) {
            return 'call.voicemail';
        }

        return 'ringcentral.' . $event;
    }
}
