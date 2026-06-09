<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_connector_message_sessions', function (Blueprint $table) {
            // Teams-Chat-Aggregation: pro chat_id + connection_id eine Session;
            // jede neue Nachricht inkrementiert message_count und setzt
            // last_message_at. external_message_id zeigt auf die letzte
            // Nachricht (für mail.forward/quote-Operationen weiterhin nützlich).
            if (!Schema::hasColumn('user_connector_message_sessions', 'message_count')) {
                $table->unsignedInteger('message_count')->default(1)->after('chat_id');
            }
            if (!Schema::hasColumn('user_connector_message_sessions', 'last_message_at')) {
                $table->timestamp('last_message_at')->nullable()->after('message_count');
            }
        });

        // Backfill für bestehende Rows: last_message_at = sent_at, message_count
        // bleibt auf 1 (jede alte Row war ja genau eine Message).
        DB::statement('UPDATE user_connector_message_sessions SET last_message_at = sent_at WHERE last_message_at IS NULL');

        Schema::table('user_connector_message_sessions', function (Blueprint $table) {
            // Index für Lookup-Pfad chat_id + connection_id beim Upsert.
            $table->index(['connection_id', 'chat_id'], 'ucms_conn_chat_idx');
        });

        // Detail-Tabelle für die einzelnen Messages eines Threads. Damit
        // bleibt der komplette Verlauf erhalten (Show-View zeigt ihn an),
        // ohne die Aggregations-Row aufzublähen.
        Schema::create('user_connector_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_session_id')
                ->constrained('user_connector_message_sessions')
                ->cascadeOnDelete();
            $table->string('external_message_id');
            $table->string('from_identifier')->nullable();
            $table->string('from_user_id')->nullable();
            $table->text('body_preview')->nullable();
            $table->longText('body')->nullable();
            $table->string('importance', 50)->nullable();
            $table->string('direction')->nullable();   // inbound / outbound aus Empfänger-Sicht
            $table->timestamp('sent_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(
                ['message_session_id', 'external_message_id'],
                'uccm_session_message_uq',
            );
            $table->index(['message_session_id', 'sent_at'], 'uccm_session_sent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_connector_chat_messages');

        Schema::table('user_connector_message_sessions', function (Blueprint $table) {
            $table->dropIndex('ucms_conn_chat_idx');
            $table->dropColumn(['message_count', 'last_message_at']);
        });
    }
};
