<?php

namespace Platform\UserConnectors\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Models\UserConnectorCallSession;
use Platform\UserConnectors\Models\UserConnectorConnection;

class ListCallSessionsTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'user-connectors.call-sessions.list';
    }

    public function getDescription(): string
    {
        return 'Listet Call Sessions (Telefonate) des Users auf. Zeigt Richtung, Von/An-Nummern, Status, Dauer und Meta-Daten. Filterbar nach Connector, Status, Richtung und Zeitraum.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connector_key' => ['type' => 'string', 'description' => 'Filter nach Connector: microsoft365, sipgate, ringcentral, vodafone.'],
                'status' => ['type' => 'string', 'description' => 'Filter nach Status: ringing, active, completed, missed, failed.'],
                'direction' => ['type' => 'string', 'description' => 'Filter nach Richtung: inbound, outbound.'],
                'connection_id' => ['type' => 'integer', 'description' => 'Filter nach Connection-ID.'],
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
            $connectionIds = UserConnectorConnection::query()
                ->where('owner_user_id', $context->user->id)
                ->pluck('id')
                ->all();

            if (empty($connectionIds)) {
                return ToolResult::success(['call_sessions' => [], 'total' => 0]);
            }

            $limit = min($arguments['limit'] ?? 25, 100);

            $query = UserConnectorCallSession::query()
                ->whereIn('connection_id', $connectionIds)
                ->orderByRaw("CASE WHEN status IN ('ringing', 'active') THEN 0 ELSE 1 END")
                ->orderByDesc('started_at');

            if (!empty($arguments['connector_key'])) {
                $query->where('connector_key', $arguments['connector_key']);
            }

            if (!empty($arguments['status'])) {
                $query->where('status', $arguments['status']);
            }

            if (!empty($arguments['direction'])) {
                $query->where('direction', $arguments['direction']);
            }

            if (!empty($arguments['connection_id'])) {
                $query->where('connection_id', (int) $arguments['connection_id']);
            }

            $sessions = $query->limit($limit)->get();

            $result = $sessions->map(function (UserConnectorCallSession $session) {
                return [
                    'id' => $session->id,
                    'connection_id' => $session->connection_id,
                    'connector_key' => $session->connector_key,
                    'external_call_id' => $session->external_call_id,
                    'direction' => $session->direction,
                    'status' => $session->status,
                    'from_number' => $session->from_number,
                    'to_number' => $session->to_number,
                    'answering_number' => $session->answering_number,
                    'started_at' => $session->started_at?->toIso8601String(),
                    'answered_at' => $session->answered_at?->toIso8601String(),
                    'ended_at' => $session->ended_at?->toIso8601String(),
                    'duration' => $session->durationForHumans(),
                    'duration_seconds' => $session->duration_seconds,
                    'hangup_cause' => $session->hangup_cause,
                    'meta' => $session->meta ?? [],
                ];
            })->all();

            return ToolResult::success([
                'call_sessions' => $result,
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
            'tags' => ['user-connectors', 'call-sessions', 'calls', 'telephony', 'sipgate', 'ringcentral', 'microsoft365'],
            'read_only' => true,
            'requires_auth' => true,
            'risk_level' => 'safe',
            'cost_class' => 'database',
        ];
    }
}
