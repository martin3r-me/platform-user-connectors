<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_connector_inbound_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('connection_id')
                ->nullable()
                ->constrained('user_connector_connections')
                ->nullOnDelete();

            $table->string('connector_key');         // 'sipgate', 'microsoft365', 'ringcentral'
            $table->string('event_type');             // 'call.new', 'call.answered', 'call.hangup', 'sms.inbound', 'mail.new', 'calendar.updated'
            $table->string('direction')->nullable();  // 'inbound', 'outbound'
            $table->string('external_id')->nullable(); // Provider-specific ID (callId, messageId, etc.)
            $table->string('idempotency_key')->nullable()->unique();

            // Caller/Callee or From/To
            $table->string('from_identifier')->nullable(); // phone number, email, etc.
            $table->string('to_identifier')->nullable();

            $table->json('payload');                  // Full raw webhook payload
            $table->json('meta')->nullable();         // Extracted/enriched metadata

            $table->string('processing_status')->default('pending'); // pending, processing, processed, failed
            $table->text('processing_error')->nullable();

            $table->timestamp('event_timestamp')->nullable(); // When the event actually happened
            $table->timestamps();

            $table->index(['connector_key', 'event_type'], 'ucie_connector_event_idx');
            $table->index(['connection_id', 'event_type'], 'ucie_connection_event_idx');
            $table->index(['processing_status'], 'ucie_status_idx');
            $table->index(['event_timestamp'], 'ucie_timestamp_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_connector_inbound_events');
    }
};
