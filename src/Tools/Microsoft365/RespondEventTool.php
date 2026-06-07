<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365ApiService;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365CalendarConnector;

/**
 * Antwort auf eine Termin-Einladung: accept / decline / tentativelyAccept.
 * Schließt die UX-Lücke „Meeting in Inbox wegklicken = Inbox-Done, aber
 * Outlook bleibt unsicher" — der Termin-Status wird im Kalender korrekt
 * gesetzt.
 */
class RespondEventTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string { return 'user-connectors.microsoft365.calendar.respond'; }

    public function getDescription(): string
    {
        return 'Antwortet auf eine Outlook-Termin-Einladung: response=accept|decline|tentative. '
            . 'Optional Kommentar an den Organisator und send_response (Default true).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer'],
                'event_id' => ['type' => 'string'],
                'response' => ['type' => 'string', 'enum' => ['accept', 'decline', 'tentative']],
                'comment' => ['type' => 'string'],
                'send_response' => ['type' => 'boolean', 'description' => 'Default true.'],
            ],
            'required' => ['event_id', 'response'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('AUTH_ERROR', 'Benutzer nicht authentifiziert.');
        }
        try {
            $connector = app(Microsoft365CalendarConnector::class);
            if (!empty($arguments['connection_id'])) {
                $connector = new Microsoft365CalendarConnector(
                    app(Microsoft365ApiService::class)->forConnection((int) $arguments['connection_id'])
                );
            }
            $ok = $connector->respondToEvent(
                $context->user,
                (string) $arguments['event_id'],
                (string) $arguments['response'],
                isset($arguments['comment']) ? (string) $arguments['comment'] : null,
                (bool) ($arguments['send_response'] ?? true),
            );
            return ToolResult::success(['status' => 'responded', 'response' => $arguments['response']]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Respond fehlgeschlagen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['category' => 'action', 'tags' => ['microsoft365', 'calendar', 'event', 'respond', 'rsvp'],
            'read_only' => false, 'requires_auth' => true, 'risk_level' => 'write',
            'confirmation_required' => true, 'cost_class' => 'external_api_free'];
    }
}
