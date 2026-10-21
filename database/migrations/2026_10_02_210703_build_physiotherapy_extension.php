<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The physiotherapy extensions (registry: `physio_assessment`, `soap_notes`,
 * `exercise_plan`, `session_packages`), hanging off Patient → Encounter like
 * every specialty extension:
 *
 * - `physio_assessments`: one per assessment consultation — body chart,
 *   pain, range of motion, strength, posture, function, goals.
 * - `physio_care_plans` + `physio_exercises`: the plan of care and its
 *   home / in-clinic exercises.
 * - `physio_sessions`: one SOAP note per treatment session (consultation),
 *   with pain before and after, modalities, and the bed or room used.
 * - `patient_session_packages` + `session_package_usages`: prepaid session
 *   packages and every session punched against one.
 *
 * Every row is clinic-owned; rows that hang off an encounter or plan can
 * only do so within the same clinic (composite foreign keys).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('physio_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('encounter_id')->unique();
            $table->text('presenting_problem')->nullable();
            $table->json('body_chart')->nullable();
            $table->unsignedTinyInteger('pain_at_rest')->nullable();
            $table->unsignedTinyInteger('pain_on_activity')->nullable();
            $table->json('range_of_motion')->nullable();
            $table->json('strength')->nullable();
            $table->text('posture')->nullable();
            $table->json('functional_measures')->nullable();
            $table->text('functional_notes')->nullable();
            $table->text('goals')->nullable();
            $table->foreignId('assessed_by')->nullable()->constrained('clinic_employees')->restrictOnDelete();
            $table->timestamps();

            $table->foreign(['encounter_id', 'clinic_id'], 'physio_assessments_same_clinic_fk')
                ->references(['id', 'clinic_id'])->on('clinical_encounters')->restrictOnDelete();
            $table->index(['clinic_id', 'patient_id']);
        });

        Schema::create('physio_care_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained('clinical_encounters')->nullOnDelete();
            $table->foreignId('assessment_id')->nullable()->constrained('physio_assessments')->nullOnDelete();
            $table->string('title', 150);
            $table->text('goals')->nullable();
            $table->json('interventions')->nullable();
            $table->unsignedSmallInteger('planned_sessions')->nullable();
            $table->string('frequency', 60)->nullable();
            $table->date('starts_on')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('closed_at')->nullable();
            $table->string('closing_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('clinic_employees')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['id', 'clinic_id']);
            $table->index(['clinic_id', 'patient_id', 'status']);
        });

        Schema::create('physio_exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('care_plan_id');
            $table->string('name', 150);
            $table->unsignedSmallInteger('sets')->nullable();
            $table->unsignedSmallInteger('reps')->nullable();
            $table->unsignedSmallInteger('hold_seconds')->nullable();
            $table->string('frequency', 60)->nullable();
            $table->string('instructions', 500)->nullable();
            $table->boolean('is_home')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign(['care_plan_id', 'clinic_id'], 'physio_exercises_same_clinic_fk')
                ->references(['id', 'clinic_id'])->on('physio_care_plans')->cascadeOnDelete();
        });

        Schema::create('patient_session_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('clinical_procedure_id')->nullable()->constrained('clinical_procedures')->restrictOnDelete();
            $table->string('name', 150);
            $table->unsignedSmallInteger('sessions_total');
            $table->unsignedSmallInteger('sessions_used')->default(0);
            $table->date('valid_until')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('patient_billing_id')->nullable()->constrained('patient_billings')->restrictOnDelete();
            $table->foreignId('sold_by')->nullable()->constrained('clinic_employees')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['id', 'clinic_id']);
            $table->index(['clinic_id', 'patient_id', 'status']);
        });

        Schema::create('physio_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('encounter_id')->unique();
            $table->foreignId('care_plan_id')->nullable()->constrained('physio_care_plans')->nullOnDelete();
            $table->unsignedSmallInteger('session_number')->nullable();
            $table->text('subjective')->nullable();
            $table->text('objective')->nullable();
            $table->text('assessment')->nullable();
            $table->text('plan')->nullable();
            $table->unsignedTinyInteger('pain_before')->nullable();
            $table->unsignedTinyInteger('pain_after')->nullable();
            $table->json('modalities')->nullable();
            $table->foreignId('room_id')->nullable()->constrained('clinic_rooms')->nullOnDelete();
            $table->foreignId('package_id')->nullable()->constrained('patient_session_packages')->restrictOnDelete();
            $table->foreignId('therapist_id')->nullable()->constrained('clinic_employees')->restrictOnDelete();
            $table->timestamps();

            $table->foreign(['encounter_id', 'clinic_id'], 'physio_sessions_same_clinic_fk')
                ->references(['id', 'clinic_id'])->on('clinical_encounters')->restrictOnDelete();
            $table->index(['clinic_id', 'patient_id', 'created_at']);
        });

        Schema::create('session_package_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('package_id');
            $table->foreignId('physio_session_id')->unique()->constrained('physio_sessions')->cascadeOnDelete();
            $table->foreignId('used_by')->nullable()->constrained('clinic_employees')->restrictOnDelete();
            $table->timestamps();

            $table->foreign(['package_id', 'clinic_id'], 'session_package_usages_same_clinic_fk')
                ->references(['id', 'clinic_id'])->on('patient_session_packages')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_package_usages');
        Schema::dropIfExists('physio_sessions');
        Schema::dropIfExists('patient_session_packages');
        Schema::dropIfExists('physio_exercises');
        Schema::dropIfExists('physio_care_plans');
        Schema::dropIfExists('physio_assessments');
    }
};
