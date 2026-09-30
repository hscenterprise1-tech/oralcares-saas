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
        // Same shape as `healthcare_specialty_masters`: a reference table, not
        // a constraint. `patient_medical_histories.allergies` stays a plain
        // JSON array of names — a receptionist who hears an allergy nobody
        // catalogued yet must still be able to record it (the "add custom"
        // escape hatch in the picker), and a foreign key would have stopped
        // that. This table exists to make the *common* case a pick rather
        // than a typo-prone free-type, not to be the only way in.
        Schema::create('allergy_masters', function (Blueprint $table) {
            $table->id();

            // Stable identifier, independent of a label edit.
            $table->string('key')->unique();
            $table->string('name');

            // Loose grouping for the picker: medication, food, material,
            // environmental. Not constrained to an enum — new categories
            // are just new strings, the same way the specialty catalogue
            // treats `category`.
            $table->string('category')->index();

            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('allergy_masters');
    }
};
