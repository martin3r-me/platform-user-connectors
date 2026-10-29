<?php

namespace Platform\UserConnectors\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\User;
use Platform\UserConnectors\Models\UserConnector;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365ApiService;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365CalendarConnector;

/**
 * Kalender-Spiegel: hält einen fremden Termin (Quelle + Schlüssel, z. B. ein geplanter Huddle aus
 * Parlan) als Kopie im Kalender seines Organisators — mit den Teilnehmern als Eingeladene, sodass
 * der Provider (Exchange) Einladungen, Verschiebungen und Absagen selbst verschickt.
 *
 * Geschrieben wird nur über die EIGENE Verbindung des Organisators (nie über eine geteilte —
 * sonst landete der Termin in einem fremden Postfach). Die Quelle bleibt führend: der Spiegel
 * schreibt nur bei geänderter Quelle (Fingerabdruck), eine Absage genau einmal.
 *
 * Kontrakt für andere Module (lose: class_exists prüfen). Event-Felder: title, start, end
 * (ISO 8601), attendees (list<{email,name?}>), location?, description? (HTML), canceled.
 */
class CalendarMirror
{
    public const TABLE = 'user_connector_calendar_mirrors';

    /**
     * @return string created | updated | canceled | same | skipped | no_connection | error
     */
    public function sync(string $source, string $key, User $organizer, array $event): string
    {
        $row = DB::table(self::TABLE)->where('source', $source)->where('external_key', $key)->first();
        $canceled = (bool) ($event['canceled'] ?? false);
        $start = Carbon::parse((string) $event['start']);
        $end = Carbon::parse((string) $event['end']);
        $fp = $this->fingerprint($event);

        try {
            if ($row === null) {
                if ($canceled || $end->isPast()) {
                    return 'skipped'; // nie gespiegelt und nichts mehr anzukündigen
                }
                $connection = $this->ownConnection($organizer);
                if ($connection === null) {
                    return 'no_connection';
                }
                $created = $this->calendar($connection)->createEvent($organizer, (string) $event['title'], $start, $end,
                    $event['attendees'] ?? [], $this->options($event));
                DB::table(self::TABLE)->insert([
                    'source' => $source, 'external_key' => $key, 'user_id' => $organizer->id, 'connection_id' => $connection->id,
                    'provider_event_id' => $created->id, 'fingerprint' => $fp, 'created_at' => now(), 'updated_at' => now(),
                ]);

                return 'created';
            }

            if ($row->canceled_at !== null) {
                return 'same'; // abgesagt bleibt abgesagt (TRAAN kennt kein Wieder-Öffnen)
            }
            $connection = UserConnectorConnection::query()->where('id', $row->connection_id)
                ->where('owner_user_id', $row->user_id)->where('status', 'active')->first();
            if ($connection === null) {
                return 'no_connection';
            }
            $owner = $organizer->id === (int) $row->user_id ? $organizer : User::find($row->user_id);
            if ($owner === null) {
                return 'no_connection';
            }
            $calendar = $this->calendar($connection);

            if ($canceled) {
                $calendar->cancelEvent($owner, (string) $row->provider_event_id, 'Der Huddle wurde in Parlan abgesagt.');
                DB::table(self::TABLE)->where('id', $row->id)->update(['canceled_at' => now(), 'fingerprint' => $fp, 'updated_at' => now()]);

                return 'canceled';
            }
            if ($row->fingerprint === $fp) {
                return 'same';
            }
            $calendar->updateEvent($owner, (string) $row->provider_event_id, [
                'title' => (string) $event['title'], 'start' => $start, 'end' => $end,
                'attendees' => $event['attendees'] ?? [],
            ] + $this->options($event));
            DB::table(self::TABLE)->where('id', $row->id)->update(['fingerprint' => $fp, 'updated_at' => now()]);

            return 'updated';
        } catch (\Throwable $e) {
            Log::warning('CalendarMirror: Spiegeln fehlgeschlagen', ['source' => $source, 'key' => $key, 'error' => $e->getMessage()]);

            return 'error';
        }
    }

    /** Kann für diesen Organisator gespiegelt werden (eigene, aktive M365-Verbindung)? */
    public function canMirror(User $user): bool
    {
        return $this->ownConnection($user) !== null;
    }

    /** Nur die eigene, aktive M365-Verbindung des Organisators. */
    private function ownConnection(User $user): ?UserConnectorConnection
    {
        $connector = UserConnector::where('key', 'microsoft365')->first();

        return $connector === null ? null : UserConnectorConnection::query()
            ->where('connector_id', $connector->id)->where('owner_user_id', $user->id)->where('status', 'active')
            ->orderByDesc('is_default')->first();
    }

    private function calendar(UserConnectorConnection $connection): Microsoft365CalendarConnector
    {
        return new Microsoft365CalendarConnector(app(Microsoft365ApiService::class)->forConnection($connection->id));
    }

    private function options(array $event): array
    {
        return array_filter([
            'timezone' => 'Europe/Berlin',
            'location' => $event['location'] ?? null,
            'description' => $event['description'] ?? null,
            'body_type' => isset($event['description']) ? 'html' : null,
        ], static fn ($v) => $v !== null);
    }

    private function fingerprint(array $event): string
    {
        $att = array_map(static fn ($a) => strtolower(is_string($a) ? $a : (string) ($a['email'] ?? '')), $event['attendees'] ?? []);
        sort($att);

        return hash('sha256', json_encode([
            (string) $event['title'], Carbon::parse((string) $event['start'])->getTimestamp(), Carbon::parse((string) $event['end'])->getTimestamp(),
            $att, (string) ($event['location'] ?? ''), (bool) ($event['canceled'] ?? false),
        ]));
    }
}
