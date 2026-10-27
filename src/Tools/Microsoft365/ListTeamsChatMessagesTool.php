<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\DTOs\Pagination;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365TeamsConnector;

class ListTeamsChatMessagesTool extends Microsoft365Tool
{
    public function getName(): string
    {
        return 'user-connectors.microsoft365.teams.chat.messages.list';
    }

    public function getDescription(): string
    {
        return 'Liest Nachrichten eines Teams-Chats (neueste zuerst, Body als Klartext). Ältere Nachrichten: next_cursor als cursor übergeben.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => self::connectionProperty(),
                'chat_id' => ['type' => 'string'],
                'per_page' => ['type' => 'integer', 'description' => '1–' . Microsoft365TeamsConnector::MAX_MESSAGES_PER_PAGE . '. Default 25.'],
                'cursor' => self::cursorProperty(),
                'body_format' => ['type' => 'string', 'enum' => ['text', 'html'], 'description' => 'Default text.'],
                'include_system' => ['type' => 'boolean', 'description' => 'Default false: System-Events (Mitglied hinzugefügt, Anruf beendet …) ausblenden.'],
            ],
            'required' => ['chat_id'],
        ];
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $result = $this->teams($arguments)->getChatMessages(
            $context->user,
            $this->requireString($arguments, 'chat_id'),
            new Pagination(perPage: (int) ($arguments['per_page'] ?? 25)),
            [
                'cursor' => $arguments['cursor'] ?? null,
                'body_format' => $arguments['body_format'] ?? 'text',
                'include_system' => (bool) $this->optionalBool($arguments, 'include_system'),
            ],
        );

        return ToolResult::success(array_filter([
            'count' => count($result['messages']),
            'messages' => $result['messages'],
            'next_cursor' => $result['next_cursor'],
        ], fn ($v) => $v !== null));
    }

    public function getMetadata(): array
    {
        return self::readMetadata(['teams', 'chat', 'messages', 'list', 'read'], ['category' => 'inspection']);
    }
}
