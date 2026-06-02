<?php

namespace Platform\UserConnectors\Console\Commands;

use Illuminate\Console\Command;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Services\WebhookSubscriptionManager;

class RenewWebhookSubscriptions extends Command
{
    protected $signature = 'user-connectors:renew-webhook-subscriptions
        {--force : Renew all subscriptions regardless of expiration}
        {--buffer=3600 : Buffer in seconds before expiration to trigger renewal}
        {--dry-run : Show what would be renewed without making changes}
        {--connector= : Only process a specific connector key}';

    protected $description = 'Renew expiring webhook subscriptions for user connectors';

    public function handle(WebhookSubscriptionManager $manager): int
    {
        $force = $this->option('force');
        $buffer = (int) $this->option('buffer');
        $dryRun = $this->option('dry-run');
        $connectorFilter = $this->option('connector');

        $connectorKeys = $connectorFilter
            ? [$connectorFilter]
            : $manager->getRegisteredKeys();

        $renewed = 0;
        $failed = 0;

        foreach ($connectorKeys as $connectorKey) {
            if (!$manager->getConnector($connectorKey)) {
                $this->warn("Connector '{$connectorKey}' nicht registriert, überspringe.");
                continue;
            }

            $connections = UserConnectorConnection::query()
                ->whereHas('connector', fn ($q) => $q->where('key', $connectorKey))
                ->where('status', 'active')
                ->get();

            foreach ($connections as $connection) {
                $subscriptions = $connection->credentials['subscriptions'] ?? [];
                $settings = $connection->credentials['settings'] ?? [];

                if (!($settings['subscriptions_enabled'] ?? true)) {
                    continue;
                }

                if (empty($subscriptions) && !$force) {
                    continue;
                }

                $needsRenewal = $force || $manager->hasExpiringSoon($connection, $buffer);

                if (!$needsRenewal) {
                    continue;
                }

                $this->info("Connection #{$connection->id} ({$connectorKey}): " .
                    ($dryRun ? 'würde erneuert' : 'wird erneuert...'));

                if ($dryRun) {
                    foreach ($subscriptions as $sub) {
                        $expiresAt = $sub['expires_at'] ?? 0;
                        $expiresIn = $expiresAt > 0 ? $expiresAt - now()->timestamp : 0;
                        $this->line("  Subscription {$sub['id']}: läuft ab in " . round($expiresIn / 3600, 1) . 'h');
                    }
                    $renewed++;
                    continue;
                }

                try {
                    $result = $manager->renewSubscriptions($connection);
                    $this->info("  → " . count($result) . ' Subscription(s) erneuert.');
                    $renewed++;
                } catch (\Exception $e) {
                    $this->error("  → Fehler: " . $e->getMessage());
                    $failed++;
                }
            }
        }

        $this->newLine();
        $this->info("Ergebnis: {$renewed} erneuert, {$failed} fehlgeschlagen.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
