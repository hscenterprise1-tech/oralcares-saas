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
        // 1. Harden patients.clinic_id to RESTRICT
        Schema::table('patients', function (Blueprint $table) {
            $table->dropForeign(['clinic_id']);

            $table->foreign('clinic_id', 'patients_clinic_id_foreign')
                ->references('id')
                ->on('clinics')
                ->restrictOnDelete();
        });

        // 2. Harden patient_visits.patient_id to RESTRICT
        Schema::table('patient_visits', function (Blueprint $table) {
            $table->dropForeign(['patient_id']);

            $table->foreign('patient_id', 'patient_visits_patient_id_foreign')
                ->references('id')
                ->on('patients')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Zero-cascade rule: do NOT restore CASCADE on rollback.
        // Constraints remain RESTRICT/NO ACTION.
    }
};
