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
        Schema::table('clinical_encounters', function (Blueprint $table) {
            // Drop existing FKs using column arrays (compatible with MySQL and SQLite)
            $table->dropForeign(['patient_id']);
            $table->dropForeign(['patient_visit_id']);

            // Re-add with ON DELETE RESTRICT
            $table->foreign('patient_id', 'clinical_encounters_patient_id_foreign')
                ->references('id')
                ->on('patients')
                ->restrictOnDelete();

            $table->foreign('patient_visit_id', 'clinical_encounters_patient_visit_fk')
                ->references('id')
                ->on('patient_visits')
                ->restrictOnDelete();

            // Enforce 1 -> 0..1 relationship between attendance and encounter
            $table->unique('patient_visit_id', 'clinical_encounters_patient_visit_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clinical_encounters', function (Blueprint $table) {
            $table->dropUnique('clinical_encounters_patient_visit_id_unique');

            // In accordance with zero-cascade rule, do NOT restore CASCADE on rollback.
            // Foreign keys remain RESTRICT/NO ACTION.
        });
    }
};
