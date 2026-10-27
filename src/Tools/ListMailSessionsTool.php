<?php

namespace Platform\UserConnectors\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Models\UserConnectorMailSession;

class ListMailSessionsTool extends ConnectorTool
{
    private const DATE = 'COALESCE(user_connector_mail_sessions.received_at, user_connector_mail_sessions.sent_at)';

    public function getName(): string
    {
        return 'user-connectors.mail-sessions.list';
    }

    public function getDescription(): string
    {
        return 'Durchsucht die LOKAL gespeicherte Mail-Historie (per Webhook synchronisiert) — standardmäßig nach Threads gruppiert '
            . '(neueste Mail je Thread mit message_count/unread_count, ungelesene zuerst). Schnell, auch über geteilte Postfächer, '
            . 'mit conversation_id alle Mails eines Threads. Für den aktuellen Live-Stand oder vollen Mail-Text: '
            . 'user-connectors.microsoft365.mail.list.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => self::listProperties(['microsoft365'], 'Empfangen/gesendet') + [
                'search' => ['type' => 'string', 'description' => 'Volltext über Betreff, Text und Absender.'],
                'from' => ['type' => 'string', 'description' => 'Teil von Absender-Adresse oder -Name.'],
                'conversation_id' => ['type' => 'string', 'description' => 'Alle Mails dieses Threads (setzt group_by_thread=false).'],
                'direction' => ['type' => 'string', 'enum' => ['inbound', 'outbound']],
                'status' => ['type' => 'string', 'description' => 'new, read …'],
                'is_read' => ['type' => 'boolean', 'description' => 'Bei Threads: false = mind. eine ungelesen, true = alle gelesen.'],
                'has_attachments' => ['type' => 'boolean'],
                'group_by_thread' => ['type' => 'boolean', 'description' => 'Default true.'],
            ],
        ];
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $connectionIds = $this->scopedConnectionIds($context->user, $arguments, ['microsoft365']);
        if ($connectionIds === []) {
            return ToolResult::success(['mail_sessions' => [], 'count' => 0, 'total' => 0]);
        }

        $grouped = ($this->optionalBool($arguments, 'group_by_thread') ?? true) && empty($arguments['conversation_id']);

        return ToolResult::success($grouped
            ? $this->grouped($arguments, $connectionIds)
            : $this->flat($arguments, $connectionIds));
    }

    private function grouped(array $arguments, array $connectionIds): array
    {
        $threads = UserConnectorMailSession::query()
            ->selectRaw('
                COALESCE(conversation_id, external_mail_id) as thread_key,
                MAX(id) as latest_mail_id,
                MAX(' . self::DATE . ') as last_activity_at,
                COUNT(*) as message_count,
                SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread_count,
                MAX(CASE WHEN has_attachments = 1 THEN 1 ELSE 0 END) as thread_has_attachments
            ')
            ->whereIn('connection_id', $connectionIds);
        $this->applyFilters($threads, $arguments, false);
        $threads->groupBy('thread_key');

        $query = UserConnectorMailSession::query()
            ->joinSub($threads, 'threads', fn ($join) => $join->on('user_connector_mail_sessions.id', '=', 'threads.latest_mail_id'))
            ->select('user_connector_mail_sessions.*')
            ->addSelect('threads.message_count', 'threads.unread_count', 'threads.last_activity_at', 'threads.thread_has_attachments')
            ->orderByRaw('CASE WHEN threads.unread_count > 0 THEN 0 ELSE 1 END')
            ->orderByDesc('threads.last_activity_at');

        $isRead = $this->optionalBool($arguments, 'is_read');
        if ($isRead !== null) {
            $query->where('threads.unread_count', $isRead ? '=' : '>', 0);
        }

        return $this->paginate($query, $arguments, 'mail_sessions', fn (UserConnectorMailSession $s) => $this->present($s) + [
            'is_read' => (int) $s->unread_count === 0,
            'has_attachments' => (bool) $s->thread_has_attachments,
            'message_count' => (int) $s->message_count,
            'unread_count' => (int) $s->unread_count,
            'last_activity_at' => $s->last_activity_at,
        ]);
    }

    private function flat(array $arguments, array $connectionIds): array
    {
        $query = UserConnectorMailSession::query()->whereIn('connection_id', $connectionIds);
        $this->applyFilters($query, $arguments, true);
        $query->orderByRaw(self::DATE . (empty($arguments['conversation_id']) ? ' DESC' : ' ASC'));

        return $this->paginate($query, $arguments, 'mail_sessions', fn (UserConnectorMailSession $s) => $this->present($s) + [
            'is_read' => (bool) $s->is_read,
            'has_attachments' => (bool) $s->has_attachments,
        ]);
    }

    private function applyFilters($query, array $arguments, bool $flat): void
    {
        foreach (['status', 'direction', 'conversation_id'] as $column) {
            if (!empty($arguments[$column])) {
                $query->where($column, (string) $arguments[$column]);
            }
        }
        if (!empty($arguments['search'])) {
            $this->whereLike($query, ['subject', 'body_preview', 'body', 'from_address', 'from_name'], (string) $arguments['search']);
        }
        if (!empty($arguments['from'])) {
            $this->whereLike($query, ['from_address', 'from_name'], (string) $arguments['from']);
        }

        [$from, $to] = $this->dateRange($arguments);
        if ($from) {
            $query->whereRaw(self::DATE . ' >= ?', [$from->format('Y-m-d H:i:s')]);
        }
        if ($to) {
            $query->whereRaw(self::DATE . ' <= ?', [$to->format('Y-m-d H:i:s')]);
        }

        $hasAttachments = $this->optionalBool($arguments, 'has_attachments');
        if ($hasAttachments !== null) {
            $query->where('has_attachments', $hasAttachments);
        }
        $isRead = $this->optionalBool($arguments, 'is_read');
        if ($flat && $isRead !== null) {
            $query->where('is_read', $isRead);
        }
    }

    private function present(UserConnectorMailSession $s): array
    {
        return array_filter([
            'id' => $s->id,
            'connection_id' => $s->connection_id,
            'external_mail_id' => $s->external_mail_id,
            'conversation_id' => $s->conversation_id,
            'direction' => $s->direction,
            'status' => $s->status,
            'from_address' => $s->from_address,
            'from_name' => $s->from_name,
            'to_addresses' => $s->to_addresses,
            'cc_addresses' => $s->cc_addresses,
            'subject' => $s->subject,
            'body_preview' => $s->body_preview,
            'is_draft' => $s->is_draft ?: null,
            'shared_mailbox' => $s->shared_mailbox,
            'received_at' => $s->received_at?->toIso8601String(),
            'sent_at' => $s->sent_at?->toIso8601String(),
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    public function getMetadata(): array
    {
        return self::readMetadata(['mail-sessions', 'email', 'mail', 'threads', 'microsoft365', 'search', 'history']);
    }
}
