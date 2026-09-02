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
        return 'Listet E-Mails aus dem Outlook-Postfach des Users auf. Unterstützt Ordner-Filter (inbox, sentitems, drafts), Volltextsuche (Absender/Betreff/Body via Microsoft Graph), Absender- und Zeitraum-Filter, Pagination und Lese-Status-Filter.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer', 'description' => 'Optionale Connection-ID.'],
                'folder' => ['type' => 'string', 'description' => 'Mail-Ordner: inbox, sentitems, drafts, deleteditems. Standard: alle.'],
                'search' => ['type' => 'string', 'description' => 'Volltextsuche über Absender, Betreff und Body (serverseitig via Microsoft Graph $search). Bei gesetzter Suche entfällt die Sortierung nach Datum — Graph sortiert nach Relevanz.'],
                'from' => ['type' => 'string', 'description' => 'Filter: exakte Absender-E-Mail-Adresse.'],
                'date_from' => ['type' => 'string', 'description' => 'Filter: nur Mails empfangen ab diesem Zeitpunkt (ISO-8601 oder von Carbon parsbares Datum).'],
                'date_to' => ['type' => 'string', 'description' => 'Filter: nur Mails empfangen bis zu diesem Zeitpunkt (ISO-8601 oder von Carbon parsbares Datum).'],
                'is_read' => ['type' => ['string', 'boolean'], 'description' => 'Filter: true/false (als Boolean oder String).'],
                'page' => ['type' => 'integer', 'description' => 'Seite (ab 1). Standard: 1.'],
                'per_page' => ['type' => 'integer', 'description' => 'Einträge pro Seite. Standard: 25.'],
                'limit' => ['type' => 'integer', 'description' => 'Alias für per_page.'],
                'body_format' => [
                    'type' => 'string',
                    'enum' => ['full', 'preview', 'none'],
                    'description' => 'Body-Format: "full" (Standard, kompletter HTML-Body je Mail), "preview" (nur bodyPreview/Kurzform) oder "none" (kein Body-Feld — für reine Trefferlisten, spart am meisten Response-Größe).',
                ],
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
            if (!empty($arguments['search'])) {
                $filters['search'] = $arguments['search'];
            }
            if (!empty($arguments['from'])) {
                $filters['from'] = $arguments['from'];
            }
            if (!empty($arguments['date_from'])) {
                $filters['date_from'] = $arguments['date_from'];
            }
            if (!empty($arguments['date_to'])) {
                $filters['date_to'] = $arguments['date_to'];
            }
            if (isset($arguments['is_read'])) {
                $filters['is_read'] = $arguments['is_read'];
            }
            if (!empty($arguments['body_format'])) {
                $filters['body_format'] = $arguments['body_format'];
            }

            $pagination = new Pagination(
                page: $arguments['page'] ?? 1,
                perPage: $arguments['per_page'] ?? $arguments['limit'] ?? 25,
            );

            $result = $connector->listMessages($context->user, $filters, $pagination);

            return ToolResult::success([
                'messages' => array_map(fn ($m) => $m->toArray(), $result['messages']),
                'pagination' => $result['pagination']->toArray(),
                'filters' => [
                    'folder' => $arguments['folder'] ?? null,
                    'search' => $arguments['search'] ?? null,
                    'from' => $arguments['from'] ?? null,
                    'date_from' => $arguments['date_from'] ?? null,
                    'date_to' => $arguments['date_to'] ?? null,
                    'is_read' => $arguments['is_read'] ?? null,
                    'body_format' => $arguments['body_format'] ?? 'full',
                ],
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['microsoft365', 'outlook', 'mail', 'email', 'list', 'search'],
            'read_only' => true,
            'requires_auth' => true,
            'risk_level' => 'safe',
            'cost_class' => 'external_api_free',
        ];
    }
}
