<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_connector_meeting_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('connection_id')
                ->nullable()
                ->constrained('user_connector_connections')
                ->nullOnDelete();

            $table->string('connector_key');                   // 'microsoft365'
            $table->string('external_event_id');                // Graph Calendar Event ID
            $table->string('direction')->nullable();            // outbound = Organizer, inbound = Teilnehmer
            $table->string('status')->default('upcoming');      // upcoming, in_progress, completed, cancelled, deleted

            $table->string('organizer_address')->nullable();
            $table->string('organizer_name')->nullable();
            $table->text('attendee_addresses')->nullable();     // Kommasepariert

            $table->string('subject')->nullable();
            $table->text('body_preview')->nullable();
            $table->string('location')->nullable();

            $table->boolean('is_online_meeting')->default(false);
            $table->string('online_meeting_url')->nullable();

            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('external_event_id', 'ucmts_external_event_id_idx');
            $table->index(['connector_key', 'status'], 'ucmts_connector_status_idx');
            $table->index(['connection_id', 'start_at'], 'ucmts_connection_start_idx');
            $table->index(['status', 'start_at'], 'ucmts_status_start_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_connector_meeting_sessions');
    }
};
