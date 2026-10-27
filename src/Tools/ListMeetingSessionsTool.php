<?php

namespace Platform\UserConnectors\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Models\UserConnectorMeetingSession;

class ListMeetingSessionsTool extends ConnectorTool
{
    public function getName(): string
    {
        return 'user-connectors.meeting-sessions.list';
    }

    public function getDescription(): string
    {
        return 'Lokal gespeicherte Termine/Meetings (per Webhook synchronisiert) mit Organisator, Teilnehmern, Ort, Teams-Link. '
            . 'Status wird aus der Zeit berechnet (upcoming, in_progress, completed) bzw. cancelled/deleted. '
            . 'Für den Live-Kalender inkl. Ganztags/Frei-Belegt: user-connectors.microsoft365.calendar.list.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => self::listProperties(['microsoft365'], 'Terminbeginn') + [
                'status' => ['type' => 'string', 'enum' => ['upcoming', 'in_progress', 'completed', 'cancelled', 'deleted']],
                'direction' => ['type' => 'string', 'enum' => ['inbound', 'outbound'], 'description' => 'inbound = eingeladen, outbound = selbst organisiert.'],
                'search' => ['type' => 'string', 'description' => 'Teil von Betreff, Ort oder Organisator.'],
            ],
        ];
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $connectionIds = $this->scopedConnectionIds($context->user, $arguments, ['microsoft365']);
        $now = now();

        $query = UserConnectorMeetingSession::query()
            ->whereIn('connection_id', $connectionIds)
            ->orderByRaw('CASE WHEN status NOT IN (?, ?) AND end_at >= ? THEN 0 ELSE 1 END', ['cancelled', 'deleted', $now])
            ->orderByRaw('CASE WHEN end_at >= ? THEN start_at END ASC', [$now])
            ->orderByDesc('start_at');

        // Status aus der Zeit statt aus der (evtl. veralteten) Spalte — ohne beim Lesen zu schreiben.
        $active = fn ($q) => $q->whereNotIn('status', ['cancelled', 'deleted']);
        match ($arguments['status'] ?? null) {
            'upcoming' => $query->where($active)->where('start_at', '>', $now),
            'in_progress' => $query->where($active)->where('start_at', '<=', $now)->where('end_at', '>=', $now),
            'completed' => $query->where($active)->where('end_at', '<', $now),
            'cancelled', 'deleted' => $query->where('status', $arguments['status']),
            null, '' => null,
            default => throw new \InvalidArgumentException('Unbekannter status.'),
        };

        if (!empty($arguments['direction'])) {
            $query->where('direction', (string) $arguments['direction']);
        }
        if (!empty($arguments['search'])) {
            $this->whereLike($query, ['subject', 'location', 'organizer_name', 'organizer_address'], (string) $arguments['search']);
        }
        [$from, $to] = $this->dateRange($arguments);
        if ($from) {
            $query->where('start_at', '>=', $from);
        }
        if ($to) {
            $query->where('start_at', '<=', $to);
        }

        return ToolResult::success($this->paginate($query, $arguments, 'meeting_sessions', fn (UserConnectorMeetingSession $s) => array_filter([
            'id' => $s->id,
            'connection_id' => $s->connection_id,
            'external_event_id' => $s->external_event_id,
            'direction' => $s->direction,
            'status' => $this->effectiveStatus($s, $now),
            'subject' => $s->subject,
            'organizer_name' => $s->organizer_name,
            'organizer_address' => $s->organizer_address,
            'attendee_addresses' => $s->attendee_addresses ?: null,
            'location' => $s->location,
            'online_meeting_url' => $s->online_meeting_url,
            'start_at' => $s->start_at?->toIso8601String(),
            'end_at' => $s->end_at?->toIso8601String(),
            'duration' => $s->durationForHumans(),
            'body_preview' => $s->body_preview,
        ], fn ($v) => $v !== null && $v !== '')));
    }

    private function effectiveStatus(UserConnectorMeetingSession $s, $now): string
    {
        if (in_array($s->status, ['cancelled', 'deleted'], true) || !$s->start_at || !$s->end_at) {
            return (string) $s->status;
        }

        return match (true) {
            $now->lt($s->start_at) => 'upcoming',
            $now->lte($s->end_at) => 'in_progress',
            default => 'completed',
        };
    }

    public function getMetadata(): array
    {
        return self::readMetadata(['meeting-sessions', 'meetings', 'calendar', 'termine', 'history', 'microsoft365']);
    }
}
