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
        // 1. Clinic Queues
        Schema::create('clinic_queues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('doctor_id')->nullable()->constrained('clinic_employees')->nullOnDelete();
            $table->timestamps();
        });

        // 2. Clinic Queue Items
        Schema::create('clinic_queue_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_queue_id')->constrained('clinic_queues')->cascadeOnDelete();
            $table->foreignId('encounter_id')->constrained('clinical_encounters')->cascadeOnDelete();
            $table->integer('position');
            $table->string('priority')->default('normal'); // emergency, senior_citizen, child, vip, normal
            $table->timestamp('called_at')->nullable();
            $table->timestamps();

            $table->unique(['clinic_queue_id', 'encounter_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clinic_queue_items');
        Schema::dropIfExists('clinic_queues');
    }
};
