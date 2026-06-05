<?php

namespace Platform\UserConnectors\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Models\UserConnectorInboundEvent;

class ListEventsTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'user-connectors.events.list';
    }

    public function getDescription(): string
    {
        return 'Listet eingehende Webhook-Events (Event-Log) des Users auf. Zeigt Event-Typ, Richtung, Von/An, Kontext (Subject, Body-Preview), Meta-Daten und Processing-Status. Filterbar nach Connector, Event-Typ, Richtung und Zeitraum. Nutze event_id für Detail-Ansicht eines einzelnen Events (inkl. Payload). Nutze include_payload=true für Debugging der rohen Webhook-Daten.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'description' => 'Einzelnes Event per ID laden (zeigt automatisch Payload mit).'],
                'connector_key' => ['type' => 'string', 'description' => 'Filter nach Connector: microsoft365, sipgate, ringcentral, vodafone.'],
                'event_type' => ['type' => 'string', 'description' => 'Filter nach Event-Typ (prefix-match): mail, calendar, teams, call, sms.'],
                'direction' => ['type' => 'string', 'description' => 'Filter nach Richtung: inbound, outbound.'],
                'connection_id' => ['type' => 'integer', 'description' => 'Filter nach Connection-ID.'],
                'include_payload' => ['type' => 'boolean', 'description' => 'Rohen Webhook-Payload mitliefern (für Debugging). Standard: false.'],
                'limit' => ['type' => 'integer', 'description' => 'Anzahl Ergebnisse. Standard: 25, Max: 100.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('AUTH_ERROR', 'Benutzer nicht authentifiziert.');
        }

        try {
            // Get all connection IDs for this user
            $connectionIds = UserConnectorConnection::query()
                ->where('owner_user_id', $context->user->id)
                ->pluck('id')
                ->all();

            if (empty($connectionIds)) {
                return ToolResult::success(['events' => [], 'total' => 0]);
            }

            $includePayload = !empty($arguments['include_payload']);

            // Single event lookup by ID
            if (!empty($arguments['event_id'])) {
                $event = UserConnectorInboundEvent::where('id', (int) $arguments['event_id'])
                    ->whereIn('connection_id', $connectionIds)
                    ->first();

                if (!$event) {
                    return ToolResult::error('NOT_FOUND', 'Event nicht gefunden.');
                }

                $item = [
                    'id' => $event->id,
                    'connection_id' => $event->connection_id,
                    'connector_key' => $event->connector_key,
                    'event_type' => $event->event_type,
                    'direction' => $event->direction,
                    'from_identifier' => $event->from_identifier,
                    'to_identifier' => $event->to_identifier,
                    'external_id' => $event->external_id,
                    'idempotency_key' => $event->idempotency_key,
                    'processing_status' => $event->processing_status,
                    'processing_error' => $event->processing_error,
                    'event_timestamp' => $event->event_timestamp?->toIso8601String(),
                    'created_at' => $event->created_at->toIso8601String(),
                    'meta' => $event->meta ?? [],
                    'payload' => $event->payload ?? [],
                ];

                return ToolResult::success(['event' => $item]);
            }

            $limit = min($arguments['limit'] ?? 25, 100);

            $query = UserConnectorInboundEvent::query()
                ->whereIn('connection_id', $connectionIds)
                ->orderByDesc('created_at');

            if (!empty($arguments['connector_key'])) {
                $query->where('connector_key', $arguments['connector_key']);
            }

            if (!empty($arguments['event_type'])) {
                $query->where('event_type', 'like', $arguments['event_type'] . '%');
            }

            if (!empty($arguments['direction'])) {
                $query->where('direction', $arguments['direction']);
            }

            if (!empty($arguments['connection_id'])) {
                $query->where('connection_id', (int) $arguments['connection_id']);
            }

            $events = $query->limit($limit)->get();

            $result = $events->map(function (UserConnectorInboundEvent $event) use ($includePayload) {
                $item = [
                    'id' => $event->id,
                    'connection_id' => $event->connection_id,
                    'connector_key' => $event->connector_key,
                    'event_type' => $event->event_type,
                    'direction' => $event->direction,
                    'from_identifier' => $event->from_identifier,
                    'to_identifier' => $event->to_identifier,
                    'external_id' => $event->external_id,
                    'processing_status' => $event->processing_status,
                    'processing_error' => $event->processing_error,
                    'event_timestamp' => $event->event_timestamp?->toIso8601String(),
                    'created_at' => $event->created_at->toIso8601String(),
                    'meta' => $event->meta ?? [],
                ];

                if ($includePayload) {
                    $item['payload'] = $event->payload ?? [];
                }

                return $item;
            })->all();

            return ToolResult::success([
                'events' => $result,
                'total' => count($result),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['user-connectors', 'events', 'event-log', 'webhooks', 'mail', 'calendar', 'teams', 'calls'],
            'read_only' => true,
            'requires_auth' => true,
            'risk_level' => 'safe',
            'cost_class' => 'database',
        ];
    }
}
