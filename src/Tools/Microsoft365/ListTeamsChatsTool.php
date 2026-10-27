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
        return 'Listet die Teams-Chats des Users (1:1, Gruppe, Meeting), zuletzt aktive zuerst, mit Mitgliedern, Vorschau der letzten Nachricht '
            . 'und unread (ungelesen seit dem letzten Lesen). unread_only=true für "Was ist neu?"; search filtert nach Topic oder Mitglied. '
            . 'Nachrichten lesen: teams.chat.messages.list.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => self::connectionProperty(),
                'search' => ['type' => 'string', 'description' => 'Optional. Teil von Topic, Mitgliedsname oder -mail.'],
                'limit' => ['type' => 'integer', 'description' => 'Chats pro Seite (1–50). Default 20.'],
                'unread_only' => ['type' => 'boolean', 'description' => 'Nur Chats mit ungelesenen Nachrichten (innerhalb der geladenen Seite).'],
                'include_members' => ['type' => 'boolean', 'description' => 'Default true. false spart einen Abruf.'],
                'include_hidden' => ['type' => 'boolean', 'description' => 'Default false: in Teams ausgeblendete Chats weglassen.'],
                'cursor' => self::cursorProperty(),
            ],
        ];
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $result = $this->teams($arguments)->listChats($context->user, [
            'search' => $arguments['search'] ?? null,
            'limit' => $arguments['limit'] ?? 20,
            'cursor' => $arguments['cursor'] ?? null,
            'unread_only' => (bool) $this->optionalBool($arguments, 'unread_only'),
            'include_members' => $this->optionalBool($arguments, 'include_members') ?? true,
            'include_hidden' => (bool) $this->optionalBool($arguments, 'include_hidden'),
        ]);

        return ToolResult::success(array_filter([
            'count' => count($result['chats']),
            'chats' => $result['chats'],
            'next_cursor' => $result['next_cursor'],
            'sort_warning' => $result['sort_warning'] ?? null,
        ], fn ($v) => $v !== null));
    }

    public function getMetadata(): array
    {
        return self::readMetadata(['teams', 'chats', 'list', 'channel:teams-chat', 'action:list'], ['category' => 'inspection']);
    }
}
