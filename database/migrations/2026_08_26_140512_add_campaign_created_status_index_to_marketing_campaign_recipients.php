<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing Analytics' campaign-performance table aggregates recipients by
 * `campaign_id` (an IN-list of the matched campaigns) + a `created_at` date
 * range, grouped by `campaign_id, status`. Measured via EXPLAIN on a seeded
 * 150-campaign / 45,000-recipient dataset spread over a year: with only the
 * existing single-column indexes, this ran as a full table scan
 * (`type: ALL`, 44,856 of 45,000 rows examined) for a 30-day window.
 *
 * Two composite shapes were tested empirically before choosing this one:
 *   - (campaign_id, status, created_at): covering, but MySQL still had to
 *     scan the entire index (`type: index`, still 44,856 rows) since the
 *     date range sits after status in the key.
 *   - (campaign_id, created_at, status): lets MySQL range-scan straight to
 *     the selected date window per campaign (`type: range`, only 4,500 rows
 *     — a 10x cut, and one that stays roughly constant as more history
 *     accumulates, unlike the full scan which grows with total table size).
 *
 * The second shape won on both EXPLAIN row counts and measured wall time
 * (131.7ms → 38.1ms for the full campaign-performance table on that
 * dataset), so `created_at` is the middle column here, not `status`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_campaign_recipients', function (Blueprint $table) {
            $table->index(['campaign_id', 'created_at', 'status'], 'mcr_campaign_created_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_campaign_recipients', function (Blueprint $table) {
            $table->dropIndex('mcr_campaign_created_status_idx');
        });
    }
};
