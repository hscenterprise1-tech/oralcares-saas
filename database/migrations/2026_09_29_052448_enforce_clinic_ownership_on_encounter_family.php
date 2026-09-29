<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Encounter-family tables whose `clinic_id` becomes required, in the order
     * they are backfilled (parents first).
     *
     * @var array<int, string>
     */
    private array $tables = ['clinical_encounters', 'clinical_notes', 'clinical_diagnoses', 'prescriptions'];

    /**
     * Run the migrations.
     *
     * A clinical record with no `clinic_id` is invisible to the clinic scope
     * but not to organization scope, so ownership must be explicit. Rows
     * missing it are first filled from their own patient, then their
     * encounter. If any row still cannot be tied to a clinic, the migration
     * stops without changing any column, and the rows must be resolved by a
     * person. Nothing is deleted or guessed. `patients.organization_id`
     * (added nullable earlier) is filled from each patient's clinic.
     */
    public function up(): void
    {
        foreach ($this->tables as $table) {
            DB::table($table)->whereNull('clinic_id')->whereNotNull('patient_id')->update([
                'clinic_id' => DB::raw("(SELECT clinic_id FROM patients WHERE patients.id = {$table}.patient_id)"),
            ]);

            if ($table !== 'clinical_encounters') {
                DB::table($table)->whereNull('clinic_id')->whereNotNull('encounter_id')->update([
                    'clinic_id' => DB::raw("(SELECT clinic_id FROM clinical_encounters WHERE clinical_encounters.id = {$table}.encounter_id)"),
                ]);
            }

            $orphans = DB::table($table)->whereNull('clinic_id')->count();

            if ($orphans > 0) {
                throw new RuntimeException("{$orphans} row(s) in {$table} cannot be tied to a clinic through their patient or encounter. Resolve them before re-running; nothing has been changed.");
            }
        }

        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('clinic_id')->nullable(false)->change();
            });
        }

        if (Schema::hasColumn('patients', 'organization_id')) {
            DB::table('patients')->whereNull('organization_id')->update([
                'organization_id' => DB::raw('(SELECT organization_id FROM clinics WHERE clinics.id = patients.clinic_id)'),
            ]);
        }
    }

    /**
     * Reverse the migrations. Only the constraint is relaxed; backfilled
     * values are kept, because they are correct.
     */
    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('clinic_id')->nullable()->change();
            });
        }
    }
};
