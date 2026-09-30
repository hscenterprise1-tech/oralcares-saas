<?php

declare(strict_types=1);

namespace App\Services\CustomerSuccess;

use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\SubscriptionStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The four things Customer Success actually does to a contract.
 *
 * Until now it could do none of them. A customer wanting more seats, a cheaper
 * plan, another year or an exit had to be routed into the CRM as a *deal* —
 * the same pipeline used to win a stranger — or handled by somebody editing the
 * database. Renewing an existing customer onto the plan they already have is
 * not a sale, and treating it as one is why the renewals queue was a list you
 * could read and not act on.
 *
 * Every act here does the same four things, in one transaction:
 *
 *   1. changes the subscription,
 *   2. writes a `subscription_events` row saying what changed and who did it,
 *   3. leaves the recorded MRR alone unless the caller states a new one,
 *   4. hands the account to {@see AccountSnapshotService} so the lists agree.
 *
 * `subscription_events` already existed with `from_plan_id`, `to_plan_id`,
 * `changes`, `actor_id` and `reason` — the audit trail was built and nothing
 * was writing to it.
 *
 * Money is never inferred. A plan change does not silently adopt the new plan's
 * price: the caller passes the MRR that was agreed, or passes nothing and the
 * figure stays as it was and is reported as unchanged. Guessing revenue is how
 * a projection stops being trustworthy.
 */
class SubscriptionActionService
{
    public function __construct(protected AccountSnapshotService $snapshots) {}

    /**
     * Move a customer onto a different plan.
     *
     * @param  array{mrr?: float|null, max_users?: int|null, max_branches?: int|null, max_doctors?: int|null, effective_at?: CarbonInterface|null, reason?: string|null}  $options
     */
    public function changePlan(
        CompanySubscription $subscription,
        CompanyPlan $plan,
        ?User $actor = null,
        array $options = [],
    ): CompanySubscription {
        $from = $subscription->company_plan_id;

        if ($from === $plan->id && ! $this->touchesLimits($options)) {
            throw new InvalidArgumentException('That account is already on this plan.');
        }

        return $this->apply(
            $subscription,
            event: $from === $plan->id ? 'entitlements_changed' : 'plan_changed',
            actor: $actor,
            reason: $options['reason'] ?? null,
            fromPlanId: $from,
            toPlanId: $plan->id,
            attributes: array_filter([
                'company_plan_id' => $plan->id,
                'mrr' => $options['mrr'] ?? null,
                'max_users' => $options['max_users'] ?? null,
                'max_branches' => $options['max_branches'] ?? null,
                'max_doctors' => $options['max_doctors'] ?? null,
                'starts_at' => $options['effective_at'] ?? null,
            ], fn ($value) => $value !== null),
        );
    }

    /**
     * Change what a customer is entitled to without moving them off their plan.
     *
     * @param  array{max_users?: int|null, max_branches?: int|null, max_doctors?: int|null, mrr?: float|null, reason?: string|null}  $limits
     */
    public function adjustEntitlements(
        CompanySubscription $subscription,
        array $limits,
        ?User $actor = null,
    ): CompanySubscription {
        if (! $this->touchesLimits($limits)) {
            throw new InvalidArgumentException('Name at least one limit to change.');
        }

        return $this->apply(
            $subscription,
            event: 'entitlements_changed',
            actor: $actor,
            reason: $limits['reason'] ?? null,
            attributes: array_filter([
                'max_users' => $limits['max_users'] ?? null,
                'max_branches' => $limits['max_branches'] ?? null,
                'max_doctors' => $limits['max_doctors'] ?? null,
                'mrr' => $limits['mrr'] ?? null,
            ], fn ($value) => $value !== null),
        );
    }

    /**
     * Renew a contract in place.
     *
     * The common case, and the one that had no home: same customer, same plan,
     * another term. The new term starts where the old one ended rather than
     * today, so a renewal processed three days late does not quietly give away
     * three days — and one processed early does not overlap.
     *
     * A lapsed contract renews from today instead, because there is no unbroken
     * term to extend.
     *
     * @param  array{plan?: CompanyPlan|null, mrr?: float|null, reason?: string|null}  $options
     */
    public function renew(
        CompanySubscription $subscription,
        int $months = 12,
        ?User $actor = null,
        array $options = [],
    ): CompanySubscription {
        if ($months < 1) {
            throw new InvalidArgumentException('A renewal has to add at least one month.');
        }

        $currentEnd = $subscription->ends_at;
        $from = $currentEnd && $currentEnd->isFuture() ? $currentEnd->copy() : now();

        $plan = $options['plan'] ?? null;

        return $this->apply(
            $subscription,
            event: 'renewed',
            actor: $actor,
            reason: $options['reason'] ?? null,
            fromPlanId: $plan ? $subscription->company_plan_id : null,
            toPlanId: $plan?->id,
            attributes: array_filter([
                'ends_at' => $from->copy()->addMonths($months),
                'status' => SubscriptionStatus::Active,
                /*
                 * A renewal clears the cancellation. A customer who cancelled
                 * and then renewed is not cancelled, and leaving the stamp in
                 * place would keep them out of every active count.
                 */
                'canceled_at' => false,
                'company_plan_id' => $plan?->id,
                'mrr' => $options['mrr'] ?? null,
            ], fn ($value) => $value !== null),
            extraChanges: ['months_added' => $months],
        );
    }

    /**
     * End a contract, with the reason recorded.
     *
     * Cancelling is not deleting: the row stays, the term stays, and the
     * customer keeps their access until the date they paid through. What
     * changes is that nobody expects them to renew.
     */
    public function cancel(
        CompanySubscription $subscription,
        string $reason,
        ?User $actor = null,
        ?CarbonInterface $effectiveAt = null,
    ): CompanySubscription {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A cancellation has to say why.');
        }

        $effective = $effectiveAt ?? now();

        return $this->apply(
            $subscription,
            event: 'canceled',
            actor: $actor,
            reason: $reason,
            attributes: [
                'canceled_at' => $effective,
                'status' => SubscriptionStatus::Cancelled,
                /*
                 * The term is not cut short. They paid through `ends_at` and
                 * `EntitlementResolver` reads that date to decide access.
                 */
            ],
        );
    }

    /** Whether any entitlement limit is named in the options. */
    private function touchesLimits(array $options): bool
    {
        foreach (['max_users', 'max_branches', 'max_doctors', 'mrr'] as $key) {
            if (($options[$key] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Change the subscription, record what changed, refresh the projection.
     *
     * `false` in `$attributes` means "set this column to null" — `array_filter`
     * upstream strips real nulls, so a deliberate clearing needs a value that
     * survives it.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $extraChanges
     */
    private function apply(
        CompanySubscription $subscription,
        string $event,
        ?User $actor,
        ?string $reason,
        array $attributes,
        ?int $fromPlanId = null,
        ?int $toPlanId = null,
        array $extraChanges = [],
    ): CompanySubscription {
        $attributes = array_map(fn ($value) => $value === false ? null : $value, $attributes);

        $before = $subscription->only(array_keys($attributes));

        DB::transaction(function () use ($subscription, $attributes, $before, $event, $actor, $reason, $fromPlanId, $toPlanId, $extraChanges) {
            $subscription->update($attributes);

            /* Only what actually moved, so the trail reads as a diff. */
            $changes = [];

            foreach ($attributes as $column => $value) {
                $was = $before[$column] ?? null;
                $wasScalar = $was instanceof \BackedEnum ? $was->value : ($was instanceof CarbonInterface ? $was->toDateString() : $was);
                $isScalar = $value instanceof \BackedEnum ? $value->value : ($value instanceof CarbonInterface ? $value->toDateString() : $value);

                if ((string) $wasScalar !== (string) $isScalar) {
                    $changes[$column] = ['from' => $wasScalar, 'to' => $isScalar];
                }
            }

            SubscriptionEvent::create([
                'company_subscription_id' => $subscription->id,
                'organization_id' => $subscription->organization_id,
                'event' => $event,
                'from_plan_id' => $fromPlanId,
                'to_plan_id' => $toPlanId,
                'changes' => $changes + $extraChanges,
                'actor_id' => $actor?->id,
                'reason' => $reason,
            ]);
        });

        /*
         * Outside the transaction: the projection is derived state, and holding
         * a write lock across recomputing an account's health is how a routine
         * plan change becomes a lock-wait timeout.
         */
        $this->snapshots->refreshForSubscription($subscription->fresh());

        return $subscription->refresh();
    }
}
