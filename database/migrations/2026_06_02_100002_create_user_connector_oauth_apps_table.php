<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_connector_oauth_apps', function (Blueprint $table) {
            $table->id();

            $table->foreignId('connector_id')
                ->constrained('user_connectors')
                ->cascadeOnDelete();

            $table->string('name');
            $table->longText('settings')->nullable(); // encrypted JSON: client_id, client_secret
            $table->string('settings_hash')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['connector_id', 'name'], 'uc_oauth_apps_connector_name_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_connector_oauth_apps');
    }
};
