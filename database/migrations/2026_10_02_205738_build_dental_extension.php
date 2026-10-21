<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The dental extension (registry extension `odontogram`) on the dental tables
 * that already exist, hanging off Patient → Encounter:
 *
 * - `patient_dental_charts`: the current state of each tooth (FDI), now with
 *   per-surface conditions and the consultation that last changed it.
 * - `patient_tooth_status_histories`: every finding and every treatment done
 *   on a tooth, clinic-owned and filed against its consultation.
 * - `treatment_plans` / `treatment_records`: a patient's dental treatment
 *   plan and its items (tooth, surfaces, service). Completing an item records
 *   the procedure in the open consultation, which is what billing reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_dental_charts', function (Blueprint $table) {
            $table->json('surfaces')->nullable()->after('status');
            $table->foreignId('encounter_id')->nullable()->after('patient_id')->constrained('clinical_encounters')->nullOnDelete();
        });

        Schema::table('patient_tooth_status_histories', function (Blueprint $table) {
            $table->foreignId('clinic_id')->nullable()->after('id');
            $table->foreignId('encounter_id')->nullable()->after('patient_id')->constrained('clinical_encounters')->nullOnDelete();
            $table->string('kind', 20)->default('finding')->after('tooth_number');
            $table->string('previous_status')->nullable()->after('status');
            $table->json('surfaces')->nullable()->after('previous_status');
            $table->foreignId('patient_procedure_id')->nullable()->after('treatment_record_id')->constrained('patient_procedures')->nullOnDelete();
        });
        DB::table('patient_tooth_status_histories')->update([
            'clinic_id' => DB::raw('(select clinic_id from patients where patients.id = patient_tooth_status_histories.patient_id)'),
        ]);
        Schema::table('patient_tooth_status_histories', function (Blueprint $table) {
            $table->unsignedBigInteger('clinic_id')->nullable(false)->change();
            $table->foreign('clinic_id')->references('id')->on('clinics')->restrictOnDelete();
            $table->index(['clinic_id', 'patient_id', 'tooth_number'], 'tooth_histories_patient_tooth_index');
        });

        Schema::table('treatment_plans', function (Blueprint $table) {
            $table->foreignId('encounter_id')->nullable()->after('patient_id')->constrained('clinical_encounters')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('clinic_employees')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->index(['clinic_id', 'patient_id', 'status'], 'treatment_plans_patient_status_index');
        });

        Schema::table('treatment_records', function (Blueprint $table) {
            $table->foreignId('clinic_id')->nullable()->after('id');
            $table->json('surfaces')->nullable()->after('tooth_number');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('patient_procedure_id')->nullable()->constrained('patient_procedures')->nullOnDelete();
        });
        DB::table('treatment_records')->update([
            'clinic_id' => DB::raw('(select clinic_id from patients where patients.id = treatment_records.patient_id)'),
        ]);
        Schema::table('treatment_records', function (Blueprint $table) {
            $table->unsignedBigInteger('clinic_id')->nullable(false)->change();
            $table->foreign('clinic_id')->references('id')->on('clinics')->restrictOnDelete();
            $table->foreign('treatment_plan_id')->references('id')->on('treatment_plans')->cascadeOnDelete();
            $table->index(['clinic_id', 'treatment_plan_id', 'status'], 'treatment_records_plan_status_index');
        });
    }

    public function down(): void
    {
        $existing = fn (string $table) => array_column(Schema::getIndexes($table), 'name');

        Schema::table('treatment_records', function (Blueprint $table) {
            $table->dropForeign(['clinic_id']);
            $table->dropForeign(['treatment_plan_id']);
            $table->dropForeign(['patient_procedure_id']);
        });
        Schema::table('treatment_records', function (Blueprint $table) use ($existing) {
            foreach (array_intersect(['treatment_records_plan_status_index', 'treatment_records_clinic_id_foreign', 'treatment_records_treatment_plan_id_foreign', 'treatment_records_patient_procedure_id_foreign'], $existing('treatment_records')) as $index) {
                $table->dropIndex($index);
            }
        });
        Schema::table('treatment_records', fn (Blueprint $table) => $table->dropColumn(['clinic_id', 'surfaces', 'sort_order', 'completed_at', 'patient_procedure_id']));

        Schema::table('treatment_plans', function (Blueprint $table) {
            $table->dropForeign(['encounter_id']);
            $table->dropForeign(['created_by']);
        });
        Schema::table('treatment_plans', function (Blueprint $table) use ($existing) {
            if (! collect(Schema::getIndexes('treatment_plans'))->contains(fn (array $index) => $index['columns'] === ['clinic_id'])) {
                $table->index('clinic_id', 'treatment_plans_clinic_id_foreign');
            }
            foreach (array_intersect(['treatment_plans_patient_status_index', 'treatment_plans_encounter_id_foreign', 'treatment_plans_created_by_foreign'], $existing('treatment_plans')) as $index) {
                $table->dropIndex($index);
            }
        });
        Schema::table('treatment_plans', fn (Blueprint $table) => $table->dropColumn(['encounter_id', 'created_by', 'notes', 'accepted_at', 'completed_at']));

        Schema::table('patient_tooth_status_histories', function (Blueprint $table) {
            $table->dropForeign(['clinic_id']);
            $table->dropForeign(['encounter_id']);
            $table->dropForeign(['patient_procedure_id']);
        });
        Schema::table('patient_tooth_status_histories', function (Blueprint $table) use ($existing) {
            foreach (array_intersect(['tooth_histories_patient_tooth_index', 'patient_tooth_status_histories_clinic_id_foreign', 'patient_tooth_status_histories_encounter_id_foreign', 'patient_tooth_status_histories_patient_procedure_id_foreign'], $existing('patient_tooth_status_histories')) as $index) {
                $table->dropIndex($index);
            }
        });
        Schema::table('patient_tooth_status_histories', fn (Blueprint $table) => $table->dropColumn(['clinic_id', 'encounter_id', 'kind', 'previous_status', 'surfaces', 'patient_procedure_id']));

        Schema::table('patient_dental_charts', fn (Blueprint $table) => $table->dropConstrainedForeignId('encounter_id'));
        Schema::table('patient_dental_charts', fn (Blueprint $table) => $table->dropColumn('surfaces'));
    }
};
