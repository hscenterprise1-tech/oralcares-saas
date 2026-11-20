<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each treatment given in a session, as the physio consultation records
     * it: `[{key, label, minutes, price, therapist_id}]` — the price as it
     * was that day (the owner's list may change later), and who gave it.
     */
    public function up(): void
    {
        Schema::table('physio_sessions', function (Blueprint $table) {
            $table->json('given')->nullable()->after('modalities');
        });
    }

    public function down(): void
    {
        Schema::table('physio_sessions', function (Blueprint $table) {
            $table->dropColumn('given');
        });
    }
};
