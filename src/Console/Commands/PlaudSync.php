<?php

namespace Platform\UserConnectors\Console\Commands;

use Illuminate\Console\Command;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\User;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Services\Plaud\PlaudApiService;

/**
 * Plaud-Sync: zieht neue Aufnahmen jeder Plaud-Connection in die Inbox.
 *
 * Pro Connection: refresh_token → Access-Token (neu persistieren), Files der
 * letzten N Tage listen, je File Transkript + Summary holen und über das
 * gehärtete inbox.plaud.sync-Tool importieren. Dedup (file_id) überspringt
 * bereits Importiertes → idempotent, kein Cursor nötig. Downstream greift die
 * CorrelateRecordingAutomation (Transkript→Termin).
 *
 * Seed: der refresh_token kommt aus der UI (einmal `plaud login`, Token einfügen);
 * hier wird er nur genutzt + refresht. Public client (kein Secret).
 */
class PlaudSync extends Command
{
    protected $signature = 'user-connectors:plaud-sync {--days=3 : Zeitfenster in Tagen} {--connection= : Nur diese Connection-ID}';

    protected $description = 'Importiert neue Plaud-Aufnahmen aller Plaud-Connections in die Inbox.';

    public function handle(PlaudApiService $plaud): int
    {
        $syncToolClass = '\\Platform\\Inbox\\Sources\\Plaud\\SyncTool';
        if (! class_exists($syncToolClass)) {
            $this->warn('Inbox-Plaud-SyncTool nicht verfügbar — übersprungen.');
            return self::SUCCESS;
        }

        $connections = UserConnectorConnection::query()
            ->whereHas('connector', fn ($q) => $q->where('key', 'plaud'))
            ->when($this->option('connection'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        if ($connections->isEmpty()) {
            $this->info('Keine Plaud-Connections.');
            return self::SUCCESS;
        }

        // Plaud erwartet epoch MILLISEKUNDEN für start_date/end_date.
        $from = now()->subDays((int) $this->option('days'))->getTimestampMs();
        $to = now()->getTimestampMs();
        $imported = 0;
        $skipped = 0;

        foreach ($connections as $conn) {
            try {
                $imported += $this->syncConnection($plaud, $syncToolClass, $conn, $from, $to, $skipped);
            } catch (\Throwable $e) {
                $this->error("Connection #{$conn->id}: " . $e->getMessage());
            }
        }

        $this->info("Fertig: {$imported} importiert, {$skipped} übersprungen (Dedup).");

        return self::SUCCESS;
    }

    protected function syncConnection(
        PlaudApiService $plaud,
        string $syncToolClass,
        UserConnectorConnection $conn,
        int $from,
        int $to,
        int &$skipped,
    ): int {
        $creds = $conn->credentials ?? [];
        $refreshToken = $creds['refresh_token'] ?? null;
        if (! $refreshToken) {
            $this->warn("Connection #{$conn->id}: kein refresh_token.");
            return 0;
        }

        // Token refreshen + (rotierten) refresh_token zurückschreiben.
        $tokens = $plaud->refresh($refreshToken);
        $access = $tokens['access_token'];
        if ($tokens['refresh_token'] && $tokens['refresh_token'] !== $refreshToken) {
            $conn->credentials = array_merge($creds, ['refresh_token' => $tokens['refresh_token']]);
            $conn->save();
        }

        $user = User::find($conn->owner_user_id);
        if (! $user) {
            $this->warn("Connection #{$conn->id}: Owner-User fehlt.");
            return 0;
        }
        $context = new ToolContext($user, $user->currentTeam);
        $tool = app($syncToolClass);

        $files = $plaud->listFiles($access, $from, $to);
        $imported = 0;

        foreach ($files as $file) {
            $fileId = (string) ($file['id'] ?? '');
            if ($fileId === '') {
                continue;
            }

            $full = $plaud->getFile($access, $fileId);
            $segments = $plaud->transcript($access, $full);
            if (empty($segments)) {
                continue; // ohne Transkript kein sinnvoller Import
            }

            $result = $tool->execute([
                'file_id'      => $fileId,
                'title'        => (string) ($file['name'] ?? $full['name'] ?? 'Plaud-Aufnahme'),
                'note_content' => (string) ($plaud->summary($access, $full) ?? ''),
                'segments'     => $segments,
                'metadata'     => [
                    'start_at'      => $file['start_at'] ?? $full['start_at'] ?? null,
                    'duration_ms'   => $file['duration'] ?? $full['duration'] ?? null,
                    'serial_number' => $file['serial_number'] ?? $full['serial_number'] ?? null,
                ],
                'language' => 'de',
            ], $context);

            $data = is_array($result->data ?? null) ? $result->data : [];
            if (! empty($data['duplicate'])) {
                $skipped++;
            } else {
                $imported++;
            }
        }

        $conn->forceFill(['last_tested_at' => now()])->save();

        return $imported;
    }
}
