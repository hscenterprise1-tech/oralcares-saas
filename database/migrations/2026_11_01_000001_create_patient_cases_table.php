<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('patient_cases')) {
            return;
        }

        Schema::create('patient_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->string('case_number', 32);
            $table->string('specialty', 32);
            $table->string('title', 150);
            $table->text('chief_complaint');
            $table->string('primary_diagnosis', 255)->nullable();
            $table->foreignId('lead_practitioner_id')->constrained('clinic_employees')->restrictOnDelete();
            $table->string('referring_doctor', 150)->nullable();
            $table->string('status', 32)->default('active');
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
            $table->text('discharge_summary')->nullable();
            $table->timestamps();

            $table->unique(['clinic_id', 'case_number'], 'patient_cases_clinic_case_number_unique');
            $table->index(['patient_id', 'status'], 'patient_cases_patient_status_idx');
            $table->index(['branch_id', 'status'], 'patient_cases_branch_status_idx');
            $table->index('lead_practitioner_id', 'patient_cases_lead_practitioner_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('patient_cases')) {
            return;
        }

        if (DB::table('patient_cases')->count() > 0) {
            throw new RuntimeException('Cannot drop patient_cases table: live records exist.');
        }

        Schema::dropIfExists('patient_cases');
    }
};
