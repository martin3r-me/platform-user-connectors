<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B: Serien-Identität aus MS365 (Graph seriesMasterId + type) auf der
 * Meeting-Session ablegen, damit wiederkehrende Termine gruppierbar werden
 * (eine Meeting-Instanz pro Serie).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_connector_meeting_sessions', function (Blueprint $table) {
            $table->string('series_master_id')->nullable()->after('external_event_id');
            $table->string('occurrence_type')->nullable()->after('series_master_id');

            $table->index('series_master_id', 'ucmts_series_master_idx');
        });
    }

    public function down(): void
    {
        Schema::table('user_connector_meeting_sessions', function (Blueprint $table) {
            $table->dropIndex('ucmts_series_master_idx');
            $table->dropColumn(['series_master_id', 'occurrence_type']);
        });
    }
};
