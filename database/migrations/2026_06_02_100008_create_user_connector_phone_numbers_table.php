<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_connector_phone_numbers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('connection_id')
                ->constrained('user_connector_connections')
                ->cascadeOnDelete();

            $table->string('number');               // E.164: +4917647803970
            $table->string('label')->nullable();     // "Hauptnummer", "Fax"
            $table->string('type');                  // voice, fax, sms, voip
            $table->json('capabilities')->nullable(); // ['voice', 'sms', 'fax']
            $table->boolean('is_default')->default(false);
            $table->string('external_id')->nullable(); // Provider-ID (Sipgate extension-ID, RC phoneNumberId)
            $table->json('meta')->nullable();         // routing, endpointId, etc.

            $table->timestamps();

            $table->index('connection_id', 'ucpn_connection_id_idx');
            $table->index(['connection_id', 'type'], 'ucpn_connection_type_idx');
            $table->index(['connection_id', 'is_default'], 'ucpn_connection_default_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_connector_phone_numbers');
    }
};
