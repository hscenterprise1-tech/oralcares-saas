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
        Schema::table('patient_billing_payments', function (Blueprint $table) {
            // Drop cascade foreign keys
            $table->dropForeign(['patient_billing_id']);

            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $table->dropForeign('patient_billing_payments_same_clinic_fk');
            }

            // Re-add with ON DELETE RESTRICT
            $table->foreign('patient_billing_id', 'patient_billing_payments_patient_billing_id_foreign')
                ->references('id')
                ->on('patient_billings')
                ->restrictOnDelete();

            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $table->foreign(['patient_billing_id', 'clinic_id'], 'patient_billing_payments_same_clinic_fk')
                    ->references(['id', 'clinic_id'])
                    ->on('patient_billings')
                    ->restrictOnDelete();
            }

            if (! Schema::hasColumn('patient_billing_payments', 'status')) {
                $table->string('status', 20)->default('successful')->after('amount');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patient_billing_payments', function (Blueprint $table) {
            if (Schema::hasColumn('patient_billing_payments', 'status')) {
                $table->dropColumn('status');
            }

            // Zero-cascade rule: do NOT restore CASCADE on rollback.
        });
    }
};
