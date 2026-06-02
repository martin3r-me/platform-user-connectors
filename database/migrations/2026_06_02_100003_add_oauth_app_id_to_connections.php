<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_connector_connections', function (Blueprint $table) {
            $table->foreignId('oauth_app_id')
                ->nullable()
                ->after('connector_id')
                ->constrained('user_connector_oauth_apps')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_connector_connections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('oauth_app_id');
        });
    }
};
