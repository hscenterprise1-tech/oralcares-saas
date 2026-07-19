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
        // 1. Clinical Consultations
        Schema::create('clinical_consultations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encounter_id')->constrained('clinical_encounters')->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('clinic_employees')->cascadeOnDelete();
            $table->string('status')->default('started'); // started, paused, resumed, completed
            $table->timestamp('started_at');
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        // 2. Patient Dental Charts
        Schema::create('patient_dental_charts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->integer('tooth_number'); // FDI numbering (e.g. 11-18, 21-28, etc.)
            $table->string('status')->default('healthy'); // healthy, needs_treatment, completed, crown, etc.
            $table->text('notes')->nullable();

            $table->foreignId('updated_by_doctor_id')
                ->nullable()
                ->constrained('clinic_employees')
                ->nullOnDelete()
                ->name('dent_chart_doc_fk');

            $table->timestamps();

            $table->unique(['patient_id', 'tooth_number']);
        });

        // 3. Clinical Diagnoses
        Schema::create('clinical_diagnoses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encounter_id')->constrained('clinical_encounters')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->string('code')->nullable(); // ICD/SNODENT placeholder
            $table->string('description');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clinical_diagnoses');
        Schema::dropIfExists('patient_dental_charts');
        Schema::dropIfExists('clinical_consultations');
    }
};
