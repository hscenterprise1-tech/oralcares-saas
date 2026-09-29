<?php

use App\LeadStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make qualification explicit.
 *
 * The lifecycle had no way to say "someone decided this lead is real". The
 * smallest model change that expresses it: one nullable timestamp. Everything
 * else reuses what exists —
 *
 *   unqualified   status is new or assigned, qualified_at null
 *   qualified     qualified_at set (stamped on reaching contacted or beyond)
 *   disqualified  status is lost with qualified_at still null
 *
 * No new status, no new table, and a disqualified lead is findable exactly
 * where a lost one already is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('qualified_at')->nullable()->after('status');
            $table->index('qualified_at');
        });

        // Backfill: anything that has already progressed past the intake
        // stages was, in substance, qualified. Use the moment it entered its
        // current stage rather than inventing a date.
        $qualifiedStatuses = collect(LeadStatus::cases())
            ->reject(fn (LeadStatus $s) => in_array($s->value, ['new', 'assigned', 'lost'], true))
            ->map(fn (LeadStatus $s) => $s->value)
            ->all();

        DB::table('leads')
            ->whereIn('status', $qualifiedStatuses)
            ->update(['qualified_at' => DB::raw('COALESCE(stage_entered_at, created_at)')]);
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['qualified_at']);
            $table->dropColumn('qualified_at');
        });
    }
};
