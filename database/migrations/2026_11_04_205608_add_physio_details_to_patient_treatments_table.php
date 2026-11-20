<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A physiotherapy problem's complaint as the doctor recorded it (duration,
     * severity, daily difficulties, previous treatment, notes) and its plan's
     * category and instructions, with every earlier version kept
     * (`PhysioConsultation::saveDetails`). The goal stays in `goal`.
     */
    public function up(): void
    {
        Schema::table('patient_treatments', function (Blueprint $table) {
            $table->json('physio_details')->nullable()->after('visit_frequency');
        });
    }

    public function down(): void
    {
        Schema::table('patient_treatments', function (Blueprint $table) {
            $table->dropColumn('physio_details');
        });
    }
};
