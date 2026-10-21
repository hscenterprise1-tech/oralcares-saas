<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orthodontics as its own workflow, not as tooth fillings: a case (arches,
 * appliance, phase, start and expected length) and its adjustment visits
 * (wires per arch, adjustments, elastics, notes, next interval).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orthodontic_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained('clinical_encounters')->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->string('arches', 10)->default('both');
            $table->string('appliance', 30);
            $table->string('bracket_system', 120)->nullable();
            $table->string('phase', 20)->default('planning');
            $table->string('diagnosis', 500)->nullable();
            $table->date('started_on')->nullable();
            $table->unsignedTinyInteger('estimated_months')->nullable();
            $table->json('bracketed_teeth')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('clinic_employees')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['clinic_id', 'patient_id', 'status'], 'ortho_patient_status_index');
        });

        Schema::create('orthodontic_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orthodontic_case_id')->constrained('orthodontic_cases')->cascadeOnDelete();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('encounter_id')->unique()->constrained('clinical_encounters')->restrictOnDelete();
            $table->string('phase', 20)->nullable();
            $table->string('upper_wire', 80)->nullable();
            $table->string('lower_wire', 80)->nullable();
            $table->text('adjustments')->nullable();
            $table->string('elastics', 150)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedTinyInteger('next_visit_weeks')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('clinic_employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['orthodontic_case_id', 'created_at'], 'ortho_visits_case_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orthodontic_visits');
        Schema::dropIfExists('orthodontic_cases');
    }
};
