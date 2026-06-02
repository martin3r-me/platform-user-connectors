<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('user_connectors')->insert([
            'key' => 'vodafone',
            'name' => 'Vodafone Business UC',
            'is_enabled' => true,
            'capabilities' => json_encode(['calls', 'messages']),
            'supported_auth_schemes' => json_encode(['oauth2']),
            'meta' => json_encode([
                'description' => 'Anrufe, SMS und Voicemail via Vodafone (RingCentral)',
                'icon' => 'heroicon-o-phone',
                'documentation_url' => 'https://developers.ringcentral.biz/api-reference',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('user_connectors')->where('key', 'vodafone')->delete();
    }
};
