<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The specialties a clinic practises: the canonical record behind
 * `SpecialtyRegistry::forClinic()`. `specialty_key` is the registry key
 * (config/clinic_specialties.php) the behaviour hangs on; the master row is the
 * global catalog entry it maps to. Deactivating keeps the row (and every
 * patient enrolment pointing at it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_specialties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('healthcare_specialty_master_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('specialty_key', 50);
            $table->string('status', 20)->default('active');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['clinic_id', 'specialty_key'], 'clinic_specialties_clinic_key_unique');
            $table->unique(['id', 'clinic_id'], 'clinic_specialties_id_clinic_unique');
            $table->index(['clinic_id', 'status'], 'clinic_specialties_clinic_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_specialties');
    }
};
