<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a trial was lost, and what it was doing before it was.
 *
 * A trial could be completed, cancelled or left to lapse, but none of those
 * recorded *why* or *to whom*. The deal has carried `lost_reason` and
 * `lost_competitor` since the loss-triage work; the trial — which is where the
 * evaluation is actually won or lost — carried neither, so a trial that died
 * could only be read as an absence.
 *
 * `usage_telemetry` is the live cache, deliberately separate from the nightly
 * history in `trial_usage_snapshots`. The Trials list shows current activity for
 * every row; reading that from the snapshot table means a join or a query per
 * trial, which is the exact N+1 shape this rebuild is removing elsewhere. One
 * JSON column on the row it describes is read for free with the row.
 *
 * Four columns the specification asked for already exist under their own names
 * and are reused rather than duplicated — a second column holding the same fact
 * is how a figure and the list beneath it start to disagree:
 *
 *   extended_count    → `extension_count`   (int, default 0)
 *   last_extended_at  → `extended_at`       (timestamp)
 *   extension_reason  → `extension_reason`  (text)
 *   company_plan_id   → already present, already constrained to `company_plans`
 *
 * `usage_metrics` also already exists, but it is not the same thing: nothing has
 * ever written it (see `TrialController::update()`), which is why the live cache
 * is a new column rather than a revival of a stale one. Dropping `usage_metrics`
 * is a follow-up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trials', function (Blueprint $table) {
            /*
             * Churn, recorded where the evaluation actually happens.
             *
             * `lost_reason` is a string rather than an enum so the vocabulary can
             * change without a migration; the allowed values are enforced at the
             * request layer, where the dropdown that produces them lives.
             */
            $table->timestamp('lost_at')->nullable()->after('cancellation_reason');
            $table->string('lost_reason')->nullable()->after('lost_at');
            $table->string('lost_competitor')->nullable()->after('lost_reason');

            /*
             * Live activity, refreshed by TrialService::syncTrialUsage().
             * Nullable and meaningless-until-written on purpose: "we have not
             * measured this tenant yet" must not read as "this tenant did
             * nothing".
             */
            $table->json('usage_telemetry')->nullable()->after('usage_metrics');

            // The Trials workspace filters on lost trials; without this the
            // "lost" phase is a scan.
            $table->index('lost_at', 'trials_lost_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('trials', function (Blueprint $table) {
            $table->dropIndex('trials_lost_at_index');
            $table->dropColumn(['lost_at', 'lost_reason', 'lost_competitor', 'usage_telemetry']);
        });
    }
};
