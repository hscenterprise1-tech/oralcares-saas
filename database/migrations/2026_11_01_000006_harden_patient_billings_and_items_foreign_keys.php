<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Harden patient_billings FKs to RESTRICT
        Schema::table('patient_billings', function (Blueprint $table) {
            $table->dropForeign(['patient_id']);

            $table->foreign('patient_id', 'patient_billings_patient_id_foreign')
                ->references('id')
                ->on('patients')
                ->restrictOnDelete();
        });

        // 2. Harden patient_billing_items FKs to RESTRICT and add doctor_id
        Schema::table('patient_billing_items', function (Blueprint $table) {
            $table->dropForeign(['patient_billing_id']);

            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $table->dropForeign('patient_billing_items_same_clinic_fk');
            }

            $table->foreign('patient_billing_id', 'patient_billing_items_patient_billing_id_foreign')
                ->references('id')
                ->on('patient_billings')
                ->restrictOnDelete();

            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $table->foreign(['patient_billing_id', 'clinic_id'], 'patient_billing_items_same_clinic_fk')
                    ->references(['id', 'clinic_id'])
                    ->on('patient_billings')
                    ->restrictOnDelete();
            }

            if (! Schema::hasColumn('patient_billing_items', 'doctor_id')) {
                $table->foreignId('doctor_id')
                    ->nullable()
                    ->after('clinical_procedure_id')
                    ->constrained('clinic_employees')
                    ->restrictOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patient_billing_items', function (Blueprint $table) {
            if (Schema::hasColumn('patient_billing_items', 'doctor_id')) {
                $table->dropConstrainedForeignId('doctor_id');
            }

            // Zero-cascade rule: do NOT restore CASCADE on rollback.
        });
    }
};
