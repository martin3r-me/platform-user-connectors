<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_connector_connections', function (Blueprint $table) {
            $table->id();

            $table->foreignId('connector_id')
                ->constrained('user_connectors')
                ->cascadeOnDelete();

            $table->foreignId('owner_user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->string('auth_scheme')->default('oauth2');
            $table->string('status')->default('draft');
            $table->json('capabilities')->nullable();

            // Encrypted credentials (JSON)
            $table->longText('credentials')->nullable();
            $table->string('credentials_hash')->nullable();

            $table->timestamp('last_tested_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['connector_id', 'owner_user_id', 'name'], 'ucc_connector_user_name_uniq');
            $table->index(['connector_id', 'status'], 'ucc_connector_status_idx');
            $table->index(['owner_user_id'], 'ucc_owner_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_connector_connections');
    }
};
