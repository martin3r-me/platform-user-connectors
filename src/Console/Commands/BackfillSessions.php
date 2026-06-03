<?php

namespace Platform\UserConnectors\Console\Commands;

use Illuminate\Console\Command;
use Platform\UserConnectors\Models\UserConnectorInboundEvent;
use Platform\UserConnectors\Services\InboundEventService;

class BackfillSessions extends Command
{
    protected $signature = 'user-connectors:backfill-sessions
        {--type= : Only backfill a specific type: mail, calendar, teams, sms}
        {--dry-run : Show what would be processed without making changes}
        {--connection= : Only process events for a specific connection ID}';

    protected $description = 'Backfill mail, meeting and message sessions from existing enriched inbound events';

    public function handle(InboundEventService $service): int
    {
        $dryRun = $this->option('dry-run');
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

        $query = UserConnectorInboundEvent::query()
            ->whereNotNull('connection_id')
            ->whereNotNull('external_id')
            ->whereNotNull('meta')
            ->where(function ($q) use ($eventTypes) {
                foreach ($eventTypes as $prefix) {
                    $q->orWhere('event_type', 'like', $prefix . '%');
                }
            });

        if ($connectionFilter) {
            $query->where('connection_id', (int) $connectionFilter);
        }

        $total = $query->count();
        $this->info("Gefundene Events mit enriched meta: {$total}");

        if ($total === 0) {
            $this->info('Nichts zu tun.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $summary = UserConnectorInboundEvent::query()
                ->whereNotNull('connection_id')
                ->whereNotNull('external_id')
                ->whereNotNull('meta')
                ->where(function ($q) use ($eventTypes) {
                    foreach ($eventTypes as $prefix) {
                        $q->orWhere('event_type', 'like', $prefix . '%');
                    }
                })
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

        // Check if it was actually created (messages skip duplicates)
        $existsNow = $modelClass::where($externalColumn, $event->external_id)->exists();
        return $existsNow ? 'created' : 'skipped';
    }
}
