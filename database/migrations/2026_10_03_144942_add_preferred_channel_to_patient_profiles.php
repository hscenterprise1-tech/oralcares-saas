<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the patient prefers to be contacted. Whether they consented to it is
 * a `consent_records` row (type `communication`), not this column; the
 * clinic-level `communication_preferences` table is a different thing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_profiles', function (Blueprint $table) {
            $table->string('preferred_channel', 20)->nullable()->after('emergency_contact_phone');
        });
    }

    public function down(): void
    {
        Schema::table('patient_profiles', function (Blueprint $table) {
            $table->dropColumn('preferred_channel');
        });
    }
};
