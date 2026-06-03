<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_connector_mail_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('connection_id')
                ->nullable()
                ->constrained('user_connector_connections')
                ->nullOnDelete();

            $table->string('connector_key');                // 'microsoft365'
            $table->string('external_mail_id');              // Graph Mail ID
            $table->string('conversation_id')->nullable();   // Thread-Kontext
            $table->string('direction')->nullable();          // 'inbound', 'outbound'
            $table->string('status')->default('new');         // new, read

            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->text('to_addresses')->nullable();         // Kommasepariert
            $table->text('cc_addresses')->nullable();         // Kommasepariert
            $table->string('subject')->nullable();
            $table->text('body_preview')->nullable();

            $table->boolean('is_read')->default(false);
            $table->boolean('has_attachments')->default(false);
            $table->boolean('is_draft')->default(false);

            $table->string('shared_mailbox')->nullable();

            $table->timestamp('received_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('external_mail_id', 'ucms_external_mail_id_idx');
            $table->index('conversation_id', 'ucms_conversation_id_idx');
            $table->index(['connector_key', 'status'], 'ucms_connector_status_idx');
            $table->index(['connection_id', 'received_at'], 'ucms_connection_received_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_connector_mail_sessions');
    }
};
