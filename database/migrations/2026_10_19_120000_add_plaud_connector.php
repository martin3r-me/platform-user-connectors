<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plaud als Connector-Typ: persönliche Sprach-Aufnahmen/Transkripte. Auth-Scheme
 * 'token' (kein OAuth-Redirect) — der refresh_token wird in der UI eingefügt
 * (einmal `plaud login`), der PlaudSync-Command refresht + zieht Aufnahmen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('user_connectors')->where('key', 'plaud')->exists()) {
            return;
        }

        DB::table('user_connectors')->insert([
            'key' => 'plaud',
            'name' => 'Plaud',
            'is_enabled' => true,
            'capabilities' => json_encode(['recordings']),
            'supported_auth_schemes' => json_encode(['token']),
            'meta' => json_encode([
                'description' => 'Sprach-Aufnahmen & Transkripte (Plaud-Gerät) — landen automatisch im Eingang.',
                'icon' => 'heroicon-o-microphone',
                'documentation_url' => 'https://docs.plaud.ai',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('user_connectors')->where('key', 'plaud')->delete();
    }
};
