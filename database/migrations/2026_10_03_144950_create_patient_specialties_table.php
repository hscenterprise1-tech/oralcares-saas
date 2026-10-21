<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A patient's enrolment in one of the clinic's specialties. Created by an
 * explicit act (registration, starting care in it, staff adding it, the
 * foundation backfill), never inferred on read; later activity only moves
 * `last_activity_at`. Both links are same-clinic composite keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_specialties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('clinic_specialty_id');
            $table->string('status', 20)->default('active');
            $table->string('source', 20);
            $table->timestamp('enrolled_at');
            $table->foreignId('enrolled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->foreign(['patient_id', 'clinic_id'], 'patient_specialties_patient_same_clinic_fk')->references(['id', 'clinic_id'])->on('patients')->cascadeOnDelete();
            $table->foreign(['clinic_specialty_id', 'clinic_id'], 'patient_specialties_specialty_same_clinic_fk')->references(['id', 'clinic_id'])->on('clinic_specialties')->restrictOnDelete();
            $table->unique(['clinic_id', 'patient_id', 'clinic_specialty_id'], 'patient_specialties_unique');
            $table->index(['clinic_id', 'clinic_specialty_id', 'status', 'patient_id'], 'patient_specialties_filter_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_specialties');
    }
};
