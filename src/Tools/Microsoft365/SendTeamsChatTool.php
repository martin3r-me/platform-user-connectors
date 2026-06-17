<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365ApiService;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365TeamsConnector;

/**
 * Sendet eine Teams-Chat-Nachricht (1:1 oder Gruppe) via MS Graph
 * POST /chats/{chatId}/messages. Nutzt den User-OAuth-Token der
 * Connection — keine App-Permissions, kein Umweg über core.
 */
class SendTeamsChatTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'user-connectors.microsoft365.teams.send';
    }

    public function getDescription(): string
    {
        return 'Sendet eine Microsoft-Teams-Chat-Nachricht (1:1 oder Gruppe) über den User-OAuth-Token. '
            . 'Erwartet die Graph-chat_id (i.d.R. aus user_connector_message_sessions.chat_id). '
            . 'body wird per Default als HTML interpretiert und in Teams als Rich Text gerendert '
            . '(<b>, <i>, <ul>/<li>, <pre>, <code>, <br>, <h3>). Für Plain-Text content_type="text" setzen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer', 'description' => 'Optionale Connection-ID.'],
                'chat_id' => ['type' => 'string', 'description' => 'Graph chat_id (z.B. "19:...@unq.gbl.spaces").'],
                'body' => ['type' => 'string', 'description' => 'Nachrichtentext. Wird per Default als HTML gerendert — Plain-Text-Newlines (\n) werden in Teams kollabiert, also entweder HTML-Tags nutzen oder content_type="text" setzen.'],
                'content_type' => ['type' => 'string', 'enum' => ['html', 'text'], 'description' => 'Default: html. "text" nur wenn Plain-Text gewünscht ist (selten der Fall — Teams rendert dann ohne Formatierung).'],
            ],
            'required' => ['chat_id', 'body'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('AUTH_ERROR', 'Benutzer nicht authentifiziert.');
        }

        $chatId = trim((string) ($arguments['chat_id'] ?? ''));
        $body = (string) ($arguments['body'] ?? '');
        $contentType = (string) ($arguments['content_type'] ?? 'html');

        if ($chatId === '' || trim($body) === '') {
            return ToolResult::error('VALIDATION_ERROR', 'chat_id und body sind erforderlich.');
        }

        try {
            $connector = app(Microsoft365TeamsConnector::class);
            if (!empty($arguments['connection_id'])) {
                $connector = new Microsoft365TeamsConnector(
                    app(Microsoft365ApiService::class)->forConnection((int) $arguments['connection_id'])
                );
            }

            $result = $connector->sendChatMessage($context->user, $chatId, $body, $contentType);

            return ToolResult::success([
                'message_id' => $result['id'] ?? null,
                'status' => $result['status'] ?? 'sent',
                'chat_id' => $chatId,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Teams-Send fehlgeschlagen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['microsoft365', 'teams', 'chat', 'message', 'send'],
            'read_only' => false,
            'requires_auth' => true,
            'risk_level' => 'write',
            'confirmation_required' => true,
            'cost_class' => 'external_api_free',
        ];
    }
}
