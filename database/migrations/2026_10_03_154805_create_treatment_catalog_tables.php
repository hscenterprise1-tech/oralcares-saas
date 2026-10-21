<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The clinic's treatment catalog:
 *
 *   catalog_treatments          what can be started (per specialty)
 *   catalog_treatment_versions  its configuration; immutable once published
 *   catalog_stages              ordered stages of a version
 *   catalog_stage_procedures    procedures allowed/expected in a stage
 *   catalog_price_versions      immutable prices for a version (upfront),
 *                               a stage (per stage) or a procedure (per
 *                               procedure), clinic-wide or for a branch
 *
 * Every row carries `clinic_id`, and every parent link is a same-clinic
 * composite key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_treatments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->string('specialty_key', 50);
            $table->string('code', 40);
            $table->string('name', 160);
            $table->string('category', 80)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['clinic_id', 'code'], 'catalog_treatments_clinic_code_unique');
            $table->unique(['id', 'clinic_id'], 'catalog_treatments_id_clinic_unique');
            $table->index(['clinic_id', 'specialty_key', 'is_active'], 'catalog_treatments_selectable_index');
        });

        Schema::create('catalog_treatment_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('catalog_treatment_id');
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft');
            $table->string('treatment_type', 20);
            $table->string('charging_model', 20);
            $table->boolean('requires_tooth')->default(false);
            $table->unsignedSmallInteger('planned_sessions')->nullable();
            $table->unsignedSmallInteger('validity_days')->nullable();
            $table->unsignedSmallInteger('default_duration_minutes')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('retired_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['catalog_treatment_id', 'clinic_id'], 'ctv_treatment_same_clinic_fk')->references(['id', 'clinic_id'])->on('catalog_treatments')->cascadeOnDelete();
            $table->unique(['catalog_treatment_id', 'version'], 'ctv_treatment_version_unique');
            $table->unique(['id', 'clinic_id'], 'ctv_id_clinic_unique');
            $table->index(['clinic_id', 'catalog_treatment_id', 'status'], 'ctv_status_index');
        });

        Schema::create('catalog_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('catalog_treatment_version_id');
            $table->unsignedSmallInteger('position');
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('planned_sessions')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->boolean('is_optional')->default(false);
            $table->timestamps();

            $table->foreign(['catalog_treatment_version_id', 'clinic_id'], 'cs_version_same_clinic_fk')->references(['id', 'clinic_id'])->on('catalog_treatment_versions')->cascadeOnDelete();
            $table->unique(['catalog_treatment_version_id', 'position'], 'cs_version_position_unique');
            $table->unique(['id', 'clinic_id'], 'cs_id_clinic_unique');
        });

        Schema::create('catalog_stage_procedures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('catalog_stage_id');
            $table->unsignedBigInteger('clinical_procedure_id');
            $table->unsignedSmallInteger('position')->default(1);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->boolean('is_required')->default(true);
            $table->timestamps();

            $table->foreign(['catalog_stage_id', 'clinic_id'], 'csp_stage_same_clinic_fk')->references(['id', 'clinic_id'])->on('catalog_stages')->cascadeOnDelete();
            $table->foreign(['clinical_procedure_id', 'clinic_id'], 'csp_procedure_same_clinic_fk')->references(['id', 'clinic_id'])->on('clinical_procedures')->restrictOnDelete();
            $table->unique(['catalog_stage_id', 'clinical_procedure_id'], 'csp_stage_procedure_unique');
        });

        Schema::create('catalog_price_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('catalog_treatment_version_id')->nullable();
            $table->unsignedBigInteger('catalog_stage_id')->nullable();
            $table->unsignedBigInteger('clinical_procedure_id')->nullable();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('INR');
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->unsignedBigInteger('legacy_price_id')->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['branch_id', 'clinic_id'], 'cpv_branch_same_clinic_fk')->references(['id', 'clinic_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['catalog_treatment_version_id', 'clinic_id'], 'cpv_version_same_clinic_fk')->references(['id', 'clinic_id'])->on('catalog_treatment_versions')->restrictOnDelete();
            $table->foreign(['catalog_stage_id', 'clinic_id'], 'cpv_stage_same_clinic_fk')->references(['id', 'clinic_id'])->on('catalog_stages')->restrictOnDelete();
            $table->foreign(['clinical_procedure_id', 'clinic_id'], 'cpv_procedure_same_clinic_fk')->references(['id', 'clinic_id'])->on('clinical_procedures')->restrictOnDelete();
            $table->unique(['id', 'clinic_id'], 'cpv_id_clinic_unique');
            $table->index(['clinic_id', 'clinical_procedure_id', 'effective_from'], 'cpv_procedure_lookup_index');
            $table->index(['clinic_id', 'catalog_treatment_version_id', 'effective_from'], 'cpv_version_lookup_index');
            $table->index(['clinic_id', 'catalog_stage_id', 'effective_from'], 'cpv_stage_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_price_versions');
        Schema::dropIfExists('catalog_stage_procedures');
        Schema::dropIfExists('catalog_stages');
        Schema::dropIfExists('catalog_treatment_versions');
        Schema::dropIfExists('catalog_treatments');
    }
};
