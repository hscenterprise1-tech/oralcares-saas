<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which branches a patient has been seen at — registration, visits,
 * appointments, encounters, bills. Not ownership: the patient stays
 * clinic-wide. A branch-restricted user's patient directory and Patient 360
 * access are an indexed lookup here instead of an EXISTS over five tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_branch_activity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('branch_id');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->string('first_source', 20);
            $table->timestamps();

            $table->foreign(['patient_id', 'clinic_id'], 'pba_patient_same_clinic_fk')->references(['id', 'clinic_id'])->on('patients')->cascadeOnDelete();
            $table->foreign(['branch_id', 'clinic_id'], 'pba_branch_same_clinic_fk')->references(['id', 'clinic_id'])->on('branches')->cascadeOnDelete();
            $table->unique(['clinic_id', 'patient_id', 'branch_id'], 'pba_patient_branch_unique');
            $table->index(['clinic_id', 'branch_id', 'last_seen_at', 'patient_id'], 'pba_branch_recent_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_branch_activity');
    }
};
