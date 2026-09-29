<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index the two sortable columns that had no index of their own.
 *
 * The Customer Success list lets a reader sort by any projected column, and
 * every one of them is read straight off an index — InnoDB appends the table's
 * primary key to each secondary index, so `ORDER BY <column>, css.id LIMIT 25`
 * never sorts anything. Health, churn risk, days to expiry, seat utilisation
 * and stage were already covered; these two were not, and paid for it:
 *
 *     ORDER BY css.last_activity_at, css.id   115 ms, unindexed
 *     ORDER BY css.days_to_expiry,   css.id     0.6 ms, indexed
 *
 * Measured on a seeded book of 120,000 accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_success_snapshots', function (Blueprint $table) {
            $table->index('last_activity_at', 'cs_snapshots_last_activity_idx');
            $table->index('renewal_probability', 'cs_snapshots_renewal_probability_idx');
        });
    }

    public function down(): void
    {
        Schema::table('customer_success_snapshots', function (Blueprint $table) {
            $table->dropIndex('cs_snapshots_last_activity_idx');
            $table->dropIndex('cs_snapshots_renewal_probability_idx');
        });
    }
};
