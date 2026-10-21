<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger of patient merges: which duplicate was merged into which
 * patient, under which approved request, and exactly which rows moved (per
 * table) and which one-per-patient facts clashed. Never edited; it is the
 * audit and the basis for reversing a merge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_merges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('source_patient_id');
            $table->unsignedBigInteger('survivor_patient_id');
            $table->foreignId('deletion_request_id')->nullable()->constrained('deletion_requests')->nullOnDelete();
            $table->json('moved');
            $table->json('conflicts')->nullable();
            $table->foreignId('merged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('merged_at');
            $table->timestamps();

            $table->foreign(['source_patient_id', 'clinic_id'], 'pm_source_same_clinic_fk')->references(['id', 'clinic_id'])->on('patients')->restrictOnDelete();
            $table->foreign(['survivor_patient_id', 'clinic_id'], 'pm_survivor_same_clinic_fk')->references(['id', 'clinic_id'])->on('patients')->restrictOnDelete();
            $table->unique('source_patient_id', 'patient_merges_source_unique');
            $table->index(['clinic_id', 'survivor_patient_id'], 'patient_merges_survivor_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_merges');
    }
};
