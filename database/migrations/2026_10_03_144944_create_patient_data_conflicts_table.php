<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Values the patient-foundation backfill found in two places that disagree
 * (e.g. a profile `alternate_mobile` different from `patients.alternate_phone`).
 * The canonical value is never overwritten; the other one is kept here until
 * someone resolves it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_data_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('field', 60);
            $table->string('source', 120);
            $table->text('canonical_value')->nullable();
            $table->text('other_value')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['patient_id', 'field', 'source'], 'patient_data_conflicts_unique');
            $table->index(['clinic_id', 'resolved_at'], 'patient_data_conflicts_open_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_data_conflicts');
    }
};
