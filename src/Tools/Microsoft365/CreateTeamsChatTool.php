<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365ApiService;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365TeamsConnector;

/**
 * Erstellt einen neuen Teams-Chat (1:1 oder Gruppe). Mitglieder können
 * per Graph-User-IDs oder per E-Mail-Adresse übergeben werden — Emails
 * werden intern via /users/{email} auf Graph-User-IDs aufgelöst. Der
 * eingeloggte User wird automatisch als Member ergänzt (Graph fügt
 * den Caller nicht implicit hinzu).
 */
class CreateTeamsChatTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string { return 'user-connectors.microsoft365.teams.chat.create'; }

    public function getDescription(): string
    {
        return 'Legt einen Teams-Chat an. Bei genau einem zusätzlichen Mitglied → 1:1-Chat, '
            . 'bei mehreren → Gruppen-Chat mit optionalem Topic. Mitglieder via member_user_ids[] '
            . '(Graph IDs) oder member_emails[] (werden serverseitig aufgelöst).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer'],
                'topic' => ['type' => 'string', 'description' => 'Optional. Nur für Gruppen-Chats relevant.'],
                'member_user_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Graph User-IDs der zusätzlichen Mitglieder.',
                ],
                'member_emails' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'E-Mail-Adressen — werden via /users/{email} auf User-IDs aufgelöst.',
                ],
            ],
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

            $ids = array_values(array_filter(array_map('strval', (array) ($arguments['member_user_ids'] ?? []))));
            $emails = array_values(array_filter(array_map('strval', (array) ($arguments['member_emails'] ?? []))));

            $unresolved = [];
            if (!empty($emails)) {
                $result = $connector->resolveUserIds($context->user, $emails);
                $ids = array_merge($ids, array_values($result['resolved']));
                $unresolved = $result['unresolved'];
            }

            if (empty($ids)) {
                if (!empty($unresolved)) {
                    return ToolResult::error(
                        'VALIDATION_ERROR',
                        'Keine der übergebenen E-Mail-Adressen konnte zu einem User aufgelöst werden: '
                            . implode('; ', array_map(fn ($u) => "{$u['email']} ({$u['reason']})", $unresolved))
                            . '. Alternativ member_user_ids[] direkt verwenden.'
                    );
                }
                return ToolResult::error('VALIDATION_ERROR', 'Mindestens ein Mitglied (member_user_ids oder member_emails) ist erforderlich.');
            }

            $chat = $connector->createChat(
                $context->user,
                $ids,
                isset($arguments['topic']) ? (string) $arguments['topic'] : null,
            );

            return ToolResult::success([
                'chat_id' => $chat['id'],
                'chat_type' => $chat['chat_type'],
                'topic' => $chat['topic'],
                'member_count' => $chat['member_count'],
                'unresolved_emails' => $unresolved,
                'message' => 'Chat erstellt — chat_id für teams.send verwendbar.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Chat-Anlage fehlgeschlagen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['microsoft365', 'teams', 'chat', 'create', 'group'],
            'read_only' => false,
            'requires_auth' => true,
            'risk_level' => 'write',
            'confirmation_required' => true,
            'cost_class' => 'external_api_free',
        ];
    }
}
