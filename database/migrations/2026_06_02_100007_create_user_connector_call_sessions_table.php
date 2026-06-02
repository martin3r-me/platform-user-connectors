<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_connector_call_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('connection_id')
                ->nullable()
                ->constrained('user_connector_connections')
                ->nullOnDelete();

            $table->string('connector_key');              // 'sipgate', 'ringcentral', 'vodafone'
            $table->string('external_call_id');            // callId (Sipgate) / sessionId (RingCentral)
            $table->string('direction')->nullable();       // 'inbound', 'outbound'
            $table->string('status')->default('ringing');  // ringing, active, completed, missed, busy, cancelled, failed

            $table->string('from_number')->nullable();
            $table->string('to_number')->nullable();
            $table->string('answering_number')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->string('hangup_cause')->nullable();    // normalClearing, busy, cancel, noAnswer, etc.
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index('external_call_id', 'uccs_external_call_id_idx');
            $table->index(['connector_key', 'status'], 'uccs_connector_status_idx');
            $table->index(['connection_id', 'started_at'], 'uccs_connection_started_idx');
            $table->index('status', 'uccs_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_connector_call_sessions');
    }
};
