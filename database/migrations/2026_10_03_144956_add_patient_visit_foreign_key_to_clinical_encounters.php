<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An encounter may reference the arrival it came from; until now nothing
 * stopped that id pointing at a visit that does not exist. Orphans stop the
 * migration with their ids — nothing is nulled or rewritten to make it pass.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orphans = DB::table('clinical_encounters as e')
            ->leftJoin('patient_visits as v', 'v.id', '=', 'e.patient_visit_id')
            ->whereNotNull('e.patient_visit_id')
            ->whereNull('v.id')
            ->pluck('e.id');

        if ($orphans->isNotEmpty()) {
            throw new RuntimeException('Encounters reference missing patient visits: '.$orphans->take(50)->implode(', '));
        }

        Schema::table('clinical_encounters', function (Blueprint $table) {
            $table->foreign('patient_visit_id', 'clinical_encounters_patient_visit_fk')->references('id')->on('patient_visits')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clinical_encounters', function (Blueprint $table) {
            $table->dropForeign('clinical_encounters_patient_visit_fk');
        });
    }
};
