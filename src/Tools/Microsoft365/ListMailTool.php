<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\DTOs\Pagination;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365MailConnector;

class ListMailTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'user-connectors.microsoft365.mail.list';
    }

    public function getDescription(): string
    {
        return 'Listet E-Mails aus dem Outlook-Postfach des Users auf. Unterstützt Ordner-Filter (inbox, sentitems, drafts), Pagination und Lese-Status-Filter.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer', 'description' => 'Optionale Connection-ID.'],
                'folder' => ['type' => 'string', 'description' => 'Mail-Ordner: inbox, sentitems, drafts, deleteditems. Standard: alle.'],
                'is_read' => ['type' => 'string', 'description' => 'Filter: "true" oder "false".'],
                'page' => ['type' => 'integer', 'description' => 'Seite (ab 1). Standard: 1.'],
                'per_page' => ['type' => 'integer', 'description' => 'Einträge pro Seite. Standard: 25.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('AUTH_ERROR', 'Benutzer nicht authentifiziert.');
        }

        try {
            $connector = app(Microsoft365MailConnector::class);
            if (!empty($arguments['connection_id'])) {
                $connector = new Microsoft365MailConnector(
                    app(\Platform\UserConnectors\Services\Microsoft365\Microsoft365ApiService::class)
                        ->forConnection($arguments['connection_id'])
                );
            }

            $filters = [];
            if (!empty($arguments['folder'])) {
                $filters['folder'] = $arguments['folder'];
            }
            if (isset($arguments['is_read'])) {
                $filters['is_read'] = $arguments['is_read'];
            }

            $pagination = new Pagination(
                page: $arguments['page'] ?? 1,
                perPage: $arguments['per_page'] ?? 25,
            );

            $result = $connector->listMessages($context->user, $filters, $pagination);

            return ToolResult::success([
                'messages' => array_map(fn ($m) => $m->toArray(), $result['messages']),
                'pagination' => $result['pagination']->toArray(),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['microsoft365', 'outlook', 'mail', 'email', 'list'],
            'read_only' => true,
            'requires_auth' => true,
            'risk_level' => 'safe',
            'cost_class' => 'external_api_free',
        ];
    }
}
