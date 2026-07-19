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
        // 1. Master Clinical Procedures
        Schema::create('clinical_procedures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->nullable()->constrained('clinics')->cascadeOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category'); // preventive, restorative, endodontic, surgical, etc.
            $table->integer('default_duration_minutes')->default(30);
            $table->string('clinical_complexity')->default('medium'); // low, medium, high
            $table->boolean('requires_tooth')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 2. Clinic Procedure Pricing & Overrides
        Schema::create('clinical_procedure_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('clinical_procedure_id')->constrained('clinical_procedures')->cascadeOnDelete();
            $table->decimal('price', 10, 2);
            $table->integer('duration_minutes')->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->unique(['clinic_id', 'clinical_procedure_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clinical_procedure_prices');
        Schema::dropIfExists('clinical_procedures');
    }
};
