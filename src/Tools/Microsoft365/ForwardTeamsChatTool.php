<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;

class ForwardTeamsChatTool extends Microsoft365Tool
{
    public function getName(): string
    {
        return 'user-connectors.microsoft365.teams.chat.forward';
    }

    public function getDescription(): string
    {
        return 'Leitet eine Teams-Chat-Nachricht in einen anderen Chat weiter (als Zitat, Teams hat keinen nativen Forward), optional mit comment.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => self::connectionProperty(),
                'source_chat_id' => ['type' => 'string'],
                'source_message_id' => ['type' => 'string'],
                'target_chat_id' => ['type' => 'string'],
                'comment' => ['type' => 'string'],
            ],
            'required' => ['source_chat_id', 'source_message_id', 'target_chat_id'],
        ];
    }

    protected function errorPrefix(): string
    {
        return 'Weiterleiten fehlgeschlagen';
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $result = $this->teams($arguments)->forwardChatMessage(
            $context->user,
            $this->requireString($arguments, 'source_chat_id'),
            $this->requireString($arguments, 'source_message_id'),
            $this->requireString($arguments, 'target_chat_id'),
            (string) ($arguments['comment'] ?? ''),
        );

        return ToolResult::success(['status' => 'forwarded', 'message_id' => $result['id'] ?? null]);
    }

    public function getMetadata(): array
    {
        return self::writeMetadata(['teams', 'chat', 'forward']);
    }
}
