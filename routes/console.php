<?php

use App\Jobs\CheckUnassignedLeadSlaJob;
use App\Jobs\EscalateOverdueFollowupsJob;
use App\Jobs\EscalateStalledDealsJob;
use App\Jobs\ExpireOverdueQuotationsJob;
use App\Jobs\ProcessDueMarketingAutomationDelaysJob;
use App\Jobs\ProcessDueWinBacksJob;
use App\Jobs\ProcessTrialLifecycleRemindersJob;
use App\Jobs\RecalculateStaleLeadScoresJob;
use App\Jobs\SendCustomerDemoRemindersJob;
use App\Jobs\SendDailyRepAgendaJob;
use App\Jobs\SendDemoDayRemindersJob;
use App\Jobs\TrialUsageSnapshotJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new ProcessDueMarketingAutomationDelaysJob)->everyMinute()->withoutOverlapping();
Schedule::job(new CheckUnassignedLeadSlaJob)->hourly()->withoutOverlapping();
Schedule::job(new EscalateOverdueFollowupsJob)->hourly()->withoutOverlapping();
Schedule::command('instagram:maintain-buffers')->hourly();
Schedule::job(new SendDemoDayRemindersJob)->dailyAt('08:00')->withoutOverlapping();

/*
 * The rep's whole day, in one message, before they start it.
 *
 * 08:30 India time, explicitly — the application runs in UTC, so `dailyAt()`
 * alone would have sent this in the middle of the afternoon. Half an hour after
 * the demo-day pass so the demos it counts are already confirmed for today.
 */
Schedule::job(new SendDailyRepAgendaJob)
    ->dailyAt('08:30')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping();

/*
 * Customer demo reminders, hourly.
 *
 * Hourly rather than at fixed times because the windows are relative to each
 * demo, not to the clock: a demo at 14:20 needs its two-hour reminder at 12:20.
 * Each window is claimed by stamping its column, so running this more often
 * sends nothing twice.
 */
Schedule::job(new SendCustomerDemoRemindersJob)->hourly()->withoutOverlapping();
Schedule::job(new ProcessTrialLifecycleRemindersJob)->dailyAt('08:15')->withoutOverlapping();
/*
 * Overnight, before the lifecycle pass reads the day's picture. Each run is a
 * dozen aggregate queries per live trial against a customer's clinic database,
 * so it happens when nobody is working rather than through the day.
 */
Schedule::job(new TrialUsageSnapshotJob)->dailyAt('02:30')->withoutOverlapping();
Schedule::job(new ProcessDueWinBacksJob)->dailyAt('08:30')->withoutOverlapping();

/*
 * Database backups (docs/security/backups.md). Only where real
 * infrastructure exists. Locally there is no mysqldump and no backup bucket,
 * and a failing nightly job would only add noise. A restore test runs weekly;
 * the production check fails when the newest passing one is older than
 * BACKUP_MAX_VERIFICATION_AGE_DAYS.
 */
Schedule::command('backup:run')->dailyAt('01:30')->withoutOverlapping()->environments(['production', 'staging']);
Schedule::command('backup:verify')->weeklyOn(0, '04:00')->withoutOverlapping()->environments(['production', 'staging']);
Schedule::job(new ExpireOverdueQuotationsJob)->dailyAt('00:30')->withoutOverlapping();

/*
 * Stalled deals get one escalation task and one notification, not a fresh
 * pair every morning — see the job's own docblock for how it recognises the
 * one it already raised. Ahead of the daily rep agenda so a stalled deal's
 * task is already on today's list, not tomorrow's.
 */
Schedule::job(new EscalateStalledDealsJob)->dailyAt('08:00')->withoutOverlapping();

/*
 * Reruns the live scoring formula for every open lead nobody touched today —
 * not a second scoring rule, just the one `LeadObserver` already applies on
 * write, kept current for leads that never get written. Early, so a stale
 * score does not linger into the day's own dashboards.
 */
Schedule::job(new RecalculateStaleLeadScoresJob)->dailyAt('01:00')->withoutOverlapping();

/*
 * The Customer Success projection, nightly.
 *
 * Without this the snapshot only ever moves when somebody writes to an account,
 * and most of what it holds moves on its own: `days_to_expiry` counts down, a
 * subscription lapses, a trial's window closes, an account goes quiet. A book
 * refreshed only on write would have told the renewals queue that a contract
 * expiring in thirty days was still thirty days away a month later — and the
 * "renewing in 30 days", "lapsed" and "over capacity" worklists are all read
 * from these columns.
 *
 * 02:00, ahead of the trial usage snapshot at 02:30, so both read a settled
 * picture. Chunked by `refreshAllChunks()`, so memory is bounded at 120,000
 * accounts rather than proportional to them.
 */
Schedule::command('cs:snapshots:refresh')->dailyAt('02:00')->withoutOverlapping();

/*
 * MRR, once a month, on the last day.
 *
 * Recorded rather than recomputed: this row is what Revenue's growth figure and
 * trend chart read months later, and a plan price change afterwards must not be
 * able to rewrite it. Running it again within the same month replaces that
 * month only.
 */
Schedule::command('finance:capture-mrr')->monthlyOn(1, '00:15')->withoutOverlapping();

/*
 * Reception day close: every 15 minutes, each branch whose own operating
 * hours (split shifts included) have ended gets its queue closed and its
 * never-checked-in bookings marked no-show. See CloseQueueDay.
 */
Schedule::command('queue:close-day')->everyFifteenMinutes()->withoutOverlapping();
