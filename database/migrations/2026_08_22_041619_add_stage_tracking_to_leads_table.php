<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stage velocity tracking.
     *
     * `stage_entered_at` is the timestamp the lead moved into its current
     * stage. Combined with the existing stage-change activity records it gives
     * current stage age, average stage duration and bottleneck analysis without
     * needing a separate stage-history table.
     *
     * `stage_durations` accumulates total seconds spent per stage as a JSON map
     * ({"contacted": 86400, ...}) so historical velocity survives even after the
     * lead moves on.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (! Schema::hasColumn('leads', 'stage_entered_at')) {
                $table->timestamp('stage_entered_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('leads', 'stage_durations')) {
                $table->json('stage_durations')->nullable()->after('stage_entered_at');
            }
        });

        // Backfill existing rows so age calculations have a baseline rather
        // than reporting null/infinite age for every pre-existing lead.
        if (Schema::hasColumn('leads', 'stage_entered_at')) {
            DB::table('leads')
                ->whereNull('stage_entered_at')
                ->update(['stage_entered_at' => DB::raw('COALESCE(updated_at, created_at)')]);
        }
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['stage_entered_at', 'stage_durations']);
        });
    }
};
