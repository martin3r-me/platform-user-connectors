<?php

namespace Platform\UserConnectors\Services;

use Illuminate\Support\Carbon;
use Platform\UserConnectors\Models\UserConnectorMeetingSession;

/**
 * Persönlicher Kalender (user-scoped) für die persönliche Sicht (home).
 *
 * Liefert die synchronisierten Termine (UserConnectorMeetingSession) eines Users —
 * Besitz läuft über die Connection (owner_user_id). Kapselt den Modellzugriff,
 * damit home nicht am user-connectors-Modell hängt. Gruppiert nach Tag, kommende
 * zuerst; abgesagte/gelöschte Termine werden ausgeblendet.
 */
class PersonCalendarService
{
    /**
     * @return array{
     *   days: array<int, array{date:string,label:string,is_today:bool,entries:array<int,array<string,mixed>>}>,
     *   count: int, today_count: int
     * }
     */
    public function agenda(int $userId, int $daysAhead = 14, int $daysBack = 1): array
    {
        $from = Carbon::today()->subDays(max(0, $daysBack));
        $to   = Carbon::today()->addDays(max(1, $daysAhead))->endOfDay();
        $now  = Carbon::now();

        $sessions = UserConnectorMeetingSession::query()
            ->whereHas('connection', fn ($q) => $q->where('owner_user_id', $userId))
            ->whereNotIn('status', ['cancelled', 'deleted'])
            ->whereNotNull('start_at')
            ->whereBetween('start_at', [$from, $to])
            ->orderBy('start_at')
            ->get();

        $byDay = [];
        $todayCount = 0;

        foreach ($sessions as $s) {
            $start = $s->start_at ? Carbon::parse($s->start_at) : null;
            if (!$start) {
                continue;
            }
            $end = $s->end_at ? Carbon::parse($s->end_at) : null;
            $dateKey = $start->toDateString();

            $isNow = $end ? $now->between($start, $end) : false;
            if ($start->isToday()) {
                $todayCount++;
            }

            $byDay[$dateKey][] = [
                'id'        => (int) $s->id,
                'subject'   => $s->subject ?: 'Termin',
                'start'     => $start->format('H:i'),
                'end'       => $end?->format('H:i'),
                'time_label' => $end ? $start->format('H:i') . '–' . $end->format('H:i') : $start->format('H:i'),
                'location'  => $s->location ?: null,
                'is_online' => (bool) $s->is_online_meeting,
                'organizer' => $s->organizer_name ?: ($s->organizer_address ?: null),
                'is_now'    => $isNow,
                'is_past'   => $end ? $end->lt($now) : $start->lt($now),
            ];
        }

        $days = [];
        foreach ($byDay as $date => $entries) {
            $d = Carbon::parse($date);
            $days[] = [
                'date'     => $date,
                'label'    => $d->isToday()
                    ? 'Heute · ' . $d->locale('de')->isoFormat('dddd, D. MMMM')
                    : ($d->isTomorrow()
                        ? 'Morgen · ' . $d->locale('de')->isoFormat('dddd, D. MMMM')
                        : $d->locale('de')->isoFormat('dddd, D. MMMM')),
                'is_today' => $d->isToday(),
                'entries'  => $entries,
            ];
        }

        return [
            'days'        => $days,
            'count'       => $sessions->count(),
            'today_count' => $todayCount,
        ];
    }
}
