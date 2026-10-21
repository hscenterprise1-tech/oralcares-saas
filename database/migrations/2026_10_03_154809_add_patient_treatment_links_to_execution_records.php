<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Execution, scheduling, billing and specialty records can now say which
 * patient treatment (and stage) they belong to. All nullable: existing rows
 * stay readable as they are; `treatments:backfill-engine` links the ones it
 * can prove. Every link is a same-clinic composite key.
 *
 * Production note: `appointments`, `patient_billing_items` and
 * `patient_procedures` are high-volume tables; run this through an online
 * schema tool there.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> table => extra links beyond patient_treatment_id */
    private const TABLES = [
        'patient_procedures' => ['stage', 'price'],
        'patient_billing_items' => ['stage', 'price'],
        'appointments' => ['stage'],
        'patient_follow_ups' => [],
        'physio_sessions' => ['procedure'],
        'physio_care_plans' => [],
        'physio_exercises' => [],
        'patient_session_packages' => [],
        'endodontic_records' => [],
        'implant_records' => [],
        'lab_orders' => [],
        'patient_tooth_status_histories' => [],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $name => $extras) {
            if (Schema::hasColumn($name, 'patient_treatment_id')) {
                continue;
            }
            // An index left behind by an earlier rollback is replaced, not duplicated.
            $this->replaceIndex($name, "{$name}_pt_index", null);

            Schema::table($name, function (Blueprint $table) use ($name, $extras) {
                $table->unsignedBigInteger('patient_treatment_id')->nullable();
                $table->foreign(['patient_treatment_id', 'clinic_id'], "{$name}_pt_same_clinic_fk")->references(['id', 'clinic_id'])->on('patient_treatments')->restrictOnDelete();

                if (in_array('stage', $extras, true)) {
                    $table->unsignedBigInteger('patient_treatment_stage_id')->nullable();
                    $table->foreign(['patient_treatment_stage_id', 'clinic_id'], "{$name}_pts_same_clinic_fk")->references(['id', 'clinic_id'])->on('patient_treatment_stages')->restrictOnDelete();
                }
                if (in_array('price', $extras, true)) {
                    $table->unsignedBigInteger('price_version_id')->nullable();
                    $table->foreign(['price_version_id', 'clinic_id'], "{$name}_cpv_same_clinic_fk")->references(['id', 'clinic_id'])->on('catalog_price_versions')->restrictOnDelete();
                }
                if (in_array('procedure', $extras, true)) {
                    $table->foreignId('patient_procedure_id')->nullable()->constrained('patient_procedures')->nullOnDelete();
                }
            });

            Schema::table($name, fn (Blueprint $table) => $table->index(['clinic_id', 'patient_treatment_id'], "{$name}_pt_index"));
            // The new index now backs any clinic_id foreign key; the temporary one goes.
            if (Schema::hasIndex($name, "{$name}_clinic_fk_index")) {
                Schema::table($name, fn (Blueprint $table) => $table->dropIndex("{$name}_clinic_fk_index"));
            }
        }

        // The amount charged for a performed procedure, fixed when it was done.
        if (! Schema::hasColumn('patient_procedures', 'charged_amount')) {
            Schema::table('patient_procedures', fn (Blueprint $table) => $table->decimal('charged_amount', 12, 2)->nullable());
        }
    }

    /**
     * Tolerates a partly rolled-back schema. MySQL may have adopted the
     * `(clinic_id, patient_treatment_id)` index to back an existing
     * `clinic_id` foreign key, so a plain `(clinic_id)` index is put in its
     * place before it is dropped.
     */
    public function down(): void
    {
        if (Schema::hasColumn('patient_procedures', 'charged_amount')) {
            Schema::table('patient_procedures', fn (Blueprint $table) => $table->dropColumn('charged_amount'));
        }

        foreach (array_reverse(self::TABLES, true) as $name => $extras) {
            if (! Schema::hasColumn($name, 'patient_treatment_id')) {
                $this->replaceIndex($name, "{$name}_pt_index", null);

                continue;
            }
            $foreignKeys = collect(Schema::getForeignKeys($name))->pluck('name')->all();
            $dropForeign = fn (Blueprint $table, string $key) => in_array($key, $foreignKeys, true) ? $table->dropForeign($key) : null;

            Schema::table($name, function (Blueprint $table) use ($name, $extras, $dropForeign) {
                if (in_array('procedure', $extras, true) && Schema::hasColumn($name, 'patient_procedure_id')) {
                    $table->dropConstrainedForeignId('patient_procedure_id');
                }
                if (in_array('price', $extras, true) && Schema::hasColumn($name, 'price_version_id')) {
                    $dropForeign($table, "{$name}_cpv_same_clinic_fk");
                    $table->dropColumn('price_version_id');
                }
                if (in_array('stage', $extras, true) && Schema::hasColumn($name, 'patient_treatment_stage_id')) {
                    $dropForeign($table, "{$name}_pts_same_clinic_fk");
                    $table->dropColumn('patient_treatment_stage_id');
                }
                $dropForeign($table, "{$name}_pt_same_clinic_fk");
            });
            $this->replaceIndex($name, "{$name}_pt_index", "{$name}_clinic_fk_index");
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('patient_treatment_id'));
        }
    }

    /**
     * Drop an index that may be backing a `clinic_id` foreign key, after
     * giving that key a plain `(clinic_id)` index (named $replacement, or a
     * temporary one the next `up()` removes).
     */
    private function replaceIndex(string $table, string $index, ?string $replacement): void
    {
        if (! Schema::hasIndex($table, $index)) {
            return;
        }
        $replacement ??= "{$table}_clinic_fk_index";
        if (! Schema::hasIndex($table, $replacement)) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index('clinic_id', $replacement));
        }
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($index));
    }
};
