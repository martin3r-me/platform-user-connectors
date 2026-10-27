<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;

/**
 * Neuer Channel-Beitrag oder Antwort in einen Thread — ersetzt das frühere
 * teams.channel.reply.
 */
class SendTeamsChannelMessageTool extends Microsoft365Tool
{
    public function getName(): string
    {
        return 'user-connectors.microsoft365.teams.channel.send';
    }

    public function getDescription(): string
    {
        return 'Postet in einen Teams-Channel — als neuer Beitrag oder mit reply_to_message_id als Antwort in einen bestehenden Thread. '
            . 'channel_id = "teamId:channelId" aus teams.channels.list. Body: Plain Text (Umbrüche bleiben) oder HTML.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => self::connectionProperty(),
                'channel_id' => ['type' => 'string', 'description' => '"teamId:channelId".'],
                'body' => ['type' => 'string'],
                'reply_to_message_id' => ['type' => 'string', 'description' => 'Optional. id des Thread-Beitrags, auf den geantwortet wird.'],
                'content_type' => ['type' => 'string', 'enum' => ['auto', 'html', 'text'], 'description' => 'Default auto.'],
            ],
            'required' => ['channel_id', 'body'],
        ];
    }

    protected function errorPrefix(): string
    {
        return 'Channel-Senden fehlgeschlagen';
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $replyTo = trim((string) ($arguments['reply_to_message_id'] ?? $arguments['message_id'] ?? '')) ?: null;

        $result = $this->teams($arguments)->sendChannelMessage(
            $context->user,
            $this->requireString($arguments, 'channel_id'),
            $this->requireString($arguments, 'body'),
            (string) ($arguments['content_type'] ?? 'auto'),
            $replyTo,
        );

        return ToolResult::success(array_filter([
            'status' => 'sent',
            'message_id' => $result['id'] ?? null,
            'reply_to_message_id' => $replyTo,
            'web_url' => $result['web_url'] ?? null,
        ]));
    }

    public function getMetadata(): array
    {
        return self::writeMetadata(['teams', 'channel', 'send', 'reply', 'thread', 'channel:teams-channel', 'action:send']);
    }
}
