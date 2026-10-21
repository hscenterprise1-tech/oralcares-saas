<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Care actually started for a patient.
 *
 *   patient_treatments        one treatment: the pinned catalog version and
 *                             prices it started on (or a legacy record it was
 *                             migrated from), its status and progress
 *   patient_treatment_stages  its stages, with names/order and stage prices
 *                             snapshotted at start
 *   patient_treatment_teeth   dental extension: the teeth it targets
 *
 * Catalog changes never reach these rows; history stays as it happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_treatments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('patient_specialty_id')->nullable();
            $table->string('specialty_key', 50)->nullable();
            $table->string('source', 20)->default('catalog');
            $table->unsignedBigInteger('catalog_treatment_id')->nullable();
            $table->unsignedBigInteger('catalog_treatment_version_id')->nullable();
            $table->foreignId('treatment_plan_id')->nullable()->constrained('treatment_plans')->nullOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained('clinical_encounters')->nullOnDelete();
            $table->unsignedBigInteger('practitioner_id')->nullable();
            $table->string('title', 255);
            $table->string('code', 40)->nullable();
            $table->string('treatment_type', 20)->nullable();
            $table->string('charging_model', 20)->nullable();
            $table->string('status', 20);
            $table->unsignedBigInteger('price_version_id')->nullable();
            $table->decimal('agreed_amount', 12, 2)->nullable();
            $table->char('currency', 3)->default('INR');
            $table->json('price_snapshot')->nullable();
            $table->unsignedSmallInteger('stages_total')->default(0);
            $table->unsignedSmallInteger('stages_completed')->default(0);
            $table->unsignedSmallInteger('sessions_planned')->nullable();
            $table->unsignedSmallInteger('sessions_completed')->default(0);
            $table->date('valid_until')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('status_reason', 500)->nullable();
            $table->unsignedBigInteger('legacy_treatment_record_id')->nullable()->unique();
            $table->unsignedBigInteger('legacy_physio_care_plan_id')->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['patient_id', 'clinic_id'], 'pt_patient_same_clinic_fk')->references(['id', 'clinic_id'])->on('patients')->cascadeOnDelete();
            $table->foreign(['branch_id', 'clinic_id'], 'pt_branch_same_clinic_fk')->references(['id', 'clinic_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['practitioner_id', 'clinic_id'], 'pt_practitioner_same_clinic_fk')->references(['id', 'clinic_id'])->on('clinic_employees')->restrictOnDelete();
            $table->foreign(['catalog_treatment_id', 'clinic_id'], 'pt_catalog_same_clinic_fk')->references(['id', 'clinic_id'])->on('catalog_treatments')->restrictOnDelete();
            $table->foreign(['catalog_treatment_version_id', 'clinic_id'], 'pt_version_same_clinic_fk')->references(['id', 'clinic_id'])->on('catalog_treatment_versions')->restrictOnDelete();
            $table->foreign(['price_version_id', 'clinic_id'], 'pt_price_same_clinic_fk')->references(['id', 'clinic_id'])->on('catalog_price_versions')->restrictOnDelete();
            $table->foreign('patient_specialty_id', 'pt_patient_specialty_fk')->references('id')->on('patient_specialties')->nullOnDelete();
            $table->unique(['id', 'clinic_id'], 'pt_id_clinic_unique');
            $table->index(['clinic_id', 'patient_id', 'status'], 'pt_patient_status_index');
            $table->index(['clinic_id', 'status', 'branch_id'], 'pt_clinic_status_branch_index');
            $table->index(['clinic_id', 'practitioner_id', 'status'], 'pt_practitioner_status_index');
            $table->index(['clinic_id', 'catalog_treatment_version_id'], 'pt_version_index');
        });

        Schema::create('patient_treatment_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('patient_treatment_id');
            $table->unsignedBigInteger('catalog_stage_id')->nullable();
            $table->unsignedSmallInteger('position');
            $table->string('name', 255);
            $table->boolean('is_optional')->default(false);
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('price_version_id')->nullable();
            $table->decimal('agreed_amount', 12, 2)->nullable();
            $table->unsignedSmallInteger('sessions_planned')->nullable();
            $table->unsignedSmallInteger('sessions_completed')->default(0);
            $table->foreignId('encounter_id')->nullable()->constrained('clinical_encounters')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign(['patient_treatment_id', 'clinic_id'], 'pts_treatment_same_clinic_fk')->references(['id', 'clinic_id'])->on('patient_treatments')->cascadeOnDelete();
            $table->foreign(['catalog_stage_id', 'clinic_id'], 'pts_catalog_stage_same_clinic_fk')->references(['id', 'clinic_id'])->on('catalog_stages')->restrictOnDelete();
            $table->foreign(['price_version_id', 'clinic_id'], 'pts_price_same_clinic_fk')->references(['id', 'clinic_id'])->on('catalog_price_versions')->restrictOnDelete();
            $table->unique(['patient_treatment_id', 'position'], 'pts_treatment_position_unique');
            $table->unique(['id', 'clinic_id'], 'pts_id_clinic_unique');
        });

        Schema::create('patient_treatment_teeth', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('patient_treatment_id');
            $table->unsignedTinyInteger('tooth_number');
            $table->json('surfaces')->nullable();
            $table->timestamps();

            $table->foreign(['patient_treatment_id', 'clinic_id'], 'ptt_treatment_same_clinic_fk')->references(['id', 'clinic_id'])->on('patient_treatments')->cascadeOnDelete();
            $table->unique(['patient_treatment_id', 'tooth_number'], 'ptt_treatment_tooth_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_treatment_teeth');
        Schema::dropIfExists('patient_treatment_stages');
        Schema::dropIfExists('patient_treatments');
    }
};
