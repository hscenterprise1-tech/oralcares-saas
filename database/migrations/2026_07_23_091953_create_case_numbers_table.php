<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the case_numbers table. Each row is a year-wise sequence number
     * linked to one patient visit. The sequence resets to 1 each calendar year
     * per clinic. Format: YYYY-000001
     */
    public function up(): void
    {
        Schema::create('case_numbers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('patient_visit_id')
                ->nullable()
                ->constrained('patient_visits')
                ->nullOnDelete()
                ->name('cn_visit_fk');

            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('sequence');
            $table->string('case_number'); // e.g. 2026-000001

            $table->timestamps();

            // Sequence must be unique within clinic+year
            $table->unique(['clinic_id', 'year', 'sequence'], 'cn_clinic_year_seq_unique');

            // Case number string must also be globally unique within a clinic
            $table->unique(['clinic_id', 'case_number'], 'cn_clinic_case_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('case_numbers');
    }
};
