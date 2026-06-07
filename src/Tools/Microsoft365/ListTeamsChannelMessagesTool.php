<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\DTOs\Pagination;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365ApiService;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365TeamsConnector;

class ListTeamsChannelMessagesTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string { return 'user-connectors.microsoft365.teams.channel.messages.list'; }

    public function getDescription(): string
    {
        return 'Listet Nachrichten in einem Teams-Channel. Erwartet channel_id im Format "teamId:channelId".';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer'],
                'channel_id' => ['type' => 'string', 'description' => 'Format "teamId:channelId".'],
                'per_page' => ['type' => 'integer', 'description' => 'Default 25.'],
            ],
            'required' => ['channel_id'],
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
            $pagination = new Pagination(page: 1, perPage: (int) ($arguments['per_page'] ?? 25));
            $result = $connector->getChannelMessages($context->user, (string) $arguments['channel_id'], $pagination);
            return ToolResult::success($result);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['category' => 'inspection', 'tags' => ['microsoft365', 'teams', 'channel', 'messages', 'list'],
            'read_only' => true, 'requires_auth' => true, 'risk_level' => 'read', 'idempotent' => true,
            'cost_class' => 'external_api_free'];
    }
}
