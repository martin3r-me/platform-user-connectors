<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;

class SendTeamsChatTool extends Microsoft365Tool
{
    public function getName(): string
    {
        return 'user-connectors.microsoft365.teams.send';
    }

    public function getDescription(): string
    {
        return 'Sendet eine Nachricht in einen Teams-Chat (1:1 oder Gruppe). chat_id aus teams.chats.list oder teams.chat.create. '
            . 'Body: Plain Text (Zeilenumbrüche bleiben erhalten) oder HTML (<b>, <i>, <ul>/<li>, <pre>, <code>, <br>).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => self::connectionProperty(),
                'chat_id' => ['type' => 'string', 'description' => 'Graph-chat_id (z. B. "19:…@unq.gbl.spaces").'],
                'body' => ['type' => 'string', 'description' => 'Nachrichtentext.'],
                'content_type' => ['type' => 'string', 'enum' => ['auto', 'html', 'text'], 'description' => 'Default auto: ohne HTML-Tags wird Plain Text mit Umbrüchen gesendet, sonst HTML.'],
            ],
            'required' => ['chat_id', 'body'],
        ];
    }

    protected function errorPrefix(): string
    {
        return 'Teams-Senden fehlgeschlagen';
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $chatId = $this->requireString($arguments, 'chat_id');
        $body = $this->requireString($arguments, 'body');

        $result = $this->teams($arguments)->sendChatMessage($context->user, $chatId, $body, (string) ($arguments['content_type'] ?? 'auto'));

        return ToolResult::success(array_filter([
            'status' => 'sent',
            'message_id' => $result['id'] ?? null,
            'chat_id' => $chatId,
            'web_url' => $result['web_url'] ?? null,
        ]));
    }

    public function getMetadata(): array
    {
        return self::writeMetadata(['teams', 'chat', 'message', 'send', 'channel:teams-chat', 'action:send']);
    }
}
