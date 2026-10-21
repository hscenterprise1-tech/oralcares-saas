<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implants by site: the fixture (system, diameter, length, lot), its
 * placement, osseointegration, abutment and crown, review and any
 * complication — one record per implant, advanced in consultations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('implant_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained('clinical_encounters')->restrictOnDelete();
            $table->foreignId('last_encounter_id')->nullable()->constrained('clinical_encounters')->nullOnDelete();
            $table->foreignId('treatment_record_id')->nullable()->constrained('treatment_records')->nullOnDelete();
            $table->unsignedTinyInteger('tooth_number');
            $table->string('status', 20)->default('planned');
            $table->string('system', 120)->nullable();
            $table->decimal('diameter_mm', 4, 2)->nullable();
            $table->decimal('length_mm', 4, 1)->nullable();
            $table->string('lot_number', 60)->nullable();
            $table->date('placed_on')->nullable();
            $table->unsignedSmallInteger('insertion_torque_ncm')->nullable();
            $table->string('integration_status', 20)->nullable();
            $table->string('abutment', 120)->nullable();
            $table->date('abutment_on')->nullable();
            $table->string('crown_status', 20)->nullable();
            $table->date('crown_on')->nullable();
            $table->date('review_on')->nullable();
            $table->text('complications')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('clinic_employees')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['clinic_id', 'patient_id', 'tooth_number'], 'implant_patient_tooth_index');
            $table->index(['clinic_id', 'status'], 'implant_clinic_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('implant_records');
    }
};
