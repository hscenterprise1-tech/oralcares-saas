<?php

namespace App\Services\CRM;

use App\ActivityType;
use App\LeadStatus;
use App\Models\CompanySubscription;
use App\Models\SubscriptionEvent;
use App\Models\Task;
use App\Models\Trial;
use App\Models\User;
use App\Services\LeadWorkspaceService;
use App\Services\Provisioning\EntitlementResolver;
use App\Shared\Notification\Enums\NotificationPriority;
use App\SubscriptionStatus;
use App\TaskPriority;
use App\TaskStatus;
use App\TaskType;
use App\TrialStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Where a trial is, and what that means for everyone involved.
 *
 * Two things were wrong with how this worked before.
 *
 * The status was a value somebody set by hand, so it described whatever the
 * last person had clicked rather than where the trial actually was. A trial
 * whose end date passed on Sunday stayed "active" until a human noticed.
 *
 * And the reminder pass asked calendar questions — `isTomorrow()`, `isPast()`.
 * Those are only true on one specific day, so a scheduler that missed its slot
 * skipped that milestone forever, while a scheduler that ran twice sent the
 * client two WhatsApp messages and the rep two tasks.
 *
 * So: status is *derived* from the dates, never stored as an opinion; and each
 * milestone is recorded on the trial as it fires. A missed run catches up,
 * because the marker is absent. A repeated run does nothing, because it is
 * present. Both properties come from the same one line of bookkeeping.
 *
 * Entitlement questions are never answered here. {@see EntitlementResolver} is
 * the single source of truth for what a tenant may do; this service moves the
 * dates that resolver reads.
 */
class TrialLifecycleService
{
    /** How close to the end date a trial starts reading as "ending soon". */
    public const ENDING_SOON_DAYS = 3;

    /**
     * Milestones, as day offsets from the end date.
     *
     * Negative is before the trial ends, zero is the end date itself, positive
     * is inside the grace period. Ordered, because a catch-up run fires every
     * milestone it missed in sequence.
     *
     * @var array<string, int>
     */
    public const MILESTONES = [
        'pre_3' => -3,
        'pre_1' => -1,
        'end_day' => 0,
        'grace_3' => 3,
        'grace_6' => 6,
        /*
         * +8, not +7. The trial runs through the end of its end date, so grace
         * covers days +1 to +7 — seven full days, which is what was promised.
         * Day +7 is the customer's last day of access, which is exactly why the
         * "access ends tomorrow" warning belongs on +6. Access has actually
         * gone on +8, and that is when we say so.
         */
        'lapsed' => 8,
    ];

    /** Statuses a person set deliberately. The scheduler never overrides these. */
    public const TERMINAL = [
        TrialStatus::Completed,
        TrialStatus::QuotationRequested,
        TrialStatus::Cancelled,
    ];

    public function __construct(
        protected LeadWorkspaceService $workspace,
        protected EntitlementResolver $entitlements,
    ) {}

    /**
     * The status this trial should have right now.
     *
     * Pure: reads dates, returns a status, touches nothing. That is what makes
     * it safe to call from a payload, a test or the scheduler alike, and it is
     * why the status can no longer disagree with the dates underneath it.
     */
    public function resolveStatus(Trial $trial, ?CarbonInterface $now = null): TrialStatus
    {
        $now = $now ? Carbon::parse($now) : Carbon::now();

        if (in_array($trial->status, self::TERMINAL, true)) {
            return $trial->status;
        }

        /* No environment yet — whatever the dates say, the customer cannot try anything. */
        if (! $trial->isProvisioned()) {
            return TrialStatus::Preparing;
        }

        $end = $trial->end_date?->copy()->endOfDay();

        if (! $end) {
            return TrialStatus::Active;
        }

        if ($now->lessThan($end)) {
            if ($this->daysUntil($now, $end) <= self::ENDING_SOON_DAYS) {
                return TrialStatus::EndingSoon;
            }

            /*
             * Extended outranks Active but not EndingSoon: once an extended
             * trial approaches its new end date, the urgent fact is that it is
             * ending, not that it was extended a fortnight ago.
             */
            return $trial->extension_count > 0 ? TrialStatus::Extended : TrialStatus::Active;
        }

        $graceEnd = $trial->grace_ends_at ?? $end->copy()->addDays(EntitlementResolver::GRACE_DAYS);

        return $now->lessThan($graceEnd) ? TrialStatus::InGrace : TrialStatus::Expired;
    }

    /**
     * Bring one trial up to date: its status, and every milestone it has passed.
     *
     * @return array{status: TrialStatus, changed: bool, fired: list<string>, skipped: ?string}
     */
    public function sync(Trial $trial, ?CarbonInterface $now = null): array
    {
        $now = $now ? Carbon::parse($now) : Carbon::now();

        if ($reason = $this->skipReason($trial)) {
            return ['status' => $trial->status, 'changed' => false, 'fired' => [], 'skipped' => $reason];
        }

        $target = $this->resolveStatus($trial, $now);
        $changed = $target !== $trial->status;

        if ($changed) {
            $trial->forceFill(['status' => $target])->save();
        }

        return [
            'status' => $target,
            'changed' => $changed,
            'fired' => $this->fireDueMilestones($trial, $now),
            'skipped' => null,
        ];
    }

    /**
     * Every trial that could still move, brought up to date.
     *
     * Deliberately not filtered by status: a trial whose stored status is stale
     * is exactly the one that needs syncing, so filtering on it would skip the
     * rows that matter most.
     *
     * @return array{synced: int, changed: int, fired: int, skipped: int}
     */
    public function syncAll(?CarbonInterface $now = null, ?int $organizationId = null): array
    {
        $now = $now ? Carbon::parse($now) : Carbon::now();

        $query = Trial::with(['lead', 'assignedUser'])
            ->whereNotIn('status', array_map(fn (TrialStatus $s) => $s->value, self::TERMINAL));

        if ($organizationId) {
            $query->whereHas('lead', fn ($q) => $q->where('organization_id', $organizationId));
        }

        $result = ['synced' => 0, 'changed' => 0, 'fired' => 0, 'skipped' => 0];

        $query->each(function (Trial $trial) use ($now, &$result) {
            $outcome = $this->sync($trial, $now);

            $result['synced']++;
            $result['changed'] += $outcome['changed'] ? 1 : 0;
            $result['fired'] += count($outcome['fired']);
            $result['skipped'] += $outcome['skipped'] ? 1 : 0;
        });

        return $result;
    }

    /**
     * Push the trial window out, and the access behind it with it.
     *
     * Extending used to move `trials.end_date` and nothing else, so a customer
     * granted another week kept reading as extended in CRM while their
     * subscription expired on the original date and locked them out. The trial
     * record and the entitlement have to move together or the extension is a
     * promise the product does not keep.
     *
     * Milestones after the new end date are cleared so the customer gets their
     * warnings again on the new schedule rather than silently missing them.
     */
    public function extend(Trial $trial, int $additionalDays, string $reason, ?User $user = null): Trial
    {
        if (in_array($trial->status, self::TERMINAL, true)) {
            throw new InvalidArgumentException('A '.$trial->status->label().' trial cannot be extended.');
        }

        $additionalDays = max(1, min(60, $additionalDays));

        return DB::transaction(function () use ($trial, $additionalDays, $reason, $user) {
            $oldEnd = $trial->end_date->copy();
            $newEnd = $oldEnd->copy()->addDays($additionalDays)->endOfDay();
            $newGrace = $newEnd->copy()->addDays(EntitlementResolver::GRACE_DAYS);

            $trial->forceFill([
                'original_end_date' => $trial->original_end_date ?? $oldEnd,
                'end_date' => $newEnd,
                'grace_ends_at' => $newGrace,
                'duration_days' => $trial->duration_days + $additionalDays,
                'extension_count' => $trial->extension_count + 1,
                'extended_at' => now(),
                'extension_reason' => $reason,
                /*
                 * A trial with no environment stays in `preparing` however its
                 * dates move — the status describes whether the customer has
                 * something to use, not what the rep last adjusted.
                 */
                'status' => $trial->isProvisioned() ? TrialStatus::Extended : TrialStatus::Preparing,
                /*
                 * The customer is entitled to the full run of warnings against
                 * the new date, so anything from the end day onwards is reset.
                 * The pre-end warnings they already received stay received.
                 */
                'lifecycle_events' => collect($trial->lifecycle_events ?: [])
                    ->reject(fn ($at, $key) => (self::MILESTONES[$key] ?? -100) >= 0)
                    ->all(),
            ])->save();

            $this->moveSubscriptionWindow($trial, $newEnd, $newGrace, SubscriptionEvent::TRIAL_EXTENDED, $user, $reason);

            $trial->lead?->activities()->create([
                'user_id' => $user?->id,
                'type' => ActivityType::Trial,
                'description' => "Trial extended by {$additionalDays} days (to {$newEnd->format('M d, Y')}), access extended with it. Reason: {$reason}",
                'properties' => [
                    'trial_id' => $trial->id,
                    'old_end_date' => $oldEnd->toDateString(),
                    'new_end_date' => $newEnd->toDateString(),
                    'grace_ends_at' => $newGrace->toDateString(),
                    'additional_days' => $additionalDays,
                    'extension_reason' => $reason,
                ],
            ]);

            $this->workspace->sendClientTrialReminder($trial->fresh(), 'extended');

            return $trial->fresh();
        });
    }

    /**
     * End a trial early and take the access with it.
     *
     * Distinct from completing one. Completing records an outcome after the
     * trial ran its course; cancelling stops it — a duplicate account, a
     * prospect who asked to be removed, a mistake. So the subscription window
     * closes now rather than at its natural end.
     *
     * The tenant and its data are left intact. Revoking access is reversible;
     * deleting a clinic somebody has been entering patients into is not, and
     * that decision belongs to a retention policy, not to a cancel button.
     */
    public function cancel(Trial $trial, string $reason, ?User $user = null): Trial
    {
        if ($trial->status === TrialStatus::Cancelled) {
            return $trial;
        }

        if ($this->leadIsWon($trial)) {
            throw new InvalidArgumentException('This deal has been won — cancel the subscription from the account, not the trial.');
        }

        return DB::transaction(function () use ($trial, $reason, $user) {
            $trial->forceFill([
                'status' => TrialStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            $subscription = $this->subscriptionFor($trial);

            if ($subscription) {
                $subscription->update([
                    'status' => SubscriptionStatus::Cancelled,
                    'canceled_at' => now(),
                    /*
                     * Backdated to now, not nulled: the resolver reads
                     * `ends_at` to decide access, and a null there would read
                     * as an open-ended paid term.
                     */
                    'trial_ends_at' => now(),
                    'grace_ends_at' => now(),
                    'ends_at' => now(),
                ]);

                $this->recordEvent($subscription, $trial, SubscriptionEvent::TRIAL_CANCELLED, $user, $reason, [
                    'revoked_at' => now()->toISOString(),
                ]);
            }

            $trial->lead?->activities()->create([
                'user_id' => $user?->id,
                'type' => ActivityType::Trial,
                'description' => "Trial cancelled and access revoked. Reason: {$reason}",
                'properties' => ['trial_id' => $trial->id, 'reason' => $reason],
            ]);

            return $trial->fresh();
        });
    }

    /**
     * Why this trial is being left alone, if it is.
     *
     * The closed-deal guard. A won deal's tenant is a paying customer, and
     * expiring their trial record would revoke the access they have just paid
     * for. A lost deal has already been decided, so chasing its trial with
     * reminders is noise at best.
     */
    public function skipReason(Trial $trial): ?string
    {
        if (in_array($trial->status, self::TERMINAL, true)) {
            return 'trial_closed';
        }

        $status = $trial->lead?->status;

        if ($status === LeadStatus::Won) {
            return 'deal_won';
        }

        if ($status === LeadStatus::Lost) {
            return 'deal_lost';
        }

        return null;
    }

    /**
     * Fire every milestone whose day has arrived and which has not fired yet.
     *
     * The catch-up loop: it walks all milestones rather than asking "is today
     * the day", so a run that happens three days late fires the three it owes.
     *
     * @return list<string>
     */
    protected function fireDueMilestones(Trial $trial, CarbonInterface $now): array
    {
        $end = $trial->end_date?->copy()->endOfDay();

        if (! $end) {
            return [];
        }

        $fired = [];
        $log = $trial->lifecycle_events ?: [];

        foreach (self::MILESTONES as $key => $offset) {
            $due = $end->copy()->addDays($offset)->startOfDay();

            if ($now->lessThan($due) || isset($log[$key])) {
                continue;
            }

            $this->dispatchMilestone($trial, $key, $now);

            $log[$key] = $now->toISOString();
            $fired[] = $key;
        }

        if ($fired !== []) {
            $trial->forceFill(['lifecycle_events' => $log])->save();
        }

        return $fired;
    }

    /**
     * What each milestone actually does.
     *
     * The client hears about the ones that affect them; the rep hears about
     * every one, because a trial going quiet is the thing they most need to
     * know and the thing they are least likely to notice.
     */
    protected function dispatchMilestone(Trial $trial, string $key, CarbonInterface $now): void
    {
        $lead = $trial->lead;

        if (! $lead) {
            return;
        }

        $company = $lead->company_name ?: $lead->contact_name;
        $endStr = $trial->end_date?->format('M d, Y') ?? '';

        match ($key) {
            'pre_3' => $this->milestone(
                $trial, 'ending_soon',
                'Trial Ending in 3 Days',
                "{$company}'s trial ends on {$endStr}. Good moment to ask for the decision.",
                NotificationPriority::NORMAL,
            ),
            'pre_1' => $this->milestone(
                $trial, 'ending_soon',
                'Trial Ending Tomorrow',
                "{$company}'s trial ends tomorrow ({$endStr}). Contact the client today.",
                NotificationPriority::HIGH,
                task: ['title' => "Trial ends tomorrow: {$company}", 'type' => TaskType::Call, 'due' => $now->copy()->addHours(4)],
            ),
            /*
             * Not an ending. The customer keeps full access for another week,
             * and both the client message and the rep's task say so — a rep who
             * believes access is gone sells differently, and worse.
             */
            'end_day' => $this->milestone(
                $trial, 'grace_started',
                'Trial Ended — 7-Day Grace Started',
                "{$company}'s trial period ended. They keep full access for 7 more days. Close it inside that window.",
                NotificationPriority::HIGH,
                task: ['title' => "Grace period started: {$company}", 'type' => TaskType::Call, 'due' => $now->copy()->addHours(4)],
            ),
            'grace_3' => $this->milestone(
                $trial, null,
                'Grace Period Half Spent',
                "{$company} has 4 days of access left. No decision yet.",
                NotificationPriority::HIGH,
            ),
            'grace_6' => $this->milestone(
                $trial, 'grace_ending',
                'Access Ends Tomorrow',
                "{$company} loses access tomorrow. Last chance to extend or close.",
                NotificationPriority::CRITICAL,
                task: ['title' => "Access ends tomorrow: {$company}", 'type' => TaskType::Call, 'due' => $now->copy()->addHours(2)],
            ),
            'lapsed' => $this->milestone(
                $trial, 'lapsed',
                'Trial Access Ended',
                "{$company} has lost access. Their clinic setup is retained — offer to restore it.",
                NotificationPriority::HIGH,
                task: ['title' => "Lapsed trial follow-up: {$company}", 'type' => TaskType::FollowUp, 'due' => $now->copy()->addDay()],
            ),
            default => null,
        };
    }

    /**
     * One milestone's side effects: client message, rep notification, rep task.
     *
     * @param  array{title: string, type: TaskType, due: CarbonInterface}|null  $task
     */
    protected function milestone(Trial $trial, ?string $clientTemplate, string $title, string $message, NotificationPriority $priority, ?array $task = null): void
    {
        if ($clientTemplate) {
            $this->workspace->sendClientTrialReminder($trial, $clientTemplate);
        }

        $owner = $trial->assigned_user_id ?? $trial->lead?->assigned_user_id;

        if ($owner) {
            $this->workspace->notifyUser($owner, $title, $message, $priority, [
                'type' => 'trial_lifecycle',
                'trial_id' => $trial->id,
                'lead_id' => $trial->lead_id,
            ]);
        }

        if ($task && $owner) {
            Task::create([
                'title' => $task['title'],
                'description' => $message,
                'type' => $task['type'],
                'status' => TaskStatus::Pending,
                'priority' => TaskPriority::High,
                'due_at' => $task['due'],
                'assigned_user_id' => $owner,
                'creator_id' => null,
                'taskable_type' => $trial->lead->getMorphClass(),
                'taskable_id' => $trial->lead_id,
            ]);
        }
    }

    /**
     * Move the subscription's trial and grace boundaries.
     *
     * `ends_at` follows grace, not the trial end, because grace is full access.
     */
    protected function moveSubscriptionWindow(Trial $trial, CarbonInterface $end, CarbonInterface $grace, string $event, ?User $user, string $reason): void
    {
        $subscription = $this->subscriptionFor($trial);

        if (! $subscription) {
            return;
        }

        $subscription->update([
            'trial_ends_at' => $end,
            'grace_ends_at' => $grace,
            'ends_at' => $grace,
            'status' => SubscriptionStatus::Active,
        ]);

        $this->recordEvent($subscription, $trial, $event, $user, $reason, [
            'trial_ends_at' => $end->toDateString(),
            'grace_ends_at' => $grace->toDateString(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    protected function recordEvent(CompanySubscription $subscription, Trial $trial, string $event, ?User $user, string $reason, array $changes): void
    {
        SubscriptionEvent::create([
            'company_subscription_id' => $subscription->id,
            'organization_id' => $subscription->organization_id,
            'event' => $event,
            'from_plan_id' => $subscription->company_plan_id,
            'to_plan_id' => $subscription->company_plan_id,
            'actor_id' => $user?->id,
            'lead_id' => $trial->lead_id,
            'reason' => $reason,
            'changes' => $changes,
        ]);
    }

    protected function subscriptionFor(Trial $trial): ?CompanySubscription
    {
        if (! $trial->customer_organization_id) {
            return null;
        }

        return CompanySubscription::where('organization_id', $trial->customer_organization_id)
            ->latest('id')
            ->first();
    }

    protected function leadIsWon(Trial $trial): bool
    {
        return $trial->lead?->status === LeadStatus::Won;
    }

    /** Whole days from now until a boundary, never negative. */
    protected function daysUntil(CarbonInterface $now, CarbonInterface $boundary): int
    {
        return max(0, (int) $now->copy()->startOfDay()->diffInDays($boundary->copy()->startOfDay(), false));
    }
}
