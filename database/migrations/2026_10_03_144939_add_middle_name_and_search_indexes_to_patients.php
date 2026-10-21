<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patient master: a middle name, and the clinic-leading indexes duplicate
 * detection and the directory search need (email, date of birth, name
 * prefixes, status listing).
 *
 * Production note: on MySQL 8 a column added to `patients` (which carries a
 * FULLTEXT index) rebuilds the table with ALGORITHM=COPY — about ten minutes
 * per ALTER at 100k rows on the perf database. Everything here is one Blueprint
 * so Laravel emits as few statements as it can; on a large production table
 * run it through an online schema tool (gh-ost / pt-online-schema-change)
 * instead of `migrate`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('middle_name', 100)->nullable()->after('first_name');

            $table->index(['clinic_id', 'email'], 'patients_clinic_email_index');
            $table->index(['clinic_id', 'date_of_birth'], 'patients_clinic_dob_index');
            $table->index(['clinic_id', 'first_name'], 'patients_clinic_first_name_index');
            $table->index(['clinic_id', 'last_name'], 'patients_clinic_last_name_index');
            $table->index(['clinic_id', 'status', 'created_at'], 'patients_clinic_status_created_index');
            // Target of the same-clinic composite foreign keys on patient extension tables.
            $table->unique(['id', 'clinic_id'], 'patients_id_clinic_unique');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex('patients_clinic_email_index');
            $table->dropIndex('patients_clinic_dob_index');
            $table->dropIndex('patients_clinic_first_name_index');
            $table->dropIndex('patients_clinic_last_name_index');
            $table->dropIndex('patients_clinic_status_created_index');
            $table->dropUnique('patients_id_clinic_unique');
            $table->dropColumn('middle_name');
        });
    }
};
