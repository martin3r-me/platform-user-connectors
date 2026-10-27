<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;

class CreateTeamsChatTool extends Microsoft365Tool
{
    public function getName(): string
    {
        return 'user-connectors.microsoft365.teams.chat.create';
    }

    public function getDescription(): string
    {
        return 'Legt einen Teams-Chat an oder findet den bestehenden: ein Mitglied → 1:1-Chat (existiert er schon, kommt dessen chat_id), '
            . 'mehrere → Gruppen-Chat mit optionalem topic. Mitglieder per member_emails (werden aufgelöst) oder member_user_ids. '
            . 'Danach mit teams.send schreiben.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => self::connectionProperty(),
                'member_emails' => ['type' => ['array', 'string'], 'items' => ['type' => 'string'], 'description' => 'E-Mail-Adressen der weiteren Mitglieder.'],
                'member_user_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Alternativ: Graph-User-IDs.'],
                'topic' => ['type' => 'string', 'description' => 'Optional. Nur für Gruppen-Chats.'],
            ],
        ];
    }

    protected function errorPrefix(): string
    {
        return 'Chat-Anlage fehlgeschlagen';
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $connector = $this->teams($arguments);
        $ids = $this->stringList($arguments['member_user_ids'] ?? []);
        $emails = $this->stringList($arguments['member_emails'] ?? []);

        $unresolved = [];
        if ($emails !== []) {
            $result = $connector->resolveUserIds($context->user, $emails);
            $ids = array_merge($ids, array_values($result['resolved']));
            $unresolved = $result['unresolved'];
        }
        if ($ids === []) {
            throw new \InvalidArgumentException($unresolved !== []
                ? 'Keine Adresse auflösbar: ' . implode('; ', array_map(fn ($u) => "{$u['email']} ({$u['reason']})", $unresolved))
                : 'Mindestens ein Mitglied (member_emails oder member_user_ids) ist erforderlich.');
        }

        $chat = $connector->createChat($context->user, $ids, isset($arguments['topic']) ? (string) $arguments['topic'] : null);

        return ToolResult::success(array_filter([
            'chat_id' => $chat['id'],
            'chat_type' => $chat['chat_type'],
            'topic' => $chat['topic'],
            'member_count' => $chat['member_count'],
            'web_url' => $chat['web_url'] ?? null,
            'unresolved_emails' => $unresolved ?: null,
        ], fn ($v) => $v !== null));
    }

    public function getMetadata(): array
    {
        return self::writeMetadata(['teams', 'chat', 'create', 'group']);
    }
}
