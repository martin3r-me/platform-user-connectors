<?php

namespace Platform\UserConnectors\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Models\UserConnectorMailSession;

class ListMailSessionsTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'user-connectors.mail-sessions.list';
    }

    public function getDescription(): string
    {
        return 'Listet Mail Sessions (E-Mails) des Users auf. Zeigt Absender, Betreff, Empfänger, Lese-Status und Anhänge. Filterbar nach Connector, Status, Richtung und Lese-Status.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connector_key' => ['type' => 'string', 'description' => 'Filter nach Connector: microsoft365.'],
                'status' => ['type' => 'string', 'description' => 'Filter nach Status: new, read.'],
                'direction' => ['type' => 'string', 'description' => 'Filter nach Richtung: inbound, outbound.'],
                'is_read' => ['type' => 'boolean', 'description' => 'Filter nach Lese-Status: true = gelesen, false = ungelesen.'],
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
                return ToolResult::success(['mail_sessions' => [], 'total' => 0]);
            }

            $limit = min($arguments['limit'] ?? 25, 100);

            $query = UserConnectorMailSession::query()
                ->whereIn('connection_id', $connectionIds)
                ->orderByRaw("CASE WHEN is_read = false THEN 0 ELSE 1 END")
                ->orderByDesc('received_at');

            if (!empty($arguments['connector_key'])) {
                $query->where('connector_key', $arguments['connector_key']);
            }

            if (!empty($arguments['status'])) {
                $query->where('status', $arguments['status']);
            }

            if (!empty($arguments['direction'])) {
                $query->where('direction', $arguments['direction']);
            }

            if (isset($arguments['is_read'])) {
                $query->where('is_read', (bool) $arguments['is_read']);
            }

            $sessions = $query->limit($limit)->get();

            $result = $sessions->map(function (UserConnectorMailSession $session) {
                return [
                    'id' => $session->id,
                    'connection_id' => $session->connection_id,
                    'connector_key' => $session->connector_key,
                    'external_mail_id' => $session->external_mail_id,
                    'conversation_id' => $session->conversation_id,
                    'direction' => $session->direction,
                    'status' => $session->status,
                    'from_address' => $session->from_address,
                    'from_name' => $session->from_name,
                    'to_addresses' => $session->to_addresses,
                    'cc_addresses' => $session->cc_addresses,
                    'subject' => $session->subject,
                    'body_preview' => $session->body_preview,
                    'is_read' => $session->is_read,
                    'has_attachments' => $session->has_attachments,
                    'is_draft' => $session->is_draft,
                    'shared_mailbox' => $session->shared_mailbox,
                    'received_at' => $session->received_at?->toIso8601String(),
                    'sent_at' => $session->sent_at?->toIso8601String(),
                    'meta' => $session->meta ?? [],
                ];
            })->all();

            return ToolResult::success([
                'mail_sessions' => $result,
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
            'tags' => ['user-connectors', 'mail-sessions', 'email', 'microsoft365'],
            'read_only' => true,
            'requires_auth' => true,
            'risk_level' => 'safe',
            'cost_class' => 'database',
        ];
    }
}
