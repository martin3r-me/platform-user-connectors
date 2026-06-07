<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365ApiService;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365CalendarConnector;

class ListRoomListsTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string { return 'user-connectors.microsoft365.calendar.room_lists.list'; }

    public function getDescription(): string
    {
        return 'Listet alle Raum-Listen (Building/Standort-Container für Räume) des Tenants. '
            . 'Discovery-Schritt für Raum-Booking.';
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
            $connector = app(Microsoft365CalendarConnector::class);
            if (!empty($arguments['connection_id'])) {
                $connector = new Microsoft365CalendarConnector(
                    app(Microsoft365ApiService::class)->forConnection((int) $arguments['connection_id'])
                );
            }
            return ToolResult::success([
                'room_lists' => $connector->listRoomLists($context->user),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Raum-Listen-Abruf fehlgeschlagen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['category' => 'inspection', 'tags' => ['microsoft365', 'calendar', 'rooms', 'room_lists', 'discovery'],
            'read_only' => true, 'requires_auth' => true, 'risk_level' => 'read', 'idempotent' => true,
            'cost_class' => 'external_api_free'];
    }
}
