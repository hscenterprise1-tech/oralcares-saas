<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds UHID, split name, and additional core fields to the patients table.
     * The legacy `patient_number` column is retained for backward compatibility.
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            // Permanent unique healthcare identifier per clinic (OC-00000001)
            $table->string('uhid')->nullable()->after('clinic_id');
            $table->unique(['clinic_id', 'uhid'], 'patients_clinic_uhid_unique');

            // Split name (backward compat: `name` column kept as-is)
            $table->string('first_name')->nullable()->after('uhid');
            $table->string('last_name')->nullable()->after('first_name');

            // Additional demographics
            $table->unsignedSmallInteger('age')->nullable()->after('date_of_birth');
            $table->string('alternate_phone')->nullable()->after('phone');
            $table->string('blood_group')->nullable()->after('gender');

            // Registration type for the patient record itself
            $table->string('registration_type')->default('new')->after('blood_group'); // new | repeat

            // Soft-deprecate patient_number — make nullable so UHID-based flow doesn't require it
            $table->string('patient_number')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropUnique('patients_clinic_uhid_unique');
            $table->dropColumn([
                'uhid',
                'first_name',
                'last_name',
                'age',
                'alternate_phone',
                'blood_group',
                'registration_type',
            ]);
        });
    }
};
