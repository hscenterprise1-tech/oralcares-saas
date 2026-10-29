<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The death record, set only through the governed `patient.record_death`
 * action (and cleared only through `patient.correct_death`): the date of
 * death, and who recorded it and when. The reason stays on the governance
 * request and the timeline; no cause of death is stored. Nullable, additive:
 * existing records are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->date('date_of_death')->nullable()->after('status');
            $table->timestamp('death_recorded_at')->nullable()->after('date_of_death');
            $table->foreignId('death_recorded_by')->nullable()->after('death_recorded_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('death_recorded_by');
            $table->dropColumn(['date_of_death', 'death_recorded_at']);
        });
    }
};
