<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** How often a physio course's visits are booked (`PhysioConsultation::FREQUENCIES`): daily, alternate… */
    public function up(): void
    {
        Schema::table('patient_treatments', function (Blueprint $table) {
            $table->string('visit_frequency', 20)->nullable()->after('session_type');
        });
    }

    public function down(): void
    {
        Schema::table('patient_treatments', function (Blueprint $table) {
            $table->dropColumn('visit_frequency');
        });
    }
};
