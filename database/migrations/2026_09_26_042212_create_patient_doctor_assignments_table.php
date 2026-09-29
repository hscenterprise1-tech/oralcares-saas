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
        Schema::create('patient_doctor_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_practitioner_id')->nullable()->constrained('clinic_employees')->nullOnDelete();
            $table->foreignId('to_practitioner_id')->constrained('clinic_employees')->cascadeOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->cascadeOnDelete();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patient_doctor_assignments');
    }
};
