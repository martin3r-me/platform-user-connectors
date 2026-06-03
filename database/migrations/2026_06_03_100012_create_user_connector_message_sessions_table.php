<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_connector_message_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('connection_id')
                ->nullable()
                ->constrained('user_connector_connections')
                ->nullOnDelete();

            $table->string('connector_key');                   // 'microsoft365', 'sipgate', 'ringcentral', 'vodafone'
            $table->string('external_message_id');              // External message ID
            $table->string('message_type');                     // 'teams_chat', 'sms'
            $table->string('direction')->nullable();            // 'inbound', 'outbound'

            $table->string('from_identifier')->nullable();      // Name (Teams) oder Nummer (SMS)
            $table->string('from_user_id')->nullable();          // Graph User ID
            $table->string('to_identifier')->nullable();

            $table->text('body_preview')->nullable();
            $table->string('chat_id')->nullable();               // Teams Chat ID
            $table->string('importance')->nullable();

            $table->timestamp('sent_at')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('external_message_id', 'ucmsg_external_message_id_idx');
            $table->index(['connector_key', 'message_type'], 'ucmsg_connector_type_idx');
            $table->index(['connection_id', 'sent_at'], 'ucmsg_connection_sent_idx');
            $table->index('chat_id', 'ucmsg_chat_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_connector_message_sessions');
    }
};
