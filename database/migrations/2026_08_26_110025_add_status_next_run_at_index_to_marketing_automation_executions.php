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
        // Backs the scheduled "resume due delays" scan (status = 'waiting' AND
        // next_run_at <= now), introduced alongside this migration. The
        // existing next_run_at-only index doesn't exclude the far larger set
        // of completed/stopped/failed rows that once waited and still carry
        // a stale next_run_at — this composite index does, and only grows
        // with the (small, active) waiting set rather than all history.
        Schema::table('marketing_automation_executions', function (Blueprint $table) {
            $table->index(['status', 'next_run_at'], 'mkt_auto_exec_stat_next_run_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('marketing_automation_executions', function (Blueprint $table) {
            $table->dropIndex('mkt_auto_exec_stat_next_run_idx');
        });
    }
};
