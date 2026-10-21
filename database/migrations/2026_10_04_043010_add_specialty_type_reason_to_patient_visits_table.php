<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What today's visit is for: the specialty, the visit type (a key from the
 * clinic's effective visit-type list) and a short reason. Real columns, so
 * reporting can group and filter on them. All nullable: earlier visits and
 * callers that do not send them stay valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_visits', function (Blueprint $table) {
            $table->unsignedBigInteger('clinic_specialty_id')->nullable()->after('doctor_id');
            $table->string('visit_type_key', 64)->nullable()->after('clinic_specialty_id');
            $table->string('visit_reason', 255)->nullable()->after('visit_type_key');

            $table->foreign(['clinic_specialty_id', 'clinic_id'], 'patient_visits_specialty_same_clinic_fk')->references(['id', 'clinic_id'])->on('clinic_specialties')->restrictOnDelete();
            $table->index(['clinic_id', 'clinic_specialty_id', 'visit_date'], 'patient_visits_specialty_index');
            $table->index(['clinic_id', 'visit_type_key', 'visit_date'], 'patient_visits_type_index');
        });
    }

    public function down(): void
    {
        Schema::table('patient_visits', function (Blueprint $table) {
            $table->dropForeign('patient_visits_specialty_same_clinic_fk');
            $table->dropIndex('patient_visits_specialty_index');
            $table->dropIndex('patient_visits_type_index');
            $table->dropColumn(['clinic_specialty_id', 'visit_type_key', 'visit_reason']);
        });
    }
};
