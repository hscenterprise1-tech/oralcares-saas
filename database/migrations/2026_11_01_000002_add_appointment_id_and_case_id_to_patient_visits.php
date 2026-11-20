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
        Schema::table('patient_visits', function (Blueprint $table) {
            if (! Schema::hasColumn('patient_visits', 'case_id')) {
                $table->foreignId('case_id')
                    ->nullable()
                    ->after('branch_id')
                    ->constrained('patient_cases')
                    ->restrictOnDelete();
            }

            if (! Schema::hasColumn('patient_visits', 'appointment_id')) {
                $table->foreignId('appointment_id')
                    ->nullable()
                    ->after('patient_id')
                    ->constrained('appointments')
                    ->restrictOnDelete();
            }
        });

        // Deterministic backfill of verified appointment links from clinic_queue_items:
        // Appt #31 -> Visit #4, Appt #32 -> Visit #5, Appt #33 -> Visit #8.
        // The remaining 33 visits remain NULL (walk-ins).
        DB::table('patient_visits')->where('id', 4)->whereNull('appointment_id')->update(['appointment_id' => 31]);
        DB::table('patient_visits')->where('id', 5)->whereNull('appointment_id')->update(['appointment_id' => 32]);
        DB::table('patient_visits')->where('id', 8)->whereNull('appointment_id')->update(['appointment_id' => 33]);

        // Add UNIQUE constraint on appointment_id (MySQL permits multiple NULLs)
        Schema::table('patient_visits', function (Blueprint $table) {
            $table->unique('appointment_id', 'patient_visits_appointment_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patient_visits', function (Blueprint $table) {
            if (Schema::hasColumn('patient_visits', 'appointment_id')) {
                $table->dropUnique('patient_visits_appointment_id_unique');
                $table->dropConstrainedForeignId('appointment_id');
            }

            if (Schema::hasColumn('patient_visits', 'case_id')) {
                $table->dropConstrainedForeignId('case_id');
            }
        });
    }
};
