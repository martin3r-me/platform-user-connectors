<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365ApiService;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365CalendarConnector;

class ListRoomsTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string { return 'user-connectors.microsoft365.calendar.rooms.list'; }

    public function getDescription(): string
    {
        return 'Listet Räume / Kalender-Ressourcen — entweder alle Räume des Tenants, oder eingeschränkt '
            . 'auf eine Raum-Liste via room_list_email. Liefert email/capacity/building/floor — direkt '
            . 'als attendee oder im rooms-Parameter von calendar.create verwendbar.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer'],
                'room_list_email' => [
                    'type' => 'string',
                    'description' => 'Optional. Wenn gesetzt, werden nur die Räume aus dieser Raum-Liste zurückgegeben.',
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
            $connector = app(Microsoft365CalendarConnector::class);
            if (!empty($arguments['connection_id'])) {
                $connector = new Microsoft365CalendarConnector(
                    app(Microsoft365ApiService::class)->forConnection((int) $arguments['connection_id'])
                );
            }
            $roomListEmail = isset($arguments['room_list_email'])
                ? trim((string) $arguments['room_list_email'])
                : null;
            return ToolResult::success([
                'rooms' => $connector->listRooms($context->user, $roomListEmail ?: null),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Raum-Abruf fehlgeschlagen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['category' => 'inspection', 'tags' => ['microsoft365', 'calendar', 'rooms', 'discovery'],
            'read_only' => true, 'requires_auth' => true, 'risk_level' => 'read', 'idempotent' => true,
            'cost_class' => 'external_api_free'];
    }
}
