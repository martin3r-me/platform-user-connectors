<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_connector_devices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('connection_id')
                ->constrained('user_connector_connections')
                ->cascadeOnDelete();

            $table->string('name');                   // "IP-Telefon Büro", "Softphone"
            $table->string('type');                   // softphone, deskphone, mobile, webrtc, faxline, sms
            $table->string('external_id');            // Provider-ID (Sipgate deviceId, RC deviceId)
            $table->boolean('is_online')->nullable(); // nullable = unknown
            $table->json('meta')->nullable();         // model, serial, firmware, etc.

            $table->timestamps();

            $table->index('connection_id', 'ucd_connection_id_idx');
            $table->index(['connection_id', 'type'], 'ucd_connection_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_connector_devices');
    }
};
