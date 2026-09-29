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
        if (Schema::hasColumn('clinical_encounters', 'clinic_id')) {
            Schema::table('clinical_encounters', function (Blueprint $table) {
                $table->dropForeign(['clinic_id']);
                $table->dropColumn('clinic_id');
            });
        }

        Schema::table('clinical_encounters', function (Blueprint $table) {
            if (! Schema::hasColumn('clinical_encounters', 'organization_id')) {
                $table->foreignId('organization_id')->after('id')->constrained('organizations')->cascadeOnDelete();
            }

            if (Schema::hasColumn('clinical_encounters', 'doctor_id')) {
                $table->dropColumn('doctor_id');
            }

            if (! Schema::hasColumn('clinical_encounters', 'provider_id')) {
                $table->foreignId('provider_id')->after('branch_id')->nullable()->constrained('doctor_profiles')->nullOnDelete();
            }

            if (! Schema::hasColumn('clinical_encounters', 'encounter_type')) {
                $table->string('encounter_type')->after('encounter_number')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('clinical_encounters', function (Blueprint $table) {
            $table->dropColumn('encounter_type');
            $table->dropConstrainedForeignId('provider_id');
            $table->foreignId('doctor_id')->nullable()->constrained('clinic_employees')->nullOnDelete();

            $table->dropConstrainedForeignId('organization_id');
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
        });
    }
};
