<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Root canal treatment, tooth by tooth: the diagnosis (pulp and periapical
 * status), the stage the treatment has reached, the canals with their
 * working lengths, instrumentation and obturation, the restorations placed
 * and the review date. One record per course of treatment on a tooth; each
 * visit updates it inside a consultation, and every change is audited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('endodontic_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained('clinical_encounters')->restrictOnDelete();
            $table->foreignId('last_encounter_id')->nullable()->constrained('clinical_encounters')->nullOnDelete();
            $table->foreignId('treatment_record_id')->nullable()->constrained('treatment_records')->nullOnDelete();
            $table->unsignedTinyInteger('tooth_number');
            $table->string('status', 20)->default('in_progress');
            $table->string('stage', 30)->default('diagnosis');
            $table->string('pulp_status', 40)->nullable();
            $table->string('periapical_status', 40)->nullable();
            $table->string('diagnosis', 500)->nullable();
            $table->json('canals')->nullable();
            $table->text('instrumentation_notes')->nullable();
            $table->string('obturation_technique', 100)->nullable();
            $table->string('obturation_material', 100)->nullable();
            $table->date('obturated_on')->nullable();
            $table->string('temporary_restoration', 150)->nullable();
            $table->string('permanent_restoration', 150)->nullable();
            $table->date('review_on')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('clinic_employees')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['clinic_id', 'patient_id', 'tooth_number'], 'endo_patient_tooth_index');
            $table->index(['clinic_id', 'status'], 'endo_clinic_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('endodontic_records');
    }
};
