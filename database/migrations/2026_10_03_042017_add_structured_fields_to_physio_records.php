<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured physiotherapy records.
 *
 * Assessment: initial or reassessment, the subjective history (location,
 * onset, aggravating/relieving factors), objective findings, gait, special
 * tests, the clinical impression and the working diagnosis.
 *
 * Session: what was done, the exercises performed with their dose, how the
 * patient responded, the home programme given and the next step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('physio_assessments', function (Blueprint $table) {
            $table->string('kind', 20)->default('initial')->after('encounter_id');
            $table->string('pain_location', 255)->nullable();
            $table->string('onset', 255)->nullable();
            $table->text('aggravating_factors')->nullable();
            $table->text('relieving_factors')->nullable();
            $table->text('objective_findings')->nullable();
            $table->text('gait')->nullable();
            $table->json('special_tests')->nullable();
            $table->text('clinical_impression')->nullable();
            $table->string('diagnosis', 500)->nullable();
            $table->index(['clinic_id', 'patient_id', 'created_at'], 'physio_assessments_patient_index');
        });

        Schema::table('physio_sessions', function (Blueprint $table) {
            $table->text('treatment_performed')->nullable();
            $table->json('exercises_performed')->nullable();
            $table->text('patient_response')->nullable();
            $table->text('home_program')->nullable();
            $table->string('next_step', 500)->nullable();
            $table->index(['clinic_id', 'patient_id', 'created_at'], 'physio_sessions_patient_index');
        });
    }

    public function down(): void
    {
        Schema::table('physio_sessions', function (Blueprint $table) {
            $table->dropIndex('physio_sessions_patient_index');
            $table->dropColumn(['treatment_performed', 'exercises_performed', 'patient_response', 'home_program', 'next_step']);
        });

        Schema::table('physio_assessments', function (Blueprint $table) {
            $table->dropIndex('physio_assessments_patient_index');
            $table->dropColumn(['kind', 'pain_location', 'onset', 'aggravating_factors', 'relieving_factors', 'objective_findings', 'gait', 'special_tests', 'clinical_impression', 'diagnosis']);
        });
    }
};
