<?php

namespace Platform\UserConnectors\Inbox;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Inbox\Contracts\SourceDetail;

/**
 * Live-Detail für den Mail-Kanal: voller Thread (alle Mails derselben
 * conversation_id) für den Reading-Pane. user-connectors besitzt die Daten, also
 * lebt das Detail hier — angedockt an die Core-Naht SourceDetail (statt im
 * zerfallenden inbox-Modul).
 *
 * Soft-coupled: liest inbox_items (Core-Kern) + die eigenen mail-sessions per
 * DB::table + Schema-Guard; keine harte Model-Abhängigkeit.
 */
class MailSourceDetail implements SourceDetail
{
    public function channel(): string
    {
        return 'mail';
    }

    public function detailForItem(int $inboxItemId): ?array
    {
        if (! Schema::hasTable('inbox_items') || ! Schema::hasTable('user_connector_mail_sessions')) {
            return null;
        }

        $item = DB::table('inbox_items')->where('id', $inboxItemId)->first();
        if (! $item || ($item->channel ?? null) !== 'mail') {
            return null;
        }

        $session = ($item->source_type ?? null) === 'user_connector_mail_session'
            ? DB::table('user_connector_mail_sessions')->where('id', $item->source_id)->first()
            : null;

        return [
            'channel'         => 'mail',
            'channel_label'   => 'E-Mail',
            'subject'         => ($session->subject ?? null) ?: ($item->subject ?: '(kein Betreff)'),
            'sender'          => ($session->from_name ?? null) ?: ($session->from_address ?? $item->sender_label ?? 'Unbekannt'),
            'time'            => $this->timeLabel($this->toCarbon($item->received_at ?? null)),
            'summary'         => null,
            'thread'          => $this->thread($item, $session),
            'older'           => 0,
            'has_attachments' => (bool) ($session->has_attachments ?? false),
        ];
    }

    /** Voller Verlauf: alle Mails derselben conversation_id, chronologisch. */
    protected function thread(object $item, ?object $session): array
    {
        $convId = $session->conversation_id ?? null;

        $rows = collect();
        if ($convId) {
            try {
                $rows = DB::table('user_connector_mail_sessions')
                    ->where('conversation_id', $convId)
                    ->orderByRaw('COALESCE(received_at, sent_at) ASC')
                    ->limit(50)
                    ->get();
            } catch (\Throwable $e) {
                $rows = collect();
            }
        }

        if ($rows->isEmpty()) {
            $rows = $session ? collect([$session]) : collect();
        }
        if ($rows->isEmpty()) {
            return [];
        }

        return $rows->map(function ($m) use ($item) {
            $when = $m->received_at ?? $m->sent_at ?? $item->received_at ?? null;

            return [
                'from' => ($m->from_name ?? null) ?: ($m->from_address ?? 'Unbekannt'),
                'time' => $this->timeLabel($this->toCarbon($when), true),
                'body' => $this->cleanBody(($m->body ?? null) ?: ($m->body_preview ?? '')),
                'me'   => ($m->direction ?? null) === 'outbound',
            ];
        })->values()->all();
    }

    protected function toCarbon(mixed $value): ?Carbon
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** HTML-Mails auf lesbaren Text reduzieren (kein roher Tag-Salat im Pane). */
    protected function cleanBody(?string $body): string
    {
        if (! $body) {
            return '';
        }
        $text = preg_replace('#<\s*(br|/p|/div|/tr)\s*/?>#i', "\n", $body);
        $text = strip_tags((string) $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", (string) $text);

        return trim((string) $text);
    }

    protected function timeLabel(?Carbon $when, bool $withDate = false): string
    {
        if (! $when) {
            return '—';
        }
        if ($when->isToday()) {
            return $when->format('H:i');
        }
        if ($when->isYesterday()) {
            return $withDate ? 'Gestern ' . $when->format('H:i') : 'Gestern';
        }
        if ($when->diffInDays(now()) < 7) {
            return $when->locale('de')->isoFormat($withDate ? 'dd H:i' : 'dd');
        }

        return $when->locale('de')->isoFormat($withDate ? 'D.M. H:i' : 'D.M.');
    }
}
