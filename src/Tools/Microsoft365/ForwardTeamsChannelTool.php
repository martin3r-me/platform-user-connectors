<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365ApiService;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365TeamsConnector;

class ForwardTeamsChannelTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string { return 'user-connectors.microsoft365.teams.channel.forward'; }

    public function getDescription(): string
    {
        return 'Leitet eine Teams-Channel-Nachricht in einen (anderen) Channel weiter (quote+send).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer'],
                'source_channel_id' => ['type' => 'string'],
                'source_message_id' => ['type' => 'string'],
                'target_channel_id' => ['type' => 'string'],
                'comment' => ['type' => 'string'],
            ],
            'required' => ['source_channel_id', 'source_message_id', 'target_channel_id'],
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
            $result = $connector->forwardChannelMessage(
                $context->user,
                (string) $arguments['source_channel_id'],
                (string) $arguments['source_message_id'],
                (string) $arguments['target_channel_id'],
                (string) ($arguments['comment'] ?? ''),
            );
            return ToolResult::success(['message_id' => $result['id'] ?? null, 'status' => 'forwarded']);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Forward fehlgeschlagen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['category' => 'action', 'tags' => ['microsoft365', 'teams', 'channel', 'forward'],
            'read_only' => false, 'requires_auth' => true, 'risk_level' => 'write',
            'confirmation_required' => true, 'cost_class' => 'external_api_free'];
    }
}
