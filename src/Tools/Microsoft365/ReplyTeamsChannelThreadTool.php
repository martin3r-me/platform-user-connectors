<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365ApiService;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365TeamsConnector;

class ReplyTeamsChannelThreadTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string { return 'user-connectors.microsoft365.teams.channel.reply'; }

    public function getDescription(): string
    {
        return 'Antwortet in einen Teams-Channel-Thread (reply auf bestehende Channel-Nachricht). '
            . 'Erwartet channel_id ("teamId:channelId"), message_id (der Thread-Root) und body.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer'],
                'channel_id' => ['type' => 'string'],
                'message_id' => ['type' => 'string'],
                'body' => ['type' => 'string'],
                'content_type' => ['type' => 'string', 'enum' => ['html', 'text']],
            ],
            'required' => ['channel_id', 'message_id', 'body'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('AUTH_ERROR', 'Benutzer nicht authentifiziert.');
        }
        try {
            $connector = app(Microsoft365TeamsConnector::class);
            if (!empty($arguments['connection_id'])) {
                $connector = new Microsoft365TeamsConnector(
                    app(Microsoft365ApiService::class)->forConnection((int) $arguments['connection_id'])
                );
            }
            $result = $connector->replyToChannelMessage(
                $context->user,
                (string) $arguments['channel_id'],
                (string) $arguments['message_id'],
                (string) $arguments['body'],
                (string) ($arguments['content_type'] ?? 'html'),
            );
            return ToolResult::success(['message_id' => $result['id'] ?? null, 'status' => 'sent']);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Reply fehlgeschlagen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['category' => 'action', 'tags' => ['microsoft365', 'teams', 'channel', 'reply'],
            'read_only' => false, 'requires_auth' => true, 'risk_level' => 'write',
            'confirmation_required' => true, 'cost_class' => 'external_api_free'];
    }
}
