<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A patient's conditions, allergies and medications, one row each. This is
 * the one place they are recorded; the old medical-history columns are
 * backfilled into it and kept, unread, until a later approved cleanup.
 *
 * `label` is a snapshot of the name when recorded, so renaming a catalogue
 * item never rewrites a patient's history. `dedupe_key` (the catalogue key,
 * or the lower-cased label of a custom entry) keeps one row per thing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_clinical_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('patient_id');
            $table->string('kind', 20);
            $table->string('catalogue_key', 64)->nullable();
            $table->string('label', 160);
            $table->string('dedupe_key', 180);
            $table->string('severity', 10)->nullable();
            $table->string('status', 12)->default('active');
            // Structured extras, e.g. a medication's dose and frequency, an allergy's reaction.
            $table->json('details')->nullable();
            $table->string('source', 20);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Clinical history is never removed by deleting the patient row.
            $table->foreign(['patient_id', 'clinic_id'], 'patient_clinical_entries_patient_same_clinic_fk')->references(['id', 'clinic_id'])->on('patients')->restrictOnDelete();
            $table->unique(['clinic_id', 'patient_id', 'kind', 'dedupe_key'], 'patient_clinical_entries_unique');
            $table->index(['clinic_id', 'patient_id', 'status'], 'patient_clinical_entries_patient_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_clinical_entries');
    }
};
