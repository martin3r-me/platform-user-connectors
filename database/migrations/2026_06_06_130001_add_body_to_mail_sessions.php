<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_connector_mail_sessions', function (Blueprint $table) {
            // Full plain-text body. body_preview stays for fast list rendering;
            // body is the canonical input for downstream consumers (inbox enrichment).
            $table->longText('body')->nullable()->after('body_preview');
        });
    }

    public function down(): void
    {
        Schema::table('user_connector_mail_sessions', function (Blueprint $table) {
            $table->dropColumn('body');
        });
    }
};
