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
        return 'Listet E-Mails des Users auf, standardmäßig nach Threads gruppiert (wie Gmail/Outlook). Pro Thread wird die neueste Mail mit message_count, unread_count und last_activity_at angezeigt. Threads mit neuer Aktivität stehen oben. Mit group_by_thread=false werden einzelne Mails aufgelistet.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connector_key' => ['type' => 'string', 'description' => 'Filter nach Connector: microsoft365.'],
                'status' => ['type' => 'string', 'description' => 'Filter nach Status: new, read.'],
                'direction' => ['type' => 'string', 'description' => 'Filter nach Richtung: inbound, outbound.'],
                'is_read' => ['type' => 'boolean', 'description' => 'Filter nach Lese-Status. Bei Threads: true = alle gelesen, false = mindestens eine ungelesen.'],
                'group_by_thread' => ['type' => 'boolean', 'description' => 'Thread-Gruppierung aktivieren. Standard: true. Bei false werden einzelne Mails aufgelistet.'],
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
            $groupByThread = $arguments['group_by_thread'] ?? true;

            if ($groupByThread) {
                return $this->executeGrouped($arguments, $connectionIds, $limit);
            }

            return $this->executeFlat($arguments, $connectionIds, $limit);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    private function executeGrouped(array $arguments, array $connectionIds, int $limit): ToolResult
    {
        // Subquery: latest mail ID + aggregates per conversation_id
        $threadSubquery = UserConnectorMailSession::query()
            ->selectRaw('
                COALESCE(conversation_id, external_mail_id) as thread_key,
                MAX(id) as latest_mail_id,
                MAX(COALESCE(received_at, sent_at)) as last_activity_at,
                COUNT(*) as message_count,
                SUM(CASE WHEN is_read = false THEN 1 ELSE 0 END) as unread_count,
                MAX(CASE WHEN has_attachments = true THEN 1 ELSE 0 END) as thread_has_attachments
            ')
            ->whereIn('connection_id', $connectionIds);

        // Apply filters to the subquery so aggregates are correct
        $this->applyFilters($threadSubquery, $arguments, 'thread');

        $threadSubquery->groupBy('thread_key');

        // Join with full MailSession for the fields of the latest mail
        $query = UserConnectorMailSession::query()
            ->joinSub($threadSubquery, 'threads', fn ($join) =>
                $join->on('user_connector_mail_sessions.id', '=', 'threads.latest_mail_id')
            )
            ->select('user_connector_mail_sessions.*')
            ->addSelect(
                'threads.message_count',
                'threads.unread_count',
                'threads.last_activity_at',
                'threads.thread_has_attachments'
            )
            ->orderByRaw('CASE WHEN threads.unread_count > 0 THEN 0 ELSE 1 END')
            ->orderByDesc('threads.last_activity_at');

        // is_read filter in thread mode: filter by thread-level unread_count
        if (isset($arguments['is_read'])) {
            if ((bool) $arguments['is_read']) {
                $query->where('threads.unread_count', '=', 0);
            } else {
                $query->where('threads.unread_count', '>', 0);
            }
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
                'is_read' => (int) $session->unread_count === 0,
                'has_attachments' => (bool) $session->thread_has_attachments,
                'is_draft' => $session->is_draft,
                'shared_mailbox' => $session->shared_mailbox,
                'received_at' => $session->received_at?->toIso8601String(),
                'sent_at' => $session->sent_at?->toIso8601String(),
                'message_count' => (int) $session->message_count,
                'unread_count' => (int) $session->unread_count,
                'last_activity_at' => $session->last_activity_at,
                'meta' => $session->meta ?? [],
            ];
        })->all();

        return ToolResult::success([
            'mail_sessions' => $result,
            'total' => count($result),
        ]);
    }

    private function executeFlat(array $arguments, array $connectionIds, int $limit): ToolResult
    {
        $query = UserConnectorMailSession::query()
            ->whereIn('connection_id', $connectionIds)
            ->orderByRaw('CASE WHEN is_read = false THEN 0 ELSE 1 END')
            ->orderByDesc('received_at');

        $this->applyFilters($query, $arguments);

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
    }

    private function applyFilters($query, array $arguments, string $mode = 'flat'): void
    {
        if (!empty($arguments['connector_key'])) {
            $query->where('connector_key', $arguments['connector_key']);
        }

        if (!empty($arguments['status'])) {
            $query->where('status', $arguments['status']);
        }

        if (!empty($arguments['direction'])) {
            $query->where('direction', $arguments['direction']);
        }

        // is_read filter: in thread mode, skip here (handled via HAVING or post-filter)
        // in flat mode, apply directly
        if (isset($arguments['is_read']) && $mode === 'flat') {
            $query->where('is_read', (bool) $arguments['is_read']);
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
