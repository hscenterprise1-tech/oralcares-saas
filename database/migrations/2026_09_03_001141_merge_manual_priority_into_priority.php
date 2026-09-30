<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One answer to "how hard should we push this deal".
 *
 * There were two. `priority` (high/medium/low) is what the rebuilt Deals list,
 * its filters and the deal page all read. `manual_priority` (Hot/Warm/Cold) is
 * what four older workspace queries read, and it had no input on any form — so
 * the only way it could hold a value was a seeder, and the two definitions of
 * "hot" disagreed on live rows.
 *
 * A human marking a deal Hot is a stronger signal than a default, so the
 * manual value wins where it was set: Hot becomes high, Warm medium, Cold low.
 * What each row was before is recorded, so the rollback restores the exact
 * prior state rather than guessing an inverse mapping.
 */
return new class extends Migration
{
    private const MAP = [
        'Hot' => 'high',
        'Warm' => 'medium',
        'Cold' => 'low',
    ];

    public function up(): void
    {
        Schema::create('lead_priority_merge_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id')->index();
            $table->string('priority_before')->nullable();
            $table->string('manual_priority')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $rows = DB::table('leads')
            ->whereNotNull('manual_priority')
            ->where('manual_priority', '!=', '')
            ->get(['id', 'priority', 'manual_priority']);

        foreach ($rows as $row) {
            $target = self::MAP[$row->manual_priority] ?? null;

            DB::table('lead_priority_merge_log')->insert([
                'lead_id' => $row->id,
                'priority_before' => $row->priority,
                'manual_priority' => $row->manual_priority,
                'created_at' => now(),
            ]);

            if ($target && $target !== $row->priority) {
                DB::table('leads')->where('id', $row->id)->update(['priority' => $target]);
            }
        }

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('manual_priority');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('manual_priority')->nullable()->after('priority');
        });

        foreach (DB::table('lead_priority_merge_log')->get() as $row) {
            DB::table('leads')->where('id', $row->lead_id)->update([
                'priority' => $row->priority_before,
                'manual_priority' => $row->manual_priority,
            ]);
        }

        Schema::dropIfExists('lead_priority_merge_log');
    }
};
