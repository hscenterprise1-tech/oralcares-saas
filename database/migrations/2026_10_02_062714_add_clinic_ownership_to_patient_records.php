<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every patient-owned record carries the clinic that owns the patient.
 *
 * These tables held `patient_id` and at most `organization_id`, so the
 * clinic boundary could only be enforced by joining `patients`, never by the
 * tenant scope, and two clinics of one organisation shared them at the
 * organisation level. `clinic_id` is filled from the patient, then made
 * NOT NULL; new rows inherit it from their patient (`InheritsClinicOwnership`).
 */
return new class extends Migration
{
    private const TABLES = [
        'patient_profiles',
        'patient_medical_histories',
        'patient_alerts',
        'consent_records',
        'patient_procedures',
        'clinical_orders',
        'invoices',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->foreignId('clinic_id')->nullable()->after('id')->constrained('clinics')->cascadeOnDelete();
                $blueprint->index(['clinic_id', 'patient_id'], "{$table}_clinic_patient_index");
            });

            DB::table($table)->update([
                'clinic_id' => DB::raw("(select patients.clinic_id from patients where patients.id = {$table}.patient_id)"),
            ]);

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('clinic_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropForeign(['clinic_id']);
                $blueprint->dropIndex("{$table}_clinic_patient_index");
                $blueprint->dropColumn('clinic_id');
            });
        }
    }
};
