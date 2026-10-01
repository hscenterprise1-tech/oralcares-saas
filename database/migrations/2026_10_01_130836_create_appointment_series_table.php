<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A recurring booking: the rule its appointments were generated from.
     * Each occurrence is an ordinary appointment pointing back here, so the
     * calendar, conflicts and check-in treat it like any other.
     */
    public function up(): void
    {
        Schema::create('appointment_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained('patients')->cascadeOnDelete();
            $table->foreignId('practitioner_id')->nullable()->constrained('clinic_employees')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('clinical_procedures')->nullOnDelete();
            $table->string('frequency', 20);
            $table->unsignedSmallInteger('occurrences');
            $table->dateTime('starts_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['clinic_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_series');
    }
};
