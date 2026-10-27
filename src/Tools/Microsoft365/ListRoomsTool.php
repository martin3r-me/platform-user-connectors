<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;

/**
 * Räume und Raum-Listen (Gebäude/Standorte) in einem Tool — ersetzt das
 * frühere calendar.room_lists.list.
 */
class ListRoomsTool extends Microsoft365Tool
{
    public function getName(): string
    {
        return 'user-connectors.microsoft365.calendar.rooms.list';
    }

    public function getDescription(): string
    {
        return 'Listet buchbare Besprechungsräume (E-Mail, Kapazität, Gebäude, Etage) und die Raum-Listen (Gebäude/Standorte). '
            . 'Mit room_list_email nur die Räume dieser Liste. Raum-E-Mails als rooms[] in calendar.create nutzen; '
            . 'Belegung prüfen mit calendar.find_availability (mode=schedule).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => self::connectionProperty(),
                'room_list_email' => ['type' => 'string', 'description' => 'Optional. Nur Räume dieser Raum-Liste.'],
                'min_capacity' => ['type' => 'integer', 'description' => 'Optional. Nur Räume mit mindestens so vielen Plätzen.'],
            ],
        ];
    }

    protected function errorPrefix(): string
    {
        return 'Raum-Abruf fehlgeschlagen';
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $connector = $this->calendar($arguments);
        $roomListEmail = trim((string) ($arguments['room_list_email'] ?? '')) ?: null;

        $rooms = $connector->listRooms($context->user, $roomListEmail);
        if (!empty($arguments['min_capacity'])) {
            $min = (int) $arguments['min_capacity'];
            $rooms = array_values(array_filter($rooms, fn ($r) => ($r['capacity'] ?? 0) >= $min));
        }

        return ToolResult::success(array_filter([
            'rooms' => $rooms,
            'room_lists' => $roomListEmail === null ? $connector->listRoomLists($context->user) : null,
            'filters' => ['room_list_email' => $roomListEmail, 'min_capacity' => $arguments['min_capacity'] ?? null],
        ], fn ($v) => $v !== null));
    }

    public function getMetadata(): array
    {
        return self::readMetadata(['outlook', 'calendar', 'rooms', 'room_lists', 'räume', 'discovery'], ['category' => 'inspection']);
    }
}
