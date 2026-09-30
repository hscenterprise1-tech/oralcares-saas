<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A package cannot offer a trial length the product does not sell.
 *
 * `company_plans.trial_duration_days` was created with a hardcoded default of
 * 15 and seeded at 15, while the CRM offers trials of 7 or 14 days and
 * defaults to 14. Every package therefore advertised "15-day trial" on screen
 * for a trial nobody could actually start at that length.
 *
 * Rows still sitting on the old default are moved to the configured default.
 * Anything a human has since chosen deliberately — any value that is not 15 —
 * is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $default = (int) config('crm.trial_default_days', 14);

        DB::table('company_plans')
            ->where('trial_duration_days', 15)
            ->update(['trial_duration_days' => $default]);
    }

    /**
     * Irreversible: 15 was never a length this product offered, so restoring
     * it would only put the contradiction back.
     */
    public function down(): void {}
};
