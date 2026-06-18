<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persistent "display name" for the aggregated chat session:
     *   - oneOnOne → name of the other participant
     *   - group    → chat topic (or joined member names as fallback)
     *   - meeting  → meeting subject
     *
     * Distinct from from_identifier (which tracks the latest sender and
     * gets overwritten on every message). The inbox uses this column as
     * the stable label so users don't see their own name in the stream
     * when they happened to send the last reply.
     */
    public function up(): void
    {
        Schema::table('user_connector_message_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('user_connector_message_sessions', 'chat_display_name')) {
                $table->string('chat_display_name')->nullable()->after('chat_id');
            }
            if (!Schema::hasColumn('user_connector_message_sessions', 'chat_type')) {
                $table->string('chat_type', 32)->nullable()->after('chat_display_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_connector_message_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('user_connector_message_sessions', 'chat_type')) {
                $table->dropColumn('chat_type');
            }
            if (Schema::hasColumn('user_connector_message_sessions', 'chat_display_name')) {
                $table->dropColumn('chat_display_name');
            }
        });
    }
};
