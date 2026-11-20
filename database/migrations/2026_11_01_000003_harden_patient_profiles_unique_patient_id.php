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
        // 1. Synchronize legacy data if any pincode was populated without postal_code
        DB::statement('UPDATE patient_profiles SET postal_code = pincode WHERE postal_code IS NULL AND pincode IS NOT NULL');

        // 2. Harden patient_id to a UNIQUE constraint (1:1 extension) while preserving pincode column
        Schema::table('patient_profiles', function (Blueprint $table) {
            $table->unique('patient_id', 'patient_profiles_patient_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patient_profiles', function (Blueprint $table) {
            $table->dropUnique('patient_profiles_patient_id_unique');
        });
    }
};
