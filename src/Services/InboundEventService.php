<?php

namespace Platform\UserConnectors\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Platform\UserConnectors\Events\InboundCallEvent;
use Platform\UserConnectors\Events\InboundEventReceived;
use Platform\UserConnectors\Events\InboundMessageEvent;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Models\UserConnectorInboundEvent;

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

        $event->markProcessed();

        return $event;
    }

    /**
     * Resolve connection from Sipgate user/account info in webhook payload.
     */
    public function resolveConnectionFromSipgate(array $payload): ?UserConnectorConnection
    {
        $userId = $payload['userId'] ?? $payload['fullUserId'] ?? null;
        if (!$userId) {
            return null;
        }

        // Search connections by sipgate_sub in credentials
        return UserConnectorConnection::query()
            ->whereHas('connector', fn ($q) => $q->where('key', 'sipgate'))
            ->where('status', 'active')
            ->get()
            ->first(function (UserConnectorConnection $conn) use ($userId) {
                $sub = $conn->credentials['oauth']['sipgate_sub'] ?? null;
                return $sub && $sub === $userId;
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
     * Resolve connection from RingCentral webhook payload via subscriptionId.
     */
    public function resolveConnectionFromRingCentral(array $payload): ?UserConnectorConnection
    {
        $subscriptionId = $payload['subscriptionId'] ?? null;
        if (!$subscriptionId) {
            return null;
        }

        $manager = app(WebhookSubscriptionManager::class);

        return $manager->resolveConnectionBySubscriptionId('ringcentral', $subscriptionId);
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
