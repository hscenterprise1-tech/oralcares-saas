<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-ups as records of their own: the review a doctor asked for when
 * signing a consultation, or one added from Patient 360. Each is due on a
 * date, may be booked as an appointment, and is completed or cancelled.
 *
 * Existing signed consultations with a follow-up date become follow-ups.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('encounter_id')->nullable()->unique()->constrained('clinical_encounters')->nullOnDelete();
            $table->foreignId('practitioner_id')->nullable()->constrained('clinic_employees')->nullOnDelete();
            $table->date('due_on');
            $table->string('reason', 500)->nullable();
            $table->string('status', 20)->default('due');
            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['clinic_id', 'patient_id', 'status', 'due_on'], 'follow_ups_patient_due_index');
            $table->index(['clinic_id', 'status', 'due_on'], 'follow_ups_clinic_due_index');
        });

        DB::table('clinical_encounters')->whereNotNull('follow_up_on')->whereNotNull('finalized_at')->orderBy('id')
            ->each(function (object $encounter) {
                DB::table('patient_follow_ups')->insert([
                    'clinic_id' => $encounter->clinic_id,
                    'branch_id' => $encounter->branch_id,
                    'patient_id' => $encounter->patient_id,
                    'encounter_id' => $encounter->id,
                    'practitioner_id' => $encounter->doctor_id,
                    'due_on' => $encounter->follow_up_on,
                    'reason' => $encounter->follow_up_note ? mb_substr($encounter->follow_up_note, 0, 500) : null,
                    'status' => 'due',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_follow_ups');
    }
};
