<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Race condition on the MS365 CallRecords webhook produced multiple rows
     * per (connection_id, external_call_id) — Connection #14 had 3 sessions
     * for the same call. Drop the duplicates (keep oldest by id) and add a
     * unique index so future races fail at the DB layer.
     */
    public function up(): void
    {
        $dupGroups = DB::table('user_connector_call_sessions')
            ->select('connection_id', 'external_call_id', DB::raw('MIN(id) as keep_id'))
            ->whereNotNull('external_call_id')
            ->groupBy('connection_id', 'external_call_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($dupGroups as $group) {
            DB::table('user_connector_call_sessions')
                ->where('connection_id', $group->connection_id)
                ->where('external_call_id', $group->external_call_id)
                ->where('id', '!=', $group->keep_id)
                ->delete();
        }

        Schema::table('user_connector_call_sessions', function (Blueprint $table) {
            $table->unique(['connection_id', 'external_call_id'], 'uccs_conn_extcall_uq');
        });
    }

    public function down(): void
    {
        Schema::table('user_connector_call_sessions', function (Blueprint $table) {
            $table->dropUnique('uccs_conn_extcall_uq');
        });
    }
};
