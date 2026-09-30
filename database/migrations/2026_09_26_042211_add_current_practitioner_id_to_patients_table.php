<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            // References `clinic_employees.id`, not `doctor_profiles.id` —
            // matching `Appointment.practitioner_id` and
            // `PatientVisit.doctor_id`, the two existing "who is the
            // doctor" conventions already in this schema (`ClinicalEncounter.
            // provider_id` is the one inconsistent outlier, pointing at
            // `doctor_profiles` instead — a pre-existing drift, not
            // something this migration follows). Nullable: most patients
            // have never been assigned a doctor at all.
            $table->foreignId('current_practitioner_id')->nullable()->after('status')
                ->constrained('clinic_employees')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_practitioner_id');
        });
    }
};
