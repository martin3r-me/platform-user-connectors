<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;

class ListTeamsChatsTool extends Microsoft365Tool
{
    public function getName(): string
    {
        return 'user-connectors.microsoft365.teams.chats.list';
    }

    public function getDescription(): string
    {
        return 'Listet die Teams-Chats des Users (1:1, Gruppe, Meeting), zuletzt aktive zuerst, mit Mitgliedern. '
            . 'search filtert nach Topic oder Mitgliedsname/-mail. Nachrichten lesen: teams.chat.messages.list.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => self::connectionProperty(),
                'search' => ['type' => 'string', 'description' => 'Optional. Teil von Topic, Mitgliedsname oder -mail.'],
                'limit' => ['type' => 'integer', 'description' => 'Chats pro Seite (1–50). Default 50.'],
                'cursor' => self::cursorProperty(),
            ],
        ];
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $result = $this->teams($arguments)->listChats($context->user, [
            'search' => $arguments['search'] ?? null,
            'limit' => $arguments['limit'] ?? 50,
            'cursor' => $arguments['cursor'] ?? null,
        ]);

        return ToolResult::success(array_filter([
            'count' => count($result['chats']),
            'chats' => $result['chats'],
            'next_cursor' => $result['next_cursor'],
        ], fn ($v) => $v !== null));
    }

    public function getMetadata(): array
    {
        return self::readMetadata(['teams', 'chats', 'list', 'channel:teams-chat', 'action:list'], ['category' => 'inspection']);
    }
}
