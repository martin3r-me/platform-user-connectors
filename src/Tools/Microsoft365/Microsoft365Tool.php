<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\UserConnectors\Exceptions\ConnectorException;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365ApiService;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365CalendarConnector;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365MailConnector;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365TeamsConnector;
use Platform\UserConnectors\Tools\ConnectorTool;

/**
 * Basis der Microsoft-365-Tools: Graph-Connectoren für die gewählte
 * Connection und Schema-Bausteine für Mail/Kalender/Teams.
 */
abstract class Microsoft365Tool extends ConnectorTool
{
    protected function errorCodeFor(ConnectorException $e): string
    {
        $code = parent::errorCodeFor($e);

        return $code === 'API_ERROR' ? 'GRAPH_ERROR' : $code;
    }

    protected function api(array $arguments): Microsoft365ApiService
    {
        $connectionId = $arguments['connection_id'] ?? null;

        return app(Microsoft365ApiService::class)->forConnection(
            $connectionId !== null && $connectionId !== '' ? (int) $connectionId : null
        );
    }

    protected function mail(array $arguments): Microsoft365MailConnector
    {
        return new Microsoft365MailConnector($this->api($arguments));
    }

    protected function calendar(array $arguments): Microsoft365CalendarConnector
    {
        return new Microsoft365CalendarConnector($this->api($arguments));
    }

    protected function teams(array $arguments): Microsoft365TeamsConnector
    {
        return new Microsoft365TeamsConnector($this->api($arguments));
    }

    // ── Schema-Bausteine ────────────────────────────────────────────────

    protected static function timezoneProperty(): array
    {
        return [
            'type' => 'string',
            'description' => 'Optional. IANA-Zeitzone (z. B. Europe/Berlin). Default: '
                . 'Plattform-Zeitzone. Zeitangaben ohne Offset werden in dieser Zone gelesen, Ausgaben erfolgen in ihr.',
        ];
    }

    protected static function cursorProperty(): array
    {
        return [
            'type' => 'string',
            'description' => 'Optional. next_cursor aus der vorherigen Antwort, um die nächste Seite zu laden (andere Filter werden dann ignoriert).',
        ];
    }

    protected static function addressProperty(string $description): array
    {
        return [
            'type' => ['array', 'string'],
            'items' => ['type' => 'string'],
            'description' => $description . ' Array oder komma-/semikolongetrennt.',
        ];
    }

    protected static function attachmentsProperty(): array
    {
        return [
            'type' => 'array',
            'description' => 'Optional. Dateianhänge (je max. ~3 MB).',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'content_base64' => ['type' => 'string'],
                    'content_type' => ['type' => 'string'],
                ],
                'required' => ['name', 'content_base64'],
            ],
        ];
    }

    protected function attachmentsArgument(array $arguments): array
    {
        return array_values(array_filter((array) ($arguments['attachments'] ?? []), 'is_array'));
    }

    protected static function writeMetadata(array $tags, array $extra = []): array
    {
        return parent::writeMetadata(array_merge(['microsoft365'], $tags), $extra);
    }

    protected static function readMetadata(array $tags, array $extra = []): array
    {
        return parent::readMetadata(array_merge(['microsoft365'], $tags), array_merge(['cost_class' => 'external_api_free'], $extra));
    }
}
