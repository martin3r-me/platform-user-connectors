<?php

namespace Platform\UserConnectors\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Platform\UserConnectors\Services\InboundEventService;

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

            $idempotencyKey = hash('sha256', "{$subscriptionId}:{$resource}:{$changeType}:" . json_encode($notification));

            $this->inboundService->ingest(
                connectorKey: 'microsoft365',
                eventType: $eventType,
                payload: $notification,
                idempotencyKey: $idempotencyKey,
                connectionId: $connection?->id,
            );
        }

        return response('', 202);
    }

    /**
     * RingCentral Webhook Endpoint.
     */
    public function ringcentral(Request $request)
    {
        // Validation request
        $validationToken = $request->header('Validation-Token');
        if ($validationToken) {
            Log::info('UserConnectors RingCentral Webhook: Validation');
            return response('', 200)
                ->header('Validation-Token', $validationToken);
        }

        $payload = $request->all();

        Log::info('UserConnectors RingCentral Webhook received', [
            'event' => $payload['event'] ?? 'unknown',
        ]);

        $rcEvent = $payload['event'] ?? '';

        // Resolve connection via subscriptionId
        $connection = $this->inboundService->resolveConnectionFromRingCentral($payload);

        // For telephony sessions, extract party status and emit per-party call events
        if (str_contains($rcEvent, 'telephony/sessions')) {
            $this->handleRingCentralTelephonySession($payload, $connection?->id);
            return response('', 200);
        }

        $eventType = $this->mapRingCentralEventType($rcEvent);
        $subscriptionId = $payload['subscriptionId'] ?? '';
        $timestamp = $payload['timestamp'] ?? '';
        $idempotencyKey = hash('sha256', "{$subscriptionId}:{$rcEvent}:{$timestamp}");

        $this->inboundService->ingest(
            connectorKey: 'ringcentral',
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
    protected function handleRingCentralTelephonySession(array $payload, ?int $connectionId): void
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

        // Process only the subscriber's own party (has extensionId).
        // External parties (PSTN, other accounts) are excluded by RingCentral anyway,
        // but multiple parties can still appear in a single notification.
        foreach ($parties as $party) {
            $statusCode = $party['status']['code'] ?? null;
            if (!$statusCode) {
                continue;
            }

            // Skip parties without extensionId — they're external legs
            if (empty($party['extensionId'])) {
                continue;
            }

            $eventType = match ($statusCode) {
                'Setup', 'Proceeding' => 'call.new',
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

            // Build idempotency key from session + party + sequence
            $idempotencyKey = hash('sha256', "{$telephonySessionId}:{$partyId}:{$statusCode}:{$sequence}");

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
                connectorKey: 'ringcentral',
                eventType: $eventType,
                payload: $flatPayload,
                idempotencyKey: $idempotencyKey,
                connectionId: $connectionId,
            );
        }
    }

    protected function mapGraphResourceToEventType(string $resource, string $changeType): string
    {
        if (str_contains($resource, 'messages') && str_contains($resource, 'mail')) {
            return 'mail.' . $changeType;
        }
        if (str_contains($resource, 'events') || str_contains($resource, 'calendar')) {
            return 'calendar.' . $changeType;
        }
        if (str_contains($resource, 'chats') || str_contains($resource, 'teams')) {
            return 'teams.' . $changeType;
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
