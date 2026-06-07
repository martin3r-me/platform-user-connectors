<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365ApiService;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365TeamsConnector;

class ListTeamsChannelsTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string { return 'user-connectors.microsoft365.teams.channels.list'; }

    public function getDescription(): string
    {
        return 'Listet alle Channels über alle Teams, in denen der User Mitglied ist. '
            . 'Jeder Channel kommt mit team_id, team_name, channel_id — direkt verwendbar für Channel-Send.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['connection_id' => ['type' => 'integer']]];
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
            return ToolResult::success($connector->listChannels($context->user));
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['category' => 'inspection', 'tags' => ['microsoft365', 'teams', 'channels', 'list'],
            'read_only' => true, 'requires_auth' => true, 'risk_level' => 'read', 'idempotent' => true,
            'cost_class' => 'external_api_free'];
    }
}
