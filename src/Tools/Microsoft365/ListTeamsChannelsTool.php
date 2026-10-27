<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;

/**
 * Teams + Channels in einem Tool — ersetzt das frühere teams.list.
 */
class ListTeamsChannelsTool extends Microsoft365Tool
{
    public function getName(): string
    {
        return 'user-connectors.microsoft365.teams.channels.list';
    }

    public function getDescription(): string
    {
        return 'Listet die Microsoft Teams des Users und deren Channels. Die id jedes Channels ("teamId:channelId") '
            . 'direkt für teams.channel.send / teams.channel.messages.list verwenden. '
            . 'include_last_message=true ergänzt last_message_at als Aktivitäts-Proxy (Graph hat für Channels keinen Ungelesen-Status; '
            . 'kostet einen Abruf pro Channel — mit team_id eingrenzen).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => self::connectionProperty(),
                'team_id' => ['type' => 'string', 'description' => 'Optional. Nur Channels dieses Teams.'],
                'include_last_message' => ['type' => 'boolean', 'description' => 'Default false.'],
            ],
        ];
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $result = $this->teams($arguments)->listChannels(
            $context->user,
            trim((string) ($arguments['team_id'] ?? '')) ?: null,
            (bool) $this->optionalBool($arguments, 'include_last_message'),
        );

        return ToolResult::success(array_filter([
            'teams' => $result['teams'] ?: null,
            'channels' => $result['channels'],
        ], fn ($v) => $v !== null));
    }

    public function getMetadata(): array
    {
        return self::readMetadata(['teams', 'channels', 'list', 'channel:teams-channel', 'action:list'], ['category' => 'inspection']);
    }
}
