<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_connectors', function (Blueprint $table) {
            $table->text('settings')->nullable()->after('meta');
        });
    }

    public function down(): void
    {
        Schema::table('user_connectors', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};
