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
        // 1. Patient Profiles
        Schema::create('patient_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->string('preferred_name')->nullable();
            $table->string('secondary_phone')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('occupation')->nullable();
            $table->string('nationality')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();
            $table->string('referral_source')->nullable();
            $table->timestamps();
        });

        // 2. Patient Medical Histories
        Schema::create('patient_medical_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->boolean('pregnancy_status')->default(false);
            $table->boolean('blood_disorder')->default(false);
            $table->boolean('diabetes')->default(false);
            $table->boolean('hypertension')->default(false);
            $table->boolean('cardiac_condition')->default(false);
            $table->boolean('tobacco_use')->default(false);
            $table->boolean('alcohol_use')->default(false);
            $table->json('allergies')->nullable();
            $table->json('medical_conditions')->nullable();
            $table->json('current_medications')->nullable();
            $table->json('previous_surgeries')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patient_medical_histories');
        Schema::dropIfExists('patient_profiles');
    }
};
