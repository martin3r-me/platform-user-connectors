<?php

namespace Platform\UserConnectors\Organization;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Platform\Organization\Contracts\PersonActivityProvider;
use Platform\UserConnectors\Models\UserConnectorCallSession;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Models\UserConnectorMailSession;
use Platform\UserConnectors\Models\UserConnectorMeetingSession;
use Platform\UserConnectors\Models\UserConnectorMessageSession;

class UserConnectorsPersonActivityProvider implements PersonActivityProvider
{
    public function sectionKey(): string
    {
        return 'user_connectors';
    }

    public function sectionConfig(): array
    {
        return [
            'label' => 'Kommunikation',
            'icon' => 'chat-bubble-left-right',
            'description' => 'Anrufe, Mails, Meetings und Messages',
        ];
    }

    public function metricConfig(): array
    {
        return [
            'call_minutes_7d' => ['label' => 'Telefon-Zeit 7d', 'type' => 'info', 'sort_weight' => 2],
            'meeting_minutes_past_7d' => ['label' => 'Meeting-Zeit 7d', 'type' => 'info', 'sort_weight' => 2],
            'meeting_minutes_upcoming_7d' => ['label' => 'Kommende Meetings 7d', 'type' => 'info', 'sort_weight' => 2],
            'mails_sent_7d' => ['label' => 'Mails versendet 7d', 'type' => 'info', 'sort_weight' => 1],
            'messages_sent_7d' => ['label' => 'Messages versendet 7d', 'type' => 'info', 'sort_weight' => 1],
            'unique_contacts_30d' => ['label' => 'Kontakte 30d', 'type' => 'info', 'sort_weight' => 1],
        ];
    }

    public function vitalSigns(int $userId, int $teamId): array
    {
        $connectionIds = $this->connectionIds($userId);

        if (empty($connectionIds)) {
            return [];
        }

        $now = CarbonImmutable::now();
        $weekAgo = $now->subDays(7);
        $weekAhead = $now->addDays(7);
        $monthAgo = $now->subDays(30);

        // Calls: only completed/answered (duration > 0)
        $callAgg = UserConnectorCallSession::whereIn('connection_id', $connectionIds)
            ->where('started_at', '>=', $weekAgo)
            ->whereNotNull('duration_seconds')
            ->where('duration_seconds', '>', 0)
            ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(duration_seconds), 0) AS secs')
            ->first();
        $callCount = (int) ($callAgg->cnt ?? 0);
        $callMinutes = (int) round(((int) ($callAgg->secs ?? 0)) / 60);

        // Meetings — past 7d (completed, excluding cancelled/deleted)
        $meetingPastAgg = UserConnectorMeetingSession::whereIn('connection_id', $connectionIds)
            ->whereNotIn('status', ['cancelled', 'deleted'])
            ->whereBetween('end_at', [$weekAgo, $now])
            ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(duration_minutes), 0) AS mins')
            ->first();
        $meetingPastCount = (int) ($meetingPastAgg->cnt ?? 0);
        $meetingPastMinutes = (int) ($meetingPastAgg->mins ?? 0);

        // Meetings — upcoming 7d
        $meetingUpcomingAgg = UserConnectorMeetingSession::whereIn('connection_id', $connectionIds)
            ->whereIn('status', ['upcoming', 'in_progress'])
            ->whereBetween('start_at', [$now, $weekAhead])
            ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(duration_minutes), 0) AS mins')
            ->first();
        $meetingUpcomingCount = (int) ($meetingUpcomingAgg->cnt ?? 0);
        $meetingUpcomingMinutes = (int) ($meetingUpcomingAgg->mins ?? 0);

        $mailsSent = UserConnectorMailSession::whereIn('connection_id', $connectionIds)
            ->where('direction', 'outbound')
            ->where('sent_at', '>=', $weekAgo)
            ->count();

        $messagesSent = UserConnectorMessageSession::whereIn('connection_id', $connectionIds)
            ->where('direction', 'outbound')
            ->where('sent_at', '>=', $weekAgo)
            ->count();

        $uniqueContacts = $this->uniqueContacts30d($connectionIds, $monthAgo);

        return [
            [
                'key' => 'call_minutes_7d',
                'label' => 'Telefon-Min 7d' . ($callCount > 0 ? " ({$callCount}×)" : ''),
                'value' => $callMinutes,
                'variant' => 'default',
            ],
            [
                'key' => 'meeting_minutes_past_7d',
                'label' => 'Meeting-Min 7d' . ($meetingPastCount > 0 ? " ({$meetingPastCount}×)" : ''),
                'value' => $meetingPastMinutes,
                'variant' => 'default',
            ],
            [
                'key' => 'meeting_minutes_upcoming_7d',
                'label' => 'Kommend Min 7d' . ($meetingUpcomingCount > 0 ? " ({$meetingUpcomingCount}×)" : ''),
                'value' => $meetingUpcomingMinutes,
                'variant' => 'default',
            ],
            [
                'key' => 'mails_sent_7d',
                'label' => 'Mails versendet 7d',
                'value' => $mailsSent,
                'variant' => 'default',
            ],
            [
                'key' => 'messages_sent_7d',
                'label' => 'Messages versendet 7d',
                'value' => $messagesSent,
                'variant' => 'default',
            ],
            [
                'key' => 'unique_contacts_30d',
                'label' => 'Kontakte 30d',
                'value' => $uniqueContacts,
                'variant' => 'default',
            ],
        ];
    }

    public function responsibilities(int $userId, int $teamId, int $limit = 5): array
    {
        $connectionIds = $this->connectionIds($userId);

        if (empty($connectionIds)) {
            return [];
        }

        $now = CarbonImmutable::now();
        $weekAhead = $now->addDays(14);

        $query = UserConnectorMeetingSession::whereIn('connection_id', $connectionIds)
            ->whereIn('status', ['upcoming', 'in_progress'])
            ->whereBetween('start_at', [$now, $weekAhead])
            ->orderBy('start_at');

        $totalUpcoming = (clone $query)->count();
        $meetings = $query->limit($limit)->get();

        if ($totalUpcoming === 0) {
            return [];
        }

        return [
            [
                'key' => 'upcoming_meetings',
                'label' => 'Nächste Meetings',
                'icon' => 'calendar-days',
                'total_count' => $totalUpcoming,
                'items' => $meetings->map(fn ($m) => [
                    'id' => $m->id,
                    'name' => $m->subject ?: '(ohne Titel)',
                    'url' => null,
                    'meta' => $m->start_at?->format('d.m. H:i')
                        . ($m->duration_minutes ? ' · ' . $this->formatMinutes((int) $m->duration_minutes) : ''),
                ])->toArray(),
            ],
        ];
    }

    /**
     * Connection-IDs des Users (eigene + geteilte mit Read+).
     */
    protected function connectionIds(int $userId): array
    {
        return UserConnectorConnection::where('owner_user_id', $userId)
            ->pluck('id')
            ->all();
    }

    /**
     * Anzahl unterschiedlicher Gegenstellen über alle 4 Kanäle in den letzten 30 Tagen.
     * Normalisiert: Emails lowercase, Telefonnummern nur Ziffern.
     */
    protected function uniqueContacts30d(array $connectionIds, CarbonImmutable $since): int
    {
        $contacts = [];

        // Calls — andere Nummer abhängig von direction
        UserConnectorCallSession::whereIn('connection_id', $connectionIds)
            ->where('started_at', '>=', $since)
            ->select(['direction', 'from_number', 'to_number'])
            ->chunkById(500, function ($rows) use (&$contacts) {
                foreach ($rows as $row) {
                    $value = $row->direction === 'inbound' ? $row->from_number : $row->to_number;
                    $normalized = $this->normalizePhone($value);
                    if ($normalized !== null) {
                        $contacts['phone:' . $normalized] = true;
                    }
                }
            });

        // Mails
        UserConnectorMailSession::whereIn('connection_id', $connectionIds)
            ->where(function ($q) use ($since) {
                $q->where('received_at', '>=', $since)->orWhere('sent_at', '>=', $since);
            })
            ->select(['direction', 'from_address', 'to_addresses'])
            ->chunkById(500, function ($rows) use (&$contacts) {
                foreach ($rows as $row) {
                    if ($row->direction === 'inbound') {
                        $normalized = $this->normalizeEmail($row->from_address);
                        if ($normalized !== null) {
                            $contacts['email:' . $normalized] = true;
                        }
                    } else {
                        foreach ($this->splitAddresses($row->to_addresses) as $addr) {
                            $normalized = $this->normalizeEmail($addr);
                            if ($normalized !== null) {
                                $contacts['email:' . $normalized] = true;
                            }
                        }
                    }
                }
            });

        // Meetings — Organizer + Attendees
        UserConnectorMeetingSession::whereIn('connection_id', $connectionIds)
            ->where('start_at', '>=', $since)
            ->whereNotIn('status', ['cancelled', 'deleted'])
            ->select(['organizer_address', 'attendee_addresses'])
            ->chunkById(500, function ($rows) use (&$contacts) {
                foreach ($rows as $row) {
                    $normalized = $this->normalizeEmail($row->organizer_address);
                    if ($normalized !== null) {
                        $contacts['email:' . $normalized] = true;
                    }
                    foreach ($this->splitAddresses($row->attendee_addresses) as $addr) {
                        $normalized = $this->normalizeEmail($addr);
                        if ($normalized !== null) {
                            $contacts['email:' . $normalized] = true;
                        }
                    }
                }
            });

        // Messages — Teams / SMS
        UserConnectorMessageSession::whereIn('connection_id', $connectionIds)
            ->where('sent_at', '>=', $since)
            ->select(['direction', 'message_type', 'from_identifier', 'to_identifier'])
            ->chunkById(500, function ($rows) use (&$contacts) {
                foreach ($rows as $row) {
                    $value = $row->direction === 'inbound' ? $row->from_identifier : $row->to_identifier;
                    if ($value === null || $value === '') {
                        continue;
                    }
                    $prefix = $row->message_type === 'sms' ? 'phone:' : 'msg:';
                    $normalized = $row->message_type === 'sms'
                        ? $this->normalizePhone($value)
                        : strtolower(trim($value));
                    if ($normalized !== null && $normalized !== '') {
                        $contacts[$prefix . $normalized] = true;
                    }
                }
            });

        return count($contacts);
    }

    protected function normalizePhone(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $value);

        return $digits === '' ? null : $digits;
    }

    protected function normalizeEmail(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $trimmed = strtolower(trim($value));

        return str_contains($trimmed, '@') ? $trimmed : null;
    }

    /** @return string[] */
    protected function splitAddresses(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        return array_filter(array_map('trim', explode(',', $raw)));
    }

    protected function formatMinutes(int $minutes): string
    {
        if ($minutes <= 0) {
            return '0';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        if ($hours === 0) {
            return $rest . 'min';
        }

        if ($rest === 0) {
            return $hours . 'h';
        }

        return $hours . 'h ' . $rest . 'min';
    }
}
