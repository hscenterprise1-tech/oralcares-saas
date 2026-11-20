<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Physiotherapy by problem (`PhysioCatalogue` problems):
     *
     * - a course (`patient_treatments`) treats one problem on one side, at a
     *   session type — "Knee pain · Right · Full treatment";
     * - a session records the type given that day, the add-ons (cupping…)
     *   and what it charged per visit, so history shows each day exactly.
     */
    public function up(): void
    {
        Schema::table('patient_treatments', function (Blueprint $table) {
            $table->string('physio_problem', 64)->nullable()->after('specialty_key');
            $table->string('body_side', 10)->nullable()->after('physio_problem');
            $table->string('session_type', 64)->nullable()->after('body_side');
        });

        Schema::table('physio_sessions', function (Blueprint $table) {
            $table->string('session_type', 64)->nullable()->after('given');
            $table->json('addons')->nullable()->after('session_type');
            $table->decimal('charge', 10, 2)->nullable()->after('addons');
        });
    }

    public function down(): void
    {
        Schema::table('physio_sessions', function (Blueprint $table) {
            $table->dropColumn(['session_type', 'addons', 'charge']);
        });
        Schema::table('patient_treatments', function (Blueprint $table) {
            $table->dropColumn(['physio_problem', 'body_side', 'session_type']);
        });
    }
};
