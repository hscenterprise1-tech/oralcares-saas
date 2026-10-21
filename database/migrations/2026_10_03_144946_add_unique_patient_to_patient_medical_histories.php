<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One healthcare-basics record per patient. Refuses to run while duplicates
 * exist rather than choosing which one to throw away.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('patient_medical_histories')->select('patient_id')->groupBy('patient_id')->havingRaw('count(*) > 1')->count();
        if ($duplicates > 0) {
            throw new RuntimeException("{$duplicates} patient(s) have more than one medical history row. Merge them before running this migration.");
        }

        Schema::table('patient_medical_histories', function (Blueprint $table) {
            $table->unique('patient_id', 'patient_medical_histories_patient_unique');
        });

        // Left behind by an earlier rollback; the unique index covers it.
        if (Schema::hasIndex('patient_medical_histories', 'patient_medical_histories_patient_index')) {
            Schema::table('patient_medical_histories', fn (Blueprint $table) => $table->dropIndex('patient_medical_histories_patient_index'));
        }
    }

    public function down(): void
    {
        // MySQL backs the patient_id foreign key with this index; give the key
        // a plain index before the unique one goes.
        Schema::table('patient_medical_histories', function (Blueprint $table) {
            $table->index('patient_id', 'patient_medical_histories_patient_index');
        });
        Schema::table('patient_medical_histories', function (Blueprint $table) {
            $table->dropUnique('patient_medical_histories_patient_unique');
        });
    }
};
