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
        // 1. Provisioning Audits Table
        Schema::create('provisioning_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->nullable()->constrained('clinics')->nullOnDelete();
            $table->string('profile'); // solo_dentist, small_clinic, multi_doctor, enterprise
            $table->string('status'); // started, completed, failed
            $table->integer('duration_ms')->nullable();
            $table->json('created_entities')->nullable();
            $table->string('version')->default('1.0');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provisioning_audits');
    }
};
