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
        // 1. Restore clinic_id, doctor_id, and add composite index to clinical_encounters
        Schema::table('clinical_encounters', function (Blueprint $table) {
            if (! Schema::hasColumn('clinical_encounters', 'clinic_id')) {
                $table->foreignId('clinic_id')
                    ->after('organization_id')
                    ->nullable()
                    ->constrained('clinics')
                    ->cascadeOnDelete();
            }

            if (! Schema::hasColumn('clinical_encounters', 'doctor_id')) {
                $table->foreignId('doctor_id')
                    ->after('provider_id')
                    ->nullable()
                    ->constrained('clinic_employees')
                    ->nullOnDelete();
            }

            $table->index(['clinic_id', 'branch_id', 'patient_id'], 'idx_enc_clinic_branch_patient');
        });

        // 2. Add organization_id to patients table to support Tier 2 tenancy mapping
        if (Schema::hasTable('patients') && ! Schema::hasColumn('patients', 'organization_id')) {
            Schema::table('patients', function (Blueprint $table) {
                $table->foreignId('organization_id')
                    ->after('clinic_id')
                    ->nullable()
                    ->constrained('organizations')
                    ->cascadeOnDelete();

                $table->index(['clinic_id', 'organization_id'], 'idx_patients_clinic_org');
            });
        }

        // 3. Add clinic_id to clinical_notes for strict clinic-level isolation
        if (Schema::hasTable('clinical_notes') && ! Schema::hasColumn('clinical_notes', 'clinic_id')) {
            Schema::table('clinical_notes', function (Blueprint $table) {
                $table->foreignId('clinic_id')
                    ->after('organization_id')
                    ->nullable()
                    ->constrained('clinics')
                    ->cascadeOnDelete();

                $table->index(['clinic_id', 'encounter_id'], 'idx_notes_clinic_encounter');
            });
        }

        // 4. Add clinic_id to clinical_diagnoses for strict clinic-level isolation
        if (Schema::hasTable('clinical_diagnoses') && ! Schema::hasColumn('clinical_diagnoses', 'clinic_id')) {
            Schema::table('clinical_diagnoses', function (Blueprint $table) {
                $table->foreignId('clinic_id')
                    ->after('organization_id')
                    ->nullable()
                    ->constrained('clinics')
                    ->cascadeOnDelete();

                $table->index(['clinic_id', 'encounter_id'], 'idx_diagnoses_clinic_encounter');
            });
        }

        // 5. Add clinic_id to prescriptions for strict clinic-level isolation
        if (Schema::hasTable('prescriptions') && ! Schema::hasColumn('prescriptions', 'clinic_id')) {
            Schema::table('prescriptions', function (Blueprint $table) {
                $table->foreignId('clinic_id')
                    ->after('organization_id')
                    ->nullable()
                    ->constrained('clinics')
                    ->cascadeOnDelete();

                $table->index(['clinic_id', 'encounter_id'], 'idx_prescriptions_clinic_encounter');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('prescriptions') && Schema::hasColumn('prescriptions', 'clinic_id')) {
            Schema::table('prescriptions', function (Blueprint $table) {
                $table->dropConstrainedForeignId('clinic_id');
                $table->dropIndex('idx_prescriptions_clinic_encounter');
            });
        }

        if (Schema::hasTable('clinical_diagnoses') && Schema::hasColumn('clinical_diagnoses', 'clinic_id')) {
            Schema::table('clinical_diagnoses', function (Blueprint $table) {
                $table->dropConstrainedForeignId('clinic_id');
                $table->dropIndex('idx_diagnoses_clinic_encounter');
            });
        }

        if (Schema::hasTable('clinical_notes') && Schema::hasColumn('clinical_notes', 'clinic_id')) {
            Schema::table('clinical_notes', function (Blueprint $table) {
                $table->dropConstrainedForeignId('clinic_id');
                $table->dropIndex('idx_notes_clinic_encounter');
            });
        }

        if (Schema::hasTable('patients') && Schema::hasColumn('patients', 'organization_id')) {
            Schema::table('patients', function (Blueprint $table) {
                $table->dropConstrainedForeignId('organization_id');
                $table->dropIndex('idx_patients_clinic_org');
            });
        }

        if (Schema::hasTable('clinical_encounters')) {
            Schema::table('clinical_encounters', function (Blueprint $table) {
                if (Schema::hasColumn('clinical_encounters', 'doctor_id')) {
                    $table->dropConstrainedForeignId('doctor_id');
                }
                if (Schema::hasColumn('clinical_encounters', 'clinic_id')) {
                    $table->dropConstrainedForeignId('clinic_id');
                    $table->dropIndex('idx_enc_clinic_branch_patient');
                }
            });
        }
    }
};
