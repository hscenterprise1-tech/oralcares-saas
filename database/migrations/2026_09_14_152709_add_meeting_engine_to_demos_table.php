<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The meeting itself, and proof that we told people about it.
 *
 * A demo previously carried `meeting_link` and nothing else: no record of which
 * tool the link belongs to, no dial-in id or passcode for the people who join by
 * phone, and — the reason this exists — no record of whether the rep and the
 * customer were ever actually reminded. "Did anyone tell the doctor?" was not a
 * question the row could answer, so the reminder jobs could not be made
 * idempotent and a redeploy could send the same reminder twice.
 *
 * The three `*_notified_at` / `*_reminder_*_at` columns are the idempotency keys
 * for SendDailyRepAgendaJob and SendCustomerDemoRemindersJob. A reminder is sent
 * when its column is null and the window is open, and stamping the column is
 * what stops it being sent again.
 *
 * `meeting_link` is not added here — it already exists.
 *
 * On `meeting_platform` vs the existing `demo_mode`: `demo_mode` answers
 * online/in-person and drives how the demo is presented; `meeting_platform`
 * answers *which tool*. They overlap on `in_person`, so the backfill below seeds
 * `meeting_platform` from `demo_mode` and the application keeps them in step.
 * Collapsing the two into one column is a follow-up, not something to do while
 * rows are live.
 *
 * The `(status, scheduled_at)` index serves every read that matters here: the
 * reminder sweeps ("scheduled, in the next two hours"), My Work's demo tab and
 * the Overview's "demos today". Without it each is a scan of the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demos', function (Blueprint $table) {
            $table->enum('meeting_platform', ['google_meet', 'zoom', 'in_person'])
                ->default('google_meet')
                ->after('demo_mode');

            // Separate from the link: someone joining by phone needs these and
            // cannot extract them from a URL reliably.
            $table->string('meeting_id')->nullable()->after('meeting_link');
            $table->string('passcode')->nullable()->after('meeting_id');

            /*
             * Notification audit. Null means "not yet sent", which is what the
             * jobs select on — so these are the idempotency keys, not decoration.
             */
            $table->timestamp('rep_notified_at')->nullable()->after('passcode');
            $table->timestamp('customer_reminder_24h_at')->nullable()->after('rep_notified_at');
            $table->timestamp('customer_reminder_2h_at')->nullable()->after('customer_reminder_24h_at');

            $table->index(['status', 'scheduled_at'], 'demos_status_scheduled_at_index');
        });

        /*
         * Existing rows get a platform consistent with the mode they were booked
         * under, rather than every historical in-person demo claiming to be a
         * Google Meet because that is the column default.
         */
        DB::table('demos')
            ->where('demo_mode', 'in_person')
            ->update(['meeting_platform' => 'in_person']);
    }

    public function down(): void
    {
        Schema::table('demos', function (Blueprint $table) {
            $table->dropIndex('demos_status_scheduled_at_index');
            $table->dropColumn([
                'meeting_platform',
                'meeting_id',
                'passcode',
                'rep_notified_at',
                'customer_reminder_24h_at',
                'customer_reminder_2h_at',
            ]);
        });
    }
};
