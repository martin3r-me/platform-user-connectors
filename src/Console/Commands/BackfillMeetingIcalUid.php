<?php

namespace Platform\UserConnectors\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Platform\UserConnectors\Jobs\EnrichMicrosoft365EventJob;
use Platform\UserConnectors\Models\UserConnectorInboundEvent;
use Platform\UserConnectors\Models\UserConnectorMeetingSession;

/**
 * Increment-3-Automatik: re-enricht GEZIELT nur Meeting-Sessions ohne iCalUId aus
 * dem MS365-Graph (der Enrich-Job zieht die iCalUId jetzt mit). Bounded pro Lauf
 * (Graph-Last), idempotent, **selbst-stoppend** — sobald alle Sessions eine iCalUId
 * haben, findet der Command nichts mehr und tut nichts.
 *
 * Danach kopiert inbox:backfill-identity die iCalUId auf die inbox_items → Serien
 * kollabieren, und die Auto-Propagation/Vererbung greift auch an Altdaten.
 */
class BackfillMeetingIcalUid extends Command
{
    protected $signature = 'user-connectors:backfill-meeting-ical
        {--limit=200 : Sessions pro Lauf (begrenzt die Graph-Last)}
        {--dry-run}';

    protected $description = 'Re-enricht Meeting-Sessions ohne iCalUId aus MS365 (gezielt, bounded, selbst-stoppend).';

    public function handle(): int
    {
        if (
            ! Schema::hasTable('user_connector_meeting_sessions')
            || ! Schema::hasColumn('user_connector_meeting_sessions', 'ical_uid')
        ) {
            $this->warn('Meeting-Sessions/ical_uid nicht verfügbar — übersprungen.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $dry = (bool) $this->option('dry-run');

        $sessions = UserConnectorMeetingSession::query()
            ->whereNull('ical_uid')
            ->whereNotNull('external_event_id')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'external_event_id']);

        if ($sessions->isEmpty()) {
            $this->info('Alle Meeting-Sessions haben eine iCalUId — nichts zu tun.');

            return self::SUCCESS;
        }

        $externalIds = $sessions->pluck('external_event_id')->filter()->unique()->values()->all();

        $events = UserConnectorInboundEvent::query()
            ->whereIn('external_id', $externalIds)
            ->where('connector_key', 'microsoft365')
            ->where('event_type', 'like', 'microsoft365.%')
            ->orderBy('id')
            ->get(['id']);

        $dispatched = 0;
        foreach ($events as $event) {
            if (! $dry) {
                EnrichMicrosoft365EventJob::dispatch($event->id);
            }
            $dispatched++;
        }

        $this->info(sprintf(
            '%s%d Session(s) ohne iCalUId → %d Enrichment-Job(s) dispatched (Graph-Re-Fetch).',
            $dry ? '[dry-run] ' : '',
            $sessions->count(),
            $dispatched,
        ));

        return self::SUCCESS;
    }
}
