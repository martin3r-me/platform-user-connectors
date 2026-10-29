<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kalender-Spiegel: fremde Termine (z. B. geplante Huddles aus Parlan) als Kopie im Kalender
 * des Organisators. Eine Zeile je (Quelle, Schlüssel) merkt sich den Provider-Termin und einen
 * Fingerabdruck — so wird nur bei Änderungen geschrieben und eine Absage genau einmal versandt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_connector_calendar_mirrors')) {
            return;
        }
        Schema::create('user_connector_calendar_mirrors', function (Blueprint $table) {
            $table->id();
            $table->string('source', 64);
            $table->string('external_key', 191);
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('connection_id');
            $table->string('provider_event_id', 512);
            $table->string('fingerprint', 64);
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();
            $table->unique(['source', 'external_key'], 'ucm_source_key_unique');
            $table->index('user_id', 'ucm_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_connector_calendar_mirrors');
    }
};
