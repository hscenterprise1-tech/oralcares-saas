<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The clinic's follow-up worklist: how urgent a follow-up is, the team's
 * notes on it, and an index for the worklist's open-by-date reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_follow_ups', function (Blueprint $table) {
            // 0 normal, 1 high, 2 urgent: a number, so the worklist can page by it.
            $table->unsignedTinyInteger('priority')->default(0)->after('reason');
            $table->text('notes')->nullable()->after('priority');
            $table->index(['clinic_id', 'status', 'due_on', 'priority'], 'follow_ups_clinic_status_due');
        });
    }

    public function down(): void
    {
        Schema::table('patient_follow_ups', function (Blueprint $table) {
            $table->dropIndex('follow_ups_clinic_status_due');
            $table->dropColumn(['priority', 'notes']);
        });
    }
};
