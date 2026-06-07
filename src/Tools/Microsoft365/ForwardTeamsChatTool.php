<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365ApiService;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365TeamsConnector;

/**
 * Teams hat keinen nativen Chat-Forward — wir senden den Original-Inhalt
 * als Quote in den Ziel-Chat, mit optionalem Kommentar darüber.
 */
class ForwardTeamsChatTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string { return 'user-connectors.microsoft365.teams.chat.forward'; }

    public function getDescription(): string
    {
        return 'Leitet eine Teams-Chat-Nachricht in einen anderen Chat weiter (quote+send). '
            . 'Erwartet source_chat_id, source_message_id, target_chat_id.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer'],
                'source_chat_id' => ['type' => 'string'],
                'source_message_id' => ['type' => 'string'],
                'target_chat_id' => ['type' => 'string'],
                'comment' => ['type' => 'string'],
            ],
            'required' => ['source_chat_id', 'source_message_id', 'target_chat_id'],
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
            $result = $connector->forwardChatMessage(
                $context->user,
                (string) $arguments['source_chat_id'],
                (string) $arguments['source_message_id'],
                (string) $arguments['target_chat_id'],
                (string) ($arguments['comment'] ?? ''),
            );
            return ToolResult::success(['message_id' => $result['id'] ?? null, 'status' => 'forwarded']);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Forward fehlgeschlagen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['category' => 'action', 'tags' => ['microsoft365', 'teams', 'chat', 'forward'],
            'read_only' => false, 'requires_auth' => true, 'risk_level' => 'write',
            'confirmation_required' => true, 'cost_class' => 'external_api_free'];
    }
}
