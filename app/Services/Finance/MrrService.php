<?php

namespace App\Services\Finance;

use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\MrrSnapshot;
use App\SubscriptionStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Recurring revenue, recorded rather than recomputed.
 *
 * Two rules carry this class.
 *
 * The first is that a subscription's MRR is written onto the subscription when
 * it is created, from the plan price in force at that moment. It is never
 * re-derived. Raising the Growth plan tomorrow must not change what a customer
 * on last year's price contributed last year.
 *
 * The second is that history comes from snapshots, not from arithmetic on
 * today's figures. Where a month has no snapshot, this says so — the page shows
 * insufficient history instead of a line drawn through invented points.
 */
class MrrService
{
    /**
     * The monthly value of a plan, in the plan's own currency.
     *
     * Only the billing periods the plan table actually uses are handled. An
     * unrecognised period returns null rather than a guess: quietly treating an
     * unknown cadence as monthly would overstate revenue by whatever multiple
     * it really is.
     */
    public function monthlyValueOf(?CompanyPlan $plan): ?float
    {
        if (! $plan || $plan->price === null) {
            return null;
        }

        $price = (float) $plan->price;

        return match ($plan->billing_period) {
            'monthly' => round($price, 2),
            'quarterly' => round($price / 3, 2),
            'half_yearly', 'semi_annual' => round($price / 6, 2),
            'yearly', 'annual' => round($price / 12, 2),
            default => null,
        };
    }

    /**
     * The MRR to stamp on a subscription being created.
     *
     * Called at creation time only. Nothing recalculates it afterwards.
     */
    public function snapshotValueFor(?CompanyPlan $plan): ?float
    {
        return $this->monthlyValueOf($plan);
    }

    /**
     * What the active book is worth right now.
     *
     * `unrecorded` counts active subscriptions carrying no MRR — the two that
     * predate this column, and anything provisioned without a plan. They are
     * reported, never assumed to be zero.
     *
     * @return array{mrr: float, active_subscriptions: int, active_clinics: int, arpu: float, unrecorded: int}
     */
    public function currentPosition(): array
    {
        $active = CompanySubscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->get(['id', 'organization_id', 'mrr']);

        $recorded = $active->filter(fn (CompanySubscription $s) => $s->mrr !== null);
        $mrr = (float) $recorded->sum(fn (CompanySubscription $s) => (float) $s->mrr);
        $clinics = $active->pluck('organization_id')->filter()->unique()->count();

        return [
            'mrr' => round($mrr, 2),
            'active_subscriptions' => $active->count(),
            'active_clinics' => $clinics,
            'arpu' => $clinics > 0 ? round($mrr / $clinics, 2) : 0.0,
            'unrecorded' => $active->count() - $recorded->count(),
        ];
    }

    /**
     * Record this month's position, or overwrite it if taken again today.
     *
     * Re-running within the same month replaces that month's row — a snapshot
     * taken mid-month and again at month end should tell the truth about the
     * month, not accumulate. Earlier months are never touched.
     */
    public function capture(?Carbon $month = null): MrrSnapshot
    {
        $period = ($month ?? now())->format('Y-m');
        $position = $this->currentPosition();

        return MrrSnapshot::updateOrCreate(
            ['period' => $period],
            [
                'mrr' => $position['mrr'],
                'active_subscriptions' => $position['active_subscriptions'],
                'active_clinics' => $position['active_clinics'],
                'arpu' => $position['arpu'],
                'unrecorded_subscriptions' => $position['unrecorded'],
                'captured_at' => now(),
            ],
        );
    }

    /**
     * The last N months of recorded MRR, oldest first.
     *
     * Months with no snapshot are absent, not zero-filled. A gap in the series
     * is information.
     *
     * @return Collection<int, MrrSnapshot>
     */
    public function history(int $months = 6): Collection
    {
        return MrrSnapshot::query()
            ->orderByDesc('period')
            ->limit($months)
            ->get()
            ->sortBy('period')
            ->values();
    }

    /**
     * Month-on-month growth, or null when there is not enough history.
     *
     * Null is a real answer here. The old page hard-coded `growth_rate => 0.0`
     * and printed it beside the MRR as though it had been measured.
     */
    public function growthRate(): ?float
    {
        $recent = MrrSnapshot::query()->orderByDesc('period')->limit(2)->get();

        if ($recent->count() < 2) {
            return null;
        }

        $current = (float) $recent[0]->mrr;
        $previous = (float) $recent[1]->mrr;

        if ($previous <= 0.0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
