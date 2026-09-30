<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `assigned` stops being a pipeline stage.
 *
 * It meant "somebody owns this", which is now true of every lead from the
 * moment it is created — see `LeadAssignmentService`. As a stage it sat between
 * New and Contacted collecting deals that had an owner and no conversation, so
 * the first conversion rate in the funnel measured paperwork rather than
 * selling.
 *
 * Rows carrying it are read back to New rather than pushed to Contacted: New is
 * what they actually were. Contacted now asserts that somebody was contacted and
 * these rows have no evidence of that, so promoting them would launder the very
 * guard the stage was given.
 *
 * The enum case itself survives so historical activity payloads and
 * `stage_durations` keys still hydrate; nothing can transition into it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $moved = DB::table('leads')->where('status', 'assigned')->update([
            'status' => 'new',
            /*
             * Their stage clock restarts, because the stage did.
             *
             * Leaving the old timestamp would have every migrated lead appear
             * to have sat in New since whenever it was assigned, and the
             * stalled reports would fire on all of them at once on the morning
             * this ships.
             */
            'stage_entered_at' => now(),
            'updated_at' => now(),
        ]);

        if ($moved > 0) {
            /*
             * Written as one row per lead would be one row per lead; the count
             * is the fact worth keeping, and the stage history on each deal
             * already records how it got to `assigned` in the first place.
             */
            info("Retired the `assigned` lead stage: {$moved} lead(s) read back to `new`.");
        }
    }

    public function down(): void
    {
        /*
         * Deliberately irreversible.
         *
         * Nothing distinguishes a lead that was migrated from one that was
         * always New, so a down migration could only guess — and guessing would
         * move genuinely-new leads into a stage that no longer exists.
         */
    }
};
