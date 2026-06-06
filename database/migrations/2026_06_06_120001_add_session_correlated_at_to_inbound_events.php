<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_connector_inbound_events', function (Blueprint $table) {
            // Set when correlateSession() succeeded — distinguishes "webhook stored
            // and enrichment dispatched" (processing_status='processed') from
            // "session row actually created/updated".
            $table->timestamp('session_correlated_at')
                ->nullable()
                ->after('processing_error');

            $table->index(['connector_key', 'session_correlated_at'], 'uc_ie_correlated_idx');
        });
    }

    public function down(): void
    {
        Schema::table('user_connector_inbound_events', function (Blueprint $table) {
            $table->dropIndex('uc_ie_correlated_idx');
            $table->dropColumn('session_correlated_at');
        });
    }
};
