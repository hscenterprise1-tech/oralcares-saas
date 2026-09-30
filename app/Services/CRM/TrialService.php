<?php

namespace App\Services\CRM;

use App\ActivityType;
use App\Jobs\TrialUsageSnapshotJob;
use App\LeadStatus;
use App\LostReason;
use App\Models\Trial;
use App\Models\User;
use App\Services\LeadWorkspaceService;
use App\Services\Provisioning\TenantTelemetryService;
use App\TrialOutcome;
use App\TrialStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The three trial actions that had no home.
 *
 * Extending a trial and cancelling one already live on
 * {@see TrialLifecycleService}, which does more than move a date — it carries
 * the customer's entitlement window with it, so a trial and the access behind it
 * cannot disagree. This class does not re-implement either; `extend()` here
 * delegates, and exists so a caller has one obvious place to reach for.
 *
 * What is genuinely new:
 *
 *   syncTrialUsage()  caches live tenant activity on the trial row, so the
 *                     Trials list can show what every customer is doing without
 *                     a query per row
 *   markLost()        records *why* an evaluation died, and to whom
 *   convertToCustomer() routes to the one conversion path rather than adding a
 *                     second one
 */
class TrialService
{
    /**
     * Beyond this, a trial that has not been opened is a trial nobody is
     * evaluating. Two days rather than one: a customer who did not log in on a
     * Sunday has not gone quiet.
     */
    public const INACTIVE_AFTER_HOURS = 48;

    /*
     * There is no trial-specific loss vocabulary.
     *
     * A trial is lost for the same reasons a deal is — price, a competitor, a
     * missing feature — and `leads.lost_reason` already casts to
     * {@see \App\LostReason}. A second list here would have to be mapped onto
     * that enum to record the loss on the deal at all, and the two would drift
     * the first time somebody added a reason to one of them. `markLost()` takes
     * a `LostReason`.
     */

    public function __construct(
        protected TrialLifecycleService $lifecycle,
        protected TenantTelemetryService $telemetry,
        protected LeadWorkspaceService $workspace,
    ) {}

    /**
     * Measure what this trial's tenant is actually doing, and cache it on the row.
     *
     * The nightly {@see TrialUsageSnapshotJob} already writes history
     * into `trial_usage_snapshots`, and that history is what the trend chart
     * reads. This is the other half: the *current* picture, stored on the trial
     * itself so a list of two hundred trials can show live activity for every
     * one of them in the query that loaded the list.
     *
     * Returns null — and writes nothing — for a trial with no environment. A
     * tenant that was never provisioned has no activity to report, and writing
     * a row of zeros would make "we never built it" indistinguishable from
     * "they never used it".
     *
     * @return array<string, mixed>|null
     */
    public function syncTrialUsage(Trial $trial, ?Carbon $now = null): ?array
    {
        $now ??= Carbon::now();

        $clinic = $trial->clinic;

        if (! $clinic || ! $trial->provisioned_at) {
            return null;
        }

        $metrics = $this->telemetry->capture($clinic, $clinic->organization, $now);

        $lastLoginAt = $metrics['last_login_at'] ?? null;
        $lastLogin = $lastLoginAt ? Carbon::parse($lastLoginAt) : null;

        $telemetry = [
            'patients' => (int) ($metrics['patients_count'] ?? 0),
            'prescriptions' => (int) ($metrics['prescriptions_count'] ?? 0),
            'invoices' => (int) ($metrics['invoices_count'] ?? 0),
            'appointments' => (int) ($metrics['appointments_count'] ?? 0),
            'users_total' => (int) ($metrics['users_total'] ?? 0),
            'logins_7d' => (int) ($metrics['logins_7d'] ?? 0),
            'last_login_at' => $lastLogin?->toISOString(),
            /*
             * Derived here rather than in the page, so the list, the detail page
             * and any future report agree on what "active" means.
             */
            'is_active_today' => $lastLogin?->isSameDay($now) ?? false,
            'is_inactive' => $lastLogin === null
                || $lastLogin->diffInHours($now) >= self::INACTIVE_AFTER_HOURS,
            'hours_since_login' => $lastLogin ? (int) $lastLogin->diffInHours($now) : null,
            'measured_at' => $now->toISOString(),
        ];

        $trial->forceFill([
            'usage_telemetry' => $telemetry,
            // Kept in step because the trials list and the lifecycle reminders
            // still read these two columns directly.
            'last_login' => $lastLogin,
            'users_created' => $telemetry['users_total'],
        ])->save();

        return $telemetry;
    }

    /**
     * Extend a trial's window, and the customer's access with it.
     *
     * Delegates to {@see TrialLifecycleService::extend()} rather than
     * reimplementing it: that method moves the subscription window, resets the
     * expiry warnings so the customer gets them again against the new date, and
     * writes the audit entry. Any of those missed separately would produce a
     * trial that says "extended" while the product locks the customer out on the
     * original date.
     */
    public function extend(Trial $trial, int $days, string $reason, ?User $user = null): Trial
    {
        return $this->lifecycle->extend($trial, $days, $reason, $user);
    }

    /**
     * Record that the evaluation was lost, and take the deal with it.
     *
     * A trial could previously be completed, cancelled or left to lapse, and
     * none of those said why. "Lost" is a different fact from "cancelled": a
     * cancelled trial is one we stopped, a lost one is a customer who decided
     * against us — and that decision, with the competitor who won, is the only
     * part of a failed evaluation worth anything later.
     *
     * The deal moves too. A lost trial sitting under an open deal is how a
     * pipeline ends up reporting revenue nobody is going to close.
     */
    public function markLost(Trial $trial, LostReason $reason, ?string $competitor = null, ?User $user = null): Trial
    {
        // Idempotent. A trial is lost once; pressing the button twice must not
        // write a second timeline entry or re-triage a deal somebody has since
        // annotated.
        if ($trial->lost_at) {
            return $trial;
        }

        return DB::transaction(function () use ($trial, $reason, $competitor, $user) {
            $now = Carbon::now();

            $trial->forceFill([
                'lost_at' => $now,
                'lost_reason' => $reason->value,
                'lost_competitor' => $competitor,
                'outcome' => TrialOutcome::Lost,
                /*
                 * Completed, not cancelled. The evaluation ran and produced an
                 * answer; `cancelled` is reserved for a trial we stopped, and
                 * conflating them would make "they said no" and "we pulled it"
                 * the same row.
                 */
                'status' => TrialStatus::Completed,
                'completed_at' => $trial->completed_at ?? $now,
            ])->save();

            /*
             * Loaded fresh, not off the relation.
             *
             * A caller holding a Trial whose `lead` was loaded earlier in the
             * request gets whatever the deal's stage was *then* — and the guard
             * below turns on exactly that value. A deal won a moment ago would
             * have read as still open and been dragged back to Lost.
             */
            $lead = $trial->lead()->first();

            $lead?->activities()->create([
                'user_id' => $user?->id,
                'type' => ActivityType::Trial,
                'description' => $competitor
                    ? "Trial lost — {$reason->value} (to {$competitor})"
                    : "Trial lost — {$reason->value}",
                'properties' => [
                    'trial_id' => $trial->id,
                    'lost_reason' => $reason->value,
                    'lost_competitor' => $competitor,
                ],
            ]);

            /*
             * A deal that is already closed is left alone. Losing a trial on an
             * account we have already sold to is a fact about the trial, not a
             * reason to reopen a won sale — and a deal already marked lost has
             * its reason recorded by whoever marked it.
             */
            if ($lead && ! in_array($this->leadStage($lead->status), [LeadStatus::Won->value, LeadStatus::Lost->value], true)) {
                /*
                 * Two steps, because they are two things. `transitionStage()`
                 * moves the deal and runs the side effects of closing one —
                 * cancelling pending tasks, clearing the follow-up schedule.
                 * `triageLostLead()` records why. Triage alone would leave a
                 * lost trial under an open deal, which is how a pipeline reports
                 * revenue nobody is going to close.
                 */
                /*
                 * The reason argument must be a `LostReason` value, not prose.
                 * `transitionStage()` assigns it straight to `leads.lost_reason`,
                 * which is enum-cast — anything else throws a ValueError on the
                 * way back out, which is a 500 rather than a refusal.
                 */
                $this->workspace->transitionStage(
                    $lead,
                    LeadStatus::Lost->value,
                    $user,
                    $reason->value,
                );

                $this->workspace->triageLostLead($lead, [
                    'lost_reason' => $reason->value,
                    'lost_competitor' => $competitor,
                    'notes' => 'Lost during trial evaluation.',
                ], $user);
            }

            return $trial->fresh();
        });
    }

    /**
     * Turn a successful evaluation into a paying customer.
     *
     * There is one conversion path — `LeadController::convert()` — and it
     * enforces the commercial gate: an approved quotation, settled in full.
     * This does not add a second one. It answers whether the trial is ready and
     * hands back what is missing if not, so a caller can route the rep to the
     * quotation they still have to raise instead of failing at the last step.
     *
     * @return array{ready:bool, blockers:list<string>, lead_id:?int}
     */
    public function convertToCustomer(Trial $trial, TrialPageService $page): array
    {
        $blockers = $page->convertBlockers($trial);

        return [
            'ready' => $blockers === [],
            'blockers' => $blockers,
            'lead_id' => $trial->lead_id,
        ];
    }

    private function leadStage(mixed $status): string
    {
        return $status instanceof LeadStatus ? $status->value : (string) $status;
    }
}
