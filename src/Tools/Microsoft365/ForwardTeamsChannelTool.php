<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;

class ForwardTeamsChannelTool extends Microsoft365Tool
{
    public function getName(): string
    {
        return 'user-connectors.microsoft365.teams.channel.forward';
    }

    public function getDescription(): string
    {
        return 'Leitet einen Teams-Channel-Beitrag in einen (anderen) Channel weiter (als Zitat), optional mit comment.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => self::connectionProperty(),
                'source_channel_id' => ['type' => 'string', 'description' => '"teamId:channelId".'],
                'source_message_id' => ['type' => 'string'],
                'target_channel_id' => ['type' => 'string', 'description' => '"teamId:channelId".'],
                'comment' => ['type' => 'string'],
            ],
            'required' => ['source_channel_id', 'source_message_id', 'target_channel_id'],
        ];
    }

    protected function errorPrefix(): string
    {
        return 'Weiterleiten fehlgeschlagen';
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $result = $this->teams($arguments)->forwardChannelMessage(
            $context->user,
            $this->requireString($arguments, 'source_channel_id'),
            $this->requireString($arguments, 'source_message_id'),
            $this->requireString($arguments, 'target_channel_id'),
            (string) ($arguments['comment'] ?? ''),
        );

        return ToolResult::success(['status' => 'forwarded', 'message_id' => $result['id'] ?? null]);
    }

    public function getMetadata(): array
    {
        return self::writeMetadata(['teams', 'channel', 'forward']);
    }
}
