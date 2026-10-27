<?php

namespace Platform\UserConnectors\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Models\UserConnectorInboundEvent;

/**
 * Webhook-/Event-Log der eigenen Connections — Debugging der Synchronisation.
 */
class ListEventsTool extends ConnectorTool
{
    private const KEYS = ['microsoft365', 'sipgate', 'ringcentral', 'vodafone'];

    public function getName(): string
    {
        return 'user-connectors.events.list';
    }

    public function getDescription(): string
    {
        return 'Event-Log eingehender Webhooks der eigenen Verbindungen (Mail, Kalender, Teams, Anrufe, SMS) — zum Debuggen, '
            . 'warum etwas nicht in der Inbox gelandet ist. processing_status=failed zeigt fehlgeschlagene; mit event_id inkl. Payload. '
            . 'Neu verarbeiten: user-connectors.events.reprocess.';
    }

    public function getSchema(): array
    {
        $props = self::listProperties(self::KEYS, 'Empfangen');
        unset($props['include_shared']);

        return [
            'type' => 'object',
            'properties' => $props + [
                'event_id' => ['type' => 'integer', 'description' => 'Einzelnes Event inkl. Payload.'],
                'event_type' => ['type' => 'string', 'description' => 'Präfix: mail, calendar, teams, call, sms.'],
                'direction' => ['type' => 'string', 'enum' => ['inbound', 'outbound']],
                'processing_status' => ['type' => 'string', 'description' => 'z. B. failed, processed, pending.'],
                'include_payload' => ['type' => 'boolean', 'description' => 'Roh-Payload mitliefern (groß). Default false.'],
            ],
        ];
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        // Events enthalten Roh-Payloads — nur eigene Connections.
        $connectionIds = $this->scopedConnectionIds($context->user, ['include_shared' => false] + $arguments, self::KEYS);

        if (!empty($arguments['event_id'])) {
            $event = UserConnectorInboundEvent::query()
                ->whereKey((int) $arguments['event_id'])
                ->whereIn('connection_id', $connectionIds)
                ->first();
            if (!$event) {
                return ToolResult::error('Event nicht gefunden oder kein Zugriff.', 'NOT_FOUND');
            }

            return ToolResult::success(['event' => $this->present($event, true) + ['idempotency_key' => $event->idempotency_key]]);
        }

        $query = UserConnectorInboundEvent::query()
            ->whereIn('connection_id', $connectionIds)
            ->orderByDesc('created_at');

        foreach (['direction', 'processing_status'] as $column) {
            if (!empty($arguments[$column])) {
                $query->where($column, (string) $arguments[$column]);
            }
        }
        if (!empty($arguments['event_type'])) {
            $query->where('event_type', 'like', addcslashes((string) $arguments['event_type'], '%_\\') . '%');
        }
        [$from, $to] = $this->dateRange($arguments);
        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }

        $withPayload = (bool) $this->optionalBool($arguments, 'include_payload');

        return ToolResult::success($this->paginate($query, $arguments, 'events', fn (UserConnectorInboundEvent $e) => $this->present($e, $withPayload)));
    }

    private function present(UserConnectorInboundEvent $e, bool $withPayload): array
    {
        return array_filter([
            'id' => $e->id,
            'connection_id' => $e->connection_id,
            'connector_key' => $e->connector_key,
            'event_type' => $e->event_type,
            'direction' => $e->direction,
            'from_identifier' => $e->from_identifier,
            'to_identifier' => $e->to_identifier,
            'external_id' => $e->external_id,
            'processing_status' => $e->processing_status,
            'processing_error' => $e->processing_error,
            'event_timestamp' => $e->event_timestamp?->toIso8601String(),
            'session_correlated_at' => $e->session_correlated_at?->toIso8601String(),
            'created_at' => $e->created_at?->toIso8601String(),
            'meta' => $e->meta ?: null,
            'payload' => $withPayload ? ($e->payload ?? []) : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function getMetadata(): array
    {
        return self::readMetadata(['events', 'event-log', 'webhooks', 'debug', 'mail', 'calendar', 'teams', 'calls']);
    }
}
