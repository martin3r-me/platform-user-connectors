<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;

class RespondEventTool extends Microsoft365Tool
{
    public function getName(): string
    {
        return 'user-connectors.microsoft365.calendar.respond';
    }

    public function getDescription(): string
    {
        return 'Beantwortet eine Termin-Einladung: accept (zusagen), decline (absagen) oder tentative (mit Vorbehalt), '
            . 'optional mit Kommentar an den Organisator.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => self::connectionProperty(),
                'event_id' => ['type' => 'string', 'description' => 'Termin-ID der Einladung.'],
                'response' => ['type' => 'string', 'enum' => ['accept', 'decline', 'tentative']],
                'comment' => ['type' => 'string', 'description' => 'Optional. Nachricht an den Organisator.'],
                'send_response' => ['type' => 'boolean', 'description' => 'Default true. false = Antwort nur im eigenen Kalender, Organisator wird nicht benachrichtigt.'],
            ],
            'required' => ['event_id', 'response'],
        ];
    }

    protected function errorPrefix(): string
    {
        return 'Antwort fehlgeschlagen';
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $this->calendar($arguments)->respondToEvent(
            $context->user,
            $this->requireString($arguments, 'event_id'),
            $this->requireString($arguments, 'response'),
            isset($arguments['comment']) ? (string) $arguments['comment'] : null,
            $this->optionalBool($arguments, 'send_response') ?? true,
        );

        return ToolResult::success(['status' => 'responded', 'response' => $arguments['response']]);
    }

    public function getMetadata(): array
    {
        return self::writeMetadata(['outlook', 'calendar', 'event', 'respond', 'rsvp', 'invitation']);
    }
}
