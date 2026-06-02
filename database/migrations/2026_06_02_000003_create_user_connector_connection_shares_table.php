<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_connector_connection_shares', function (Blueprint $table) {
            $table->id();

            $table->foreignId('connection_id')
                ->constrained('user_connector_connections')
                ->cascadeOnDelete();

            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->cascadeOnDelete();

            // Capability scope: null = full access, ['messages'] = only messages
            $table->json('capability_scope')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_connector_connection_shares');
    }
};
