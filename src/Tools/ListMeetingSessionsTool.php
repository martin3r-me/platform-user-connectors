<?php

namespace Platform\UserConnectors\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Models\UserConnectorMeetingSession;

class ListMeetingSessionsTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'user-connectors.meeting-sessions.list';
    }

    public function getDescription(): string
    {
        return 'Listet Meeting Sessions (Termine) des Users auf. Zeigt Organizer, Betreff, Zeitraum, Ort und Status. Status wird automatisch aktualisiert. Filterbar nach Connector, Status, Richtung.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connector_key' => ['type' => 'string', 'description' => 'Filter nach Connector: microsoft365.'],
                'status' => ['type' => 'string', 'description' => 'Filter nach Status: upcoming, in_progress, completed, cancelled, deleted.'],
                'direction' => ['type' => 'string', 'description' => 'Filter nach Richtung: inbound (Teilnehmer), outbound (Organizer).'],
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
                return ToolResult::success(['meeting_sessions' => [], 'total' => 0]);
            }

            // Auto-update status for active meetings
            $activeSessions = UserConnectorMeetingSession::query()
                ->whereIn('connection_id', $connectionIds)
                ->whereIn('status', ['upcoming', 'in_progress'])
                ->get();

            foreach ($activeSessions as $session) {
                $session->updateStatusFromTime();
            }

            $limit = min($arguments['limit'] ?? 25, 100);

            $query = UserConnectorMeetingSession::query()
                ->whereIn('connection_id', $connectionIds)
                ->orderByRaw("CASE WHEN status IN ('upcoming', 'in_progress') THEN 0 ELSE 1 END")
                ->orderByDesc('start_at');

            if (!empty($arguments['connector_key'])) {
                $query->where('connector_key', $arguments['connector_key']);
            }

            if (!empty($arguments['status'])) {
                $query->where('status', $arguments['status']);
            }

            if (!empty($arguments['direction'])) {
                $query->where('direction', $arguments['direction']);
            }

            $sessions = $query->limit($limit)->get();

            $result = $sessions->map(function (UserConnectorMeetingSession $session) {
                return [
                    'id' => $session->id,
                    'connection_id' => $session->connection_id,
                    'connector_key' => $session->connector_key,
                    'external_event_id' => $session->external_event_id,
                    'direction' => $session->direction,
                    'status' => $session->status,
                    'organizer_address' => $session->organizer_address,
                    'organizer_name' => $session->organizer_name,
                    'attendee_addresses' => $session->attendee_addresses,
                    'subject' => $session->subject,
                    'body_preview' => $session->body_preview,
                    'location' => $session->location,
                    'is_online_meeting' => $session->is_online_meeting,
                    'online_meeting_url' => $session->online_meeting_url,
                    'start_at' => $session->start_at?->toIso8601String(),
                    'end_at' => $session->end_at?->toIso8601String(),
                    'duration' => $session->durationForHumans(),
                    'duration_minutes' => $session->duration_minutes,
                    'meta' => $session->meta ?? [],
                ];
            })->all();

            return ToolResult::success([
                'meeting_sessions' => $result,
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
            'tags' => ['user-connectors', 'meeting-sessions', 'calendar', 'meetings', 'microsoft365'],
            'read_only' => true,
            'requires_auth' => true,
            'risk_level' => 'safe',
            'cost_class' => 'database',
        ];
    }
}
