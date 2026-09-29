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
        Schema::table('prescriptions', function (Blueprint $table) {
            // Check if organization_id exists, otherwise add it
            if (! Schema::hasColumn('prescriptions', 'organization_id')) {
                // To safely add constrained columns to existing tables, we might need nullable or a default,
                // but since this is greenfield dev Phase, we'll assume it's fine.
                $table->foreignId('organization_id')->after('id')->nullable()->constrained('organizations')->cascadeOnDelete();
            }

            // Drop legacy doctor_id
            if (Schema::hasColumn('prescriptions', 'doctor_id')) {
                // Drop foreign key first using the known name from Phase 1
                $foreignKeys = Schema::getForeignKeys('prescriptions');
                $foreignKeyNames = array_column($foreignKeys, 'name');

                if (in_array('rx_doc_fk', $foreignKeyNames)) {
                    $table->dropForeign('rx_doc_fk');
                }

                if (in_array('prescriptions_doctor_id_foreign', $foreignKeyNames)) {
                    $table->dropForeign(['doctor_id']);
                }

                $table->dropColumn('doctor_id');
            }

            // Add new prescribed_by referencing users
            if (! Schema::hasColumn('prescriptions', 'prescribed_by')) {
                $table->foreignId('prescribed_by')->nullable()->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('prescriptions', 'details')) {
                $table->json('details')->nullable();
            }
        });

        Schema::table('prescription_items', function (Blueprint $table) {
            if (! Schema::hasColumn('prescription_items', 'drug_master_id')) {
                // Not enforcing foreign key to a drug_masters table yet since it doesn't exist, just leaving the column.
                // Or we can just make it bigInteger.
                $table->unsignedBigInteger('drug_master_id')->nullable()->after('prescription_id');
            }

            if (! Schema::hasColumn('prescription_items', 'details')) {
                $table->json('details')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');

            $table->dropForeign(['prescribed_by']);
            $table->dropColumn('prescribed_by');

            $table->dropColumn('details');

            $table->foreignId('doctor_id')->nullable()->constrained('clinic_employees')->nullOnDelete()->name('rx_doc_fk');
        });

        Schema::table('prescription_items', function (Blueprint $table) {
            $table->dropColumn('drug_master_id');
            $table->dropColumn('details');
        });
    }
};
