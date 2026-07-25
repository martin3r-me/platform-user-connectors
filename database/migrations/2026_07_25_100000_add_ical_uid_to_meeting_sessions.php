<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * iCalUId: die EINE über-Postfach- und über-Vorkommen-stabile Termin-Identität aus
 * MS365 (identisch bei allen Beteiligten UND allen Serien-Vorkommen). Basis dafür,
 * dass derselbe reale Termin zu genau EINER geteilten Meeting-Instanz kollabiert.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_connector_meeting_sessions')) {
            return;
        }

        Schema::table('user_connector_meeting_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('user_connector_meeting_sessions', 'ical_uid')) {
                $table->string('ical_uid')->nullable()->after('series_master_id');
                $table->index('ical_uid', 'ucmts_ical_uid_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_connector_meeting_sessions')) {
            return;
        }

        Schema::table('user_connector_meeting_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('user_connector_meeting_sessions', 'ical_uid')) {
                $table->dropIndex('ucmts_ical_uid_idx');
                $table->dropColumn('ical_uid');
            }
        });
    }
};
