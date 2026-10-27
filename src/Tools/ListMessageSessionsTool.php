<?php

namespace Platform\UserConnectors\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Models\UserConnectorMessageSession;

class ListMessageSessionsTool extends ConnectorTool
{
    private const KEYS = ['microsoft365', 'sipgate', 'ringcentral', 'vodafone'];

    public function getName(): string
    {
        return 'user-connectors.message-sessions.list';
    }

    public function getDescription(): string
    {
        return 'Lokal gespeicherte Kurznachrichten — Teams-Chats und SMS — neueste zuerst. Filter: Typ, Richtung, chat_id, Absender, Text, Zeitraum. '
            . 'Live lesen: microsoft365.teams.chat.messages.list bzw. telephony.sms.list.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => self::listProperties(self::KEYS, 'Gesendet') + [
                'message_type' => ['type' => 'string', 'enum' => ['teams_chat', 'sms']],
                'direction' => ['type' => 'string', 'enum' => ['inbound', 'outbound']],
                'chat_id' => ['type' => 'string', 'description' => 'Nur Nachrichten dieses Teams-Chats.'],
                'from' => ['type' => 'string', 'description' => 'Teil von Absender (Name/Nummer).'],
                'search' => ['type' => 'string', 'description' => 'Teil des Nachrichtentexts.'],
            ],
        ];
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $connectionIds = $this->scopedConnectionIds($context->user, $arguments, self::KEYS);

        $query = UserConnectorMessageSession::query()
            ->whereIn('connection_id', $connectionIds)
            ->orderByDesc('sent_at');

        foreach (['message_type', 'direction', 'chat_id'] as $column) {
            if (!empty($arguments[$column])) {
                $query->where($column, (string) $arguments[$column]);
            }
        }
        if (!empty($arguments['from'])) {
            $this->whereLike($query, ['from_identifier'], (string) $arguments['from']);
        }
        if (!empty($arguments['search'])) {
            $this->whereLike($query, ['body_preview'], (string) $arguments['search']);
        }
        [$from, $to] = $this->dateRange($arguments);
        if ($from) {
            $query->where('sent_at', '>=', $from);
        }
        if ($to) {
            $query->where('sent_at', '<=', $to);
        }

        return ToolResult::success($this->paginate($query, $arguments, 'message_sessions', fn (UserConnectorMessageSession $s) => array_filter([
            'id' => $s->id,
            'connection_id' => $s->connection_id,
            'connector_key' => $s->connector_key,
            'external_message_id' => $s->external_message_id,
            'message_type' => $s->message_type,
            'direction' => $s->direction,
            'from_identifier' => $s->from_identifier,
            'from_user_id' => $s->from_user_id,
            'to_identifier' => $s->to_identifier,
            'body_preview' => $s->body_preview,
            'chat_id' => $s->chat_id,
            'importance' => $s->importance,
            'sent_at' => $s->sent_at?->toIso8601String(),
        ], fn ($v) => $v !== null && $v !== '')));
    }

    public function getMetadata(): array
    {
        return self::readMetadata(['message-sessions', 'teams', 'chat', 'sms', 'history', 'microsoft365', 'sipgate', 'ringcentral']);
    }
}
