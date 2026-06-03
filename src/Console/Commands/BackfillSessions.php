<?php

namespace Platform\UserConnectors\Console\Commands;

use Illuminate\Console\Command;
use Platform\UserConnectors\Jobs\EnrichMicrosoft365EventJob;
use Platform\UserConnectors\Models\UserConnectorInboundEvent;
use Platform\UserConnectors\Services\InboundEventService;

class BackfillSessions extends Command
{
    protected $signature = 'user-connectors:backfill-sessions
        {--type= : Only backfill a specific type: mail, calendar, teams, sms}
        {--dry-run : Show what would be processed without making changes}
        {--connection= : Only process events for a specific connection ID}
        {--re-enrich : Re-dispatch enrichment jobs for events missing meta/external_id}
        {--diagnose : Show event statistics without processing}
        {--fix-types : Fix misclassified microsoft365.* event types using resource path}';

    protected $description = 'Backfill mail, meeting and message sessions from existing enriched inbound events';

    public function handle(InboundEventService $service): int
    {
        $typeFilter = $this->option('type');
        $connectionFilter = $this->option('connection');

        $eventTypes = [];
        if (!$typeFilter || $typeFilter === 'mail') {
            $eventTypes[] = 'mail.';
        }
        if (!$typeFilter || $typeFilter === 'calendar') {
            $eventTypes[] = 'calendar.';
        }
        if (!$typeFilter || $typeFilter === 'teams') {
            $eventTypes[] = 'teams.';
        }
        if (!$typeFilter || $typeFilter === 'sms') {
            $eventTypes[] = 'sms.';
        }

        // Diagnose mode: show what's in the DB
        if ($this->option('diagnose')) {
            return $this->diagnose($eventTypes, $connectionFilter);
        }

        // Fix misclassified event types
        if ($this->option('fix-types')) {
            return $this->fixEventTypes($connectionFilter);
        }

        // Re-enrich mode: dispatch enrichment for events missing data
        if ($this->option('re-enrich')) {
            return $this->reEnrich($eventTypes, $connectionFilter);
        }

        return $this->backfill($service, $eventTypes, $connectionFilter);
    }

    protected function fixEventTypes(?string $connectionFilter): int
    {
        $dryRun = $this->option('dry-run');

        // Find events with microsoft365.* type that should be mail/calendar/teams
        $query = UserConnectorInboundEvent::query()
            ->where('connector_key', 'microsoft365')
            ->where('event_type', 'like', 'microsoft365.%')
            ->when($connectionFilter, fn ($q) => $q->where('connection_id', (int) $connectionFilter));

        $total = $query->count();
        $this->info("Events mit microsoft365.* Typ: {$total}");

        if ($total === 0) {
            $this->info('Keine falsch klassifizierten Events gefunden.');
            return self::SUCCESS;
        }

        // Show sample payload to understand the structure
        $sample = $query->first();
        if ($sample) {
            $this->info('Beispiel-Payload (Event #' . $sample->id . '):');
            $this->line('  Keys: ' . implode(', ', array_keys($sample->payload ?? [])));
            $payload = $sample->payload ?? [];
            foreach (['resource', 'changeType', 'subscriptionId', 'resourceData'] as $key) {
                $val = $payload[$key] ?? '(nicht vorhanden)';
                if (is_array($val)) {
                    $val = json_encode($val);
                }
                $this->line("  {$key}: {$val}");
            }
            $this->newLine();
        }

        $fixed = 0;
        $unfixable = 0;

        $query->orderBy('id')->chunk(100, function ($events) use ($dryRun, &$fixed, &$unfixable) {
            foreach ($events as $event) {
                $payload = $event->payload ?? [];

                // changeType: try payload first, then extract from event_type
                $changeType = $payload['changeType'] ?? '';
                if (!$changeType) {
                    $parts = explode('.', $event->event_type, 2);
                    $changeType = $parts[1] ?? '';
                }

                if (!$changeType) {
                    $unfixable++;
                    continue;
                }

                // resource: try multiple locations in the payload
                $resource = $payload['resource']
                    ?? $payload['resourceData']['@odata.id'] ?? '';

                // Determine new event type from resource path (case-insensitive)
                $newType = null;
                if ($resource) {
                    $r = strtolower($resource);
                    if (str_contains($r, 'chats') || str_contains($r, 'teams')) {
                        $newType = 'teams.' . $changeType;
                    } elseif (str_contains($r, 'messages') || str_contains($r, 'mailfolders')) {
                        $newType = 'mail.' . $changeType;
                    } elseif (str_contains($r, 'events') || str_contains($r, 'calendar')) {
                        $newType = 'calendar.' . $changeType;
                    }
                }

                // If no resource, try to infer from full payload content
                if (!$newType) {
                    $payloadStr = strtolower(json_encode($payload));
                    if (str_contains($payloadStr, 'chat') || str_contains($payloadStr, 'teams')) {
                        $newType = 'teams.' . $changeType;
                    } elseif (str_contains($payloadStr, 'message') || str_contains($payloadStr, 'mail') || str_contains($payloadStr, 'inbox')) {
                        $newType = 'mail.' . $changeType;
                    } elseif (str_contains($payloadStr, 'event') || str_contains($payloadStr, 'calendar')) {
                        $newType = 'calendar.' . $changeType;
                    }
                }

                if (!$newType) {
                    $unfixable++;
                    continue;
                }

                if ($dryRun) {
                    $this->line("  #{$event->id}: {$event->event_type} → {$newType} (resource: " . ($resource ?: 'inferred') . ")");
                } else {
                    $event->update(['event_type' => $newType]);
                }
                $fixed++;
            }
        });

        if ($dryRun) {
            $this->newLine();
            $this->info("Dry-run: {$fixed} würden gefixt, {$unfixable} nicht zuordenbar.");
        } else {
            $this->info("Ergebnis: {$fixed} event_types korrigiert, {$unfixable} nicht zuordenbar.");
            if ($fixed > 0) {
                $this->info('Jetzt --re-enrich ausführen um die korrigierten Events anzureichern.');
            }
        }

        return self::SUCCESS;
    }

    protected function diagnose(array $eventTypes, ?string $connectionFilter): int
    {
        $this->info('=== Diagnose: Inbound Events ===');
        $this->newLine();

        // Include microsoft365.* events (likely misclassified mail/calendar/teams)
        $allPrefixes = array_merge($eventTypes, ['microsoft365.']);

        $baseQuery = fn () => UserConnectorInboundEvent::query()
            ->where(function ($q) use ($allPrefixes) {
                foreach ($allPrefixes as $prefix) {
                    $q->orWhere('event_type', 'like', $prefix . '%');
                }
            })
            ->when($connectionFilter, fn ($q) => $q->where('connection_id', (int) $connectionFilter));

        // Total by event_type
        $byType = $baseQuery()
            ->selectRaw('event_type, count(*) as cnt')
            ->groupBy('event_type')
            ->orderBy('event_type')
            ->pluck('cnt', 'event_type');

        $this->info('Events nach Typ:');
        $misclassified = 0;
        foreach ($byType as $type => $count) {
            $suffix = '';
            if (str_starts_with($type, 'microsoft365.')) {
                $suffix = ' ← falsch klassifiziert! (--fix-types)';
                $misclassified += $count;
            }
            $this->line("  {$type}: {$count}{$suffix}");
        }
        if ($misclassified > 0) {
            $this->newLine();
            $this->warn("{$misclassified} Events als microsoft365.* statt mail.*/calendar.*/teams.* gespeichert.");
            $this->warn('Führe --fix-types aus, dann --re-enrich, dann nochmal ohne Flags.');
        }
        $this->newLine();

        // Connection status
        $withConnection = $baseQuery()->whereNotNull('connection_id')->count();
        $withoutConnection = $baseQuery()->whereNull('connection_id')->count();
        $this->info("connection_id: {$withConnection} gesetzt, {$withoutConnection} NULL");

        // External ID status
        $withExtId = $baseQuery()->whereNotNull('external_id')->where('external_id', '!=', '')->count();
        $withoutExtId = $baseQuery()->where(fn ($q) => $q->whereNull('external_id')->orWhere('external_id', ''))->count();
        $this->info("external_id:   {$withExtId} gesetzt, {$withoutExtId} NULL/leer");

        // Meta status
        $withMeta = $baseQuery()
            ->whereNotNull('meta')
            ->whereRaw("json_length(meta) > 0")
            ->count();
        $withoutMeta = $baseQuery()
            ->where(fn ($q) => $q->whereNull('meta')->orWhereRaw("json_length(meta) = 0"))
            ->count();
        $this->info("meta:          {$withMeta} mit Daten, {$withoutMeta} leer/NULL");

        // Enrichable (has connection + is MS365 type)
        $enrichable = $baseQuery()
            ->whereNotNull('connection_id')
            ->where('connector_key', 'microsoft365')
            ->where(fn ($q) => $q->whereNull('meta')->orWhereRaw("json_length(meta) = 0")->orWhereNull('external_id'))
            ->count();
        $this->newLine();
        $this->info("Re-enrichable (connection vorhanden, meta/external_id fehlt): {$enrichable}");

        // Ready for backfill
        $ready = $baseQuery()
            ->whereNotNull('connection_id')
            ->whereNotNull('external_id')
            ->where('external_id', '!=', '')
            ->whereNotNull('meta')
            ->whereRaw("json_length(meta) > 0")
            ->count();
        $this->info("Bereit für Session-Backfill (alles vorhanden): {$ready}");

        // Sample of events without meta
        if ($withoutMeta > 0) {
            $this->newLine();
            $this->info('Beispiel-Events ohne Meta (max 5):');
            $samples = $baseQuery()
                ->where(fn ($q) => $q->whereNull('meta')->orWhereRaw("json_length(meta) = 0"))
                ->orderByDesc('id')
                ->limit(5)
                ->get(['id', 'event_type', 'connector_key', 'connection_id', 'external_id', 'processing_status', 'created_at']);

            foreach ($samples as $s) {
                $this->line("  #{$s->id} {$s->event_type} | connector={$s->connector_key} conn={$s->connection_id} ext_id={$s->external_id} status={$s->processing_status} {$s->created_at}");
            }
        }

        return self::SUCCESS;
    }

    protected function reEnrich(array $eventTypes, ?string $connectionFilter): int
    {
        $dryRun = $this->option('dry-run');

        $query = UserConnectorInboundEvent::query()
            ->whereNotNull('connection_id')
            ->where('connector_key', 'microsoft365')
            ->where(function ($q) use ($eventTypes) {
                foreach ($eventTypes as $prefix) {
                    $q->orWhere('event_type', 'like', $prefix . '%');
                }
            })
            ->where(function ($q) {
                $q->whereNull('meta')
                    ->orWhereRaw("json_length(meta) = 0")
                    ->orWhereNull('external_id')
                    ->orWhere('external_id', '');
            })
            ->when($connectionFilter, fn ($q) => $q->where('connection_id', (int) $connectionFilter));

        $total = $query->count();
        $this->info("Events zum Re-Enrichment: {$total}");

        if ($total === 0) {
            $this->info('Nichts zu tun.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $summary = (clone $query)
                ->selectRaw('event_type, count(*) as cnt')
                ->groupBy('event_type')
                ->orderBy('event_type')
                ->pluck('cnt', 'event_type');

            foreach ($summary as $type => $count) {
                $this->line("  {$type}: {$count}");
            }
            $this->info('Dry-run — keine Jobs dispatched.');
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();
        $dispatched = 0;

        $query->orderBy('id')->chunk(100, function ($events) use (&$dispatched, $bar) {
            foreach ($events as $event) {
                EnrichMicrosoft365EventJob::dispatch($event->id);
                $dispatched++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("{$dispatched} Enrichment-Jobs dispatched. Sessions werden automatisch nach Enrichment erstellt.");
        $this->info('Warte auf Queue-Verarbeitung, dann erneut --diagnose oder ohne Flags ausführen.');

        return self::SUCCESS;
    }

    protected function backfill(InboundEventService $service, array $eventTypes, ?string $connectionFilter): int
    {
        $dryRun = $this->option('dry-run');

        $query = UserConnectorInboundEvent::query()
            ->whereNotNull('connection_id')
            ->whereNotNull('external_id')
            ->where('external_id', '!=', '')
            ->whereNotNull('meta')
            ->whereRaw("json_length(meta) > 0")
            ->where(function ($q) use ($eventTypes) {
                foreach ($eventTypes as $prefix) {
                    $q->orWhere('event_type', 'like', $prefix . '%');
                }
            })
            ->when($connectionFilter, fn ($q) => $q->where('connection_id', (int) $connectionFilter));

        $total = $query->count();
        $this->info("Gefundene enriched Events: {$total}");

        if ($total === 0) {
            $this->info('Keine enriched Events gefunden. Versuche --diagnose oder --re-enrich.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $summary = (clone $query)
                ->selectRaw('event_type, count(*) as cnt')
                ->groupBy('event_type')
                ->orderBy('event_type')
                ->pluck('cnt', 'event_type');

            foreach ($summary as $type => $count) {
                $this->line("  {$type}: {$count}");
            }
            $this->info('Dry-run — keine Änderungen.');
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;

        $query->orderBy('id')->chunk(100, function ($events) use ($service, &$created, &$updated, &$skipped, &$failed, $bar) {
            foreach ($events as $event) {
                try {
                    $eventType = $event->event_type;
                    $result = null;

                    if (str_starts_with($eventType, 'mail.')) {
                        $result = $this->correlateWithTracking($service, $event, 'mail');
                    } elseif (str_starts_with($eventType, 'calendar.')) {
                        $result = $this->correlateWithTracking($service, $event, 'calendar');
                    } elseif (str_starts_with($eventType, 'teams.') || str_starts_with($eventType, 'sms.')) {
                        $result = $this->correlateWithTracking($service, $event, 'message');
                    }

                    match ($result) {
                        'created' => $created++,
                        'updated' => $updated++,
                        'skipped' => $skipped++,
                        default => $skipped++,
                    };
                } catch (\Throwable $e) {
                    $failed++;
                    $this->newLine();
                    $this->warn("Event #{$event->id} ({$event->event_type}): {$e->getMessage()}");
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("Ergebnis: {$created} erstellt, {$updated} aktualisiert, {$skipped} übersprungen, {$failed} fehlgeschlagen.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function correlateWithTracking(InboundEventService $service, UserConnectorInboundEvent $event, string $type): string
    {
        $modelClass = match ($type) {
            'mail' => \Platform\UserConnectors\Models\UserConnectorMailSession::class,
            'calendar' => \Platform\UserConnectors\Models\UserConnectorMeetingSession::class,
            'message' => \Platform\UserConnectors\Models\UserConnectorMessageSession::class,
        };

        $externalColumn = match ($type) {
            'mail' => 'external_mail_id',
            'calendar' => 'external_event_id',
            'message' => 'external_message_id',
        };

        $existed = $modelClass::where($externalColumn, $event->external_id)->exists();

        match ($type) {
            'mail' => $service->updateMailSession($event),
            'calendar' => $service->updateMeetingSession($event),
            'message' => $service->updateMessageSession($event),
        };

        if ($existed) {
            return 'updated';
        }

        $existsNow = $modelClass::where($externalColumn, $event->external_id)->exists();
        return $existsNow ? 'created' : 'skipped';
    }
}
