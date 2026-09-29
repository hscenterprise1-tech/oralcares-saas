<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Extends multiple existing tables with Phase 7 fields.
     * All changes are additive — no existing columns or constraints are removed.
     */
    public function up(): void
    {
        // 1. Extend patient_profiles with missing fields from Phase 7 spec
        Schema::table('patient_profiles', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('patient_id');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('alternate_mobile')->nullable()->after('secondary_phone');
            $table->string('pincode')->nullable()->after('postal_code');
        });

        // 2. Extend patient_medical_histories with medical_alerts
        Schema::table('patient_medical_histories', function (Blueprint $table) {
            $table->json('medical_alerts')->nullable()->after('allergies');
        });

        // 3. Extend clinic_queue_items: link to patient visit and store case number
        Schema::table('clinic_queue_items', function (Blueprint $table) {
            $table->foreignId('patient_visit_id')
                ->nullable()
                ->after('encounter_id')
                ->constrained('patient_visits')
                ->nullOnDelete()
                ->name('cqi_visit_fk');

            // Denormalized for fast TV/display rendering without joins
            $table->string('case_number')->nullable()->after('patient_visit_id');

            $table->index('case_number', 'cqi_case_number_index');
        });

        // 4. Link clinical_encounters back to patient_visits (optional FK)
        Schema::table('clinical_encounters', function (Blueprint $table) {
            $table->foreignId('patient_visit_id')
                ->nullable()
                ->after('doctor_id')
                ->constrained('patient_visits')
                ->nullOnDelete()
                ->name('ce_visit_fk');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clinical_encounters', function (Blueprint $table) {
            $table->dropForeign(['patient_visit_id']);
            $table->dropColumn('patient_visit_id');
        });

        Schema::table('clinic_queue_items', function (Blueprint $table) {
            $table->dropIndex('cqi_case_number_index');
            $table->dropForeign(['patient_visit_id']);
            $table->dropColumn(['patient_visit_id', 'case_number']);
        });

        Schema::table('patient_medical_histories', function (Blueprint $table) {
            $table->dropColumn('medical_alerts');
        });

        Schema::table('patient_profiles', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name', 'alternate_mobile', 'pincode']);
        });
    }
};
