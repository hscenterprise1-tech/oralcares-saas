<?php

namespace App\Jobs;

use App\DemoStatus;
use App\Models\Demo;
use App\Services\LeadWorkspaceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Remind the customer, twice, before their demo.
 *
 * Once the day before and once two hours out. Two reminders rather than one
 * because they do different jobs: the 24-hour note gives somebody time to move
 * the appointment, the 2-hour note is the one that actually gets them to the
 * call.
 *
 * Runs hourly, and is safe to run as often as you like. Each window is claimed
 * by stamping its column — `customer_reminder_24h_at`, `customer_reminder_2h_at`
 * — and a demo whose column is already set is not selected again. That is why
 * those columns exist: before them there was no record of who had been told
 * what, so a second run of the day's reminders sent everything twice.
 *
 * `SendDemoDayRemindersJob` is not replaced by this. That one is the rep's
 * morning digest and the single same-day customer nudge; this is the pre-demo
 * pair. The overlap is deliberate and bounded by the stamps: a demo booked for
 * this afternoon receives the 2-hour reminder from here exactly once, whichever
 * job gets there first.
 */
class SendCustomerDemoRemindersJob implements ShouldQueue
{
    use Queueable;

    /**
     * How late a window may be claimed.
     *
     * A job that did not run for three hours must still send the 2-hour
     * reminder for a demo starting in twenty minutes — late is better than
     * silent. But it must not send a "starts in two hours" message for a demo
     * that finished yesterday, which is what an unbounded lower edge would do
     * after an outage.
     */
    private const GRACE_MINUTES = 30;

    public function handle(LeadWorkspaceService $workspace): void
    {
        $now = Carbon::now();

        $sent = [
            '24h' => $this->sweep($workspace, $now, 24 * 60, 'customer_reminder_24h_at'),
            '2h' => $this->sweep($workspace, $now, 120, 'customer_reminder_2h_at'),
        ];

        Log::info('Customer demo reminders swept', $sent);
    }

    /**
     * Send one window's reminders and claim each demo as it goes.
     *
     * The window is "starting within the next N minutes, and not more than
     * GRACE_MINUTES ago". Anything already stamped is excluded by the query
     * rather than skipped in PHP, so a large backlog does not become a large
     * read.
     *
     * @param  int  $leadMinutes  How far ahead this window reaches.
     * @param  string  $stampColumn  The column that marks this window as served.
     */
    private function sweep(LeadWorkspaceService $workspace, Carbon $now, int $leadMinutes, string $stampColumn): int
    {
        $demos = Demo::query()
            ->whereNull($stampColumn)
            ->whereIn('status', [DemoStatus::Scheduled, DemoStatus::Confirmed])
            ->whereBetween('scheduled_at', [
                $now->copy()->subMinutes(self::GRACE_MINUTES),
                $now->copy()->addMinutes($leadMinutes),
            ])
            ->with(['lead', 'presenter'])
            ->orderBy('scheduled_at')
            ->get();

        $sent = 0;

        foreach ($demos as $demo) {
            if (! $demo->lead) {
                continue;
            }

            /*
             * Stamped whether or not the send succeeded.
             *
             * `sendClientDemoReminder()` returns false for a reason — the
             * contact opted out, the tenant is in an emergency freeze, it is
             * quiet hours, there is no phone number — and every one of those is
             * a decision, not a transient error. Retrying them hourly until the
             * demo starts would mean re-deciding the same "no" a dozen times and
             * writing a timeline entry each time. The reminder is recorded as
             * attempted; the timeline says what came of it.
             */
            $delivered = $workspace->sendClientDemoReminder($demo);

            $demo->forceFill([$stampColumn => $now])->save();

            if ($delivered) {
                $sent++;
            }
        }

        return $sent;
    }
}
