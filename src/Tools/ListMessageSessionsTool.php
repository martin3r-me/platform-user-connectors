<?php

namespace Platform\UserConnectors\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Models\UserConnectorMessageSession;

class ListMessageSessionsTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'user-connectors.message-sessions.list';
    }

    public function getDescription(): string
    {
        return 'Listet Message Sessions (Teams Chat & SMS) des Users auf. Zeigt Absender, Nachricht, Typ und Richtung. Filterbar nach Connector, Nachrichtentyp, Richtung und Chat-ID.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connector_key' => ['type' => 'string', 'description' => 'Filter nach Connector: microsoft365, sipgate, ringcentral, vodafone.'],
                'message_type' => ['type' => 'string', 'description' => 'Filter nach Typ: teams_chat, sms.'],
                'direction' => ['type' => 'string', 'description' => 'Filter nach Richtung: inbound, outbound.'],
                'chat_id' => ['type' => 'string', 'description' => 'Filter nach Teams Chat ID.'],
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
                return ToolResult::success(['message_sessions' => [], 'total' => 0]);
            }

            $limit = min($arguments['limit'] ?? 25, 100);

            $query = UserConnectorMessageSession::query()
                ->whereIn('connection_id', $connectionIds)
                ->orderByDesc('sent_at');

            if (!empty($arguments['connector_key'])) {
                $query->where('connector_key', $arguments['connector_key']);
            }

            if (!empty($arguments['message_type'])) {
                $query->where('message_type', $arguments['message_type']);
            }

            if (!empty($arguments['direction'])) {
                $query->where('direction', $arguments['direction']);
            }

            if (!empty($arguments['chat_id'])) {
                $query->where('chat_id', $arguments['chat_id']);
            }

            $sessions = $query->limit($limit)->get();

            $result = $sessions->map(function (UserConnectorMessageSession $session) {
                return [
                    'id' => $session->id,
                    'connection_id' => $session->connection_id,
                    'connector_key' => $session->connector_key,
                    'external_message_id' => $session->external_message_id,
                    'message_type' => $session->message_type,
                    'direction' => $session->direction,
                    'from_identifier' => $session->from_identifier,
                    'from_user_id' => $session->from_user_id,
                    'to_identifier' => $session->to_identifier,
                    'body_preview' => $session->body_preview,
                    'chat_id' => $session->chat_id,
                    'importance' => $session->importance,
                    'sent_at' => $session->sent_at?->toIso8601String(),
                    'meta' => $session->meta ?? [],
                ];
            })->all();

            return ToolResult::success([
                'message_sessions' => $result,
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
            'tags' => ['user-connectors', 'message-sessions', 'teams', 'sms', 'chat', 'microsoft365'],
            'read_only' => true,
            'requires_auth' => true,
            'risk_level' => 'safe',
            'cost_class' => 'database',
        ];
    }
}
