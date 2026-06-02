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
        $eventType = match ($sipgateEvent) {
            'newCall' => 'call.new',
            'onAnswer' => 'call.answered',
            'onHangup' => 'call.hangup',
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
        $eventType = $this->mapRingCentralEventType($rcEvent);

        // Resolve connection via subscriptionId
        $connection = $this->inboundService->resolveConnectionFromRingCentral($payload);

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
