<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_connectors', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->boolean('is_enabled')->default(true);
            $table->json('capabilities')->nullable();
            $table->json('supported_auth_schemes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        // Seed default connectors
        DB::table('user_connectors')->insert([
            [
                'key' => 'microsoft365',
                'name' => 'Microsoft 365',
                'is_enabled' => true,
                'capabilities' => json_encode(['messages', 'calendar', 'presence']),
                'supported_auth_schemes' => json_encode(['oauth2']),
                'meta' => json_encode([
                    'description' => 'Outlook Mail, Kalender und Microsoft Teams',
                    'icon' => 'heroicon-o-envelope',
                    'documentation_url' => 'https://learn.microsoft.com/en-us/graph/overview',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'ringcentral',
                'name' => 'RingCentral',
                'is_enabled' => true,
                'capabilities' => json_encode(['calls', 'messages']),
                'supported_auth_schemes' => json_encode(['oauth2']),
                'meta' => json_encode([
                    'description' => 'Anrufe, SMS und Voicemail',
                    'icon' => 'heroicon-o-phone',
                    'documentation_url' => 'https://developers.ringcentral.com/api-reference',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'sipgate',
                'name' => 'Sipgate',
                'is_enabled' => true,
                'capabilities' => json_encode(['calls', 'messages']),
                'supported_auth_schemes' => json_encode(['oauth2']),
                'meta' => json_encode([
                    'description' => 'Anrufe, SMS und Voicemail via Sipgate',
                    'icon' => 'heroicon-o-phone-arrow-up-right',
                    'documentation_url' => 'https://developer.sipgate.io/',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_connectors');
    }
};
