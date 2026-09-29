<?php

namespace App\Services\Provisioning;

use App\Models\Clinic;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\License;
use App\Models\Organization;
use Carbon\CarbonInterface;

/**
 * What a tenant may do, right now.
 *
 * Entitlement was scattered: limits are copied onto `company_subscriptions`
 * *and* `licenses`, and read directly from both, so "how many users may they
 * have" had two answers that nothing kept in step. That is survivable while
 * nothing ever changes a plan, and it is exactly what makes an upgrade risky.
 *
 * One resolver answers it instead. Every consumer asks this — the grace
 * middleware, the telemetry snapshot, the CRM payload, and later the post-sale
 * expansion module — so adding a seat or a module means changing one row
 * rather than finding every place that reads a limit.
 *
 * It reads; it never writes. Provisioning decides entitlements, this reports
 * them.
 */
class EntitlementResolver
{
    /**
     * A plan with no modules attached grants the least, never the most —
     * same rule as an unrecognised plan. `PackageFormModal` lets an admin
     * leave every module unchecked; that must read as "starter-equivalent",
     * not as "everything".
     */
    public const FALLBACK_MODULES = ['appointments', 'patients', 'billing'];

    /** Days of full access after a trial's end date. */
    public const GRACE_DAYS = 7;

    /**
     * The complete entitlement picture for a tenant.
     *
     * @return array<string, mixed>
     */
    public function forOrganization(Organization $organization): array
    {
        $subscription = CompanySubscription::query()
            ->where('organization_id', $organization->id)
            ->latest('id')
            ->first();

        return $this->fromSubscription($subscription, $organization);
    }

    /** The same, resolved from the clinic the portal routes by. */
    public function forClinic(Clinic $clinic): array
    {
        $subscription = CompanySubscription::query()
            ->where('clinic_id', $clinic->id)
            ->latest('id')
            ->first();

        return $this->fromSubscription($subscription, $clinic->organization);
    }

    /**
     * @return array<string, mixed>
     */
    public function fromSubscription(?CompanySubscription $subscription, ?Organization $organization = null): array
    {
        if (! $subscription) {
            return [
                'has_subscription' => false,
                'window' => ['state' => 'none', 'days_remaining' => null, 'trial_ends_at' => null, 'grace_ends_at' => null, 'ends_at' => null],
                'grants_access' => false,
                'plan' => null,
                'limits' => ['users' => null, 'doctors' => null, 'branches' => null, 'storage_mb' => null],
                'modules' => [],
                'whatsapp_quota' => null,
            ];
        }

        $plan = $subscription->company_plan_id ? CompanyPlan::find($subscription->company_plan_id) : null;
        $license = $organization
            ? License::where('organization_id', $organization->id)->latest('id')->first()
            : null;

        $window = $this->window($subscription);

        return [
            'has_subscription' => true,
            'window' => $window,

            /*
             * Grace grants full access — that is the decision this whole phase
             * is built on. Only a lapsed window closes the door.
             */
            'grants_access' => in_array($window['state'], ['trial', 'grace', 'paid'], true),

            'plan' => $plan ? ['id' => $plan->id, 'key' => $plan->key, 'name' => $plan->name] : null,

            'limits' => [
                'users' => $subscription->max_users ?? $plan?->max_users,
                'doctors' => $subscription->max_doctors ?? $plan?->max_doctors,
                'branches' => $subscription->max_branches ?? $plan?->max_branches,
                'storage_mb' => $license?->storage_limit_mb ?? $this->planStorageMb($plan),
            ],

            'modules' => $this->modulesFor($plan),

            /*
             * No quota column exists on plans, accounts or licences, so this is
             * reported as unavailable rather than invented. When one is added
             * it is read here and the surfaces above need no change.
             */
            'whatsapp_quota' => null,
        ];
    }

    /**
     * Which phase of its life the subscription is in.
     *
     * Order matters: a subscription can carry a trial end, a grace end and a
     * paid end at once during conversion, and the earliest unexpired boundary
     * is the one in force.
     *
     * @return array<string, mixed>
     */
    public function window(CompanySubscription $subscription): array
    {
        $now = now();
        $trialEnds = $subscription->trial_ends_at;
        $graceEnds = $subscription->grace_ends_at;
        $ends = $subscription->ends_at;

        if ($trialEnds && $now->lessThan($trialEnds)) {
            return $this->windowPayload('trial', $trialEnds, $subscription);
        }

        if ($graceEnds && $now->lessThan($graceEnds)) {
            return $this->windowPayload('grace', $graceEnds, $subscription);
        }

        /* Trial and grace both spent, and no paid term took over. */
        if ($trialEnds || $graceEnds) {
            if (! $ends || $now->greaterThanOrEqualTo($ends)) {
                return $this->windowPayload('lapsed', null, $subscription);
            }
        }

        if ($ends && $now->greaterThanOrEqualTo($ends)) {
            return $this->windowPayload('lapsed', null, $subscription);
        }

        return $this->windowPayload('paid', $ends, $subscription);
    }

    /**
     * @return array<string, mixed>
     */
    protected function windowPayload(string $state, ?CarbonInterface $boundary, CompanySubscription $subscription): array
    {
        return [
            'state' => $state,
            'days_remaining' => $boundary ? max(0, (int) now()->startOfDay()->diffInDays($boundary->copy()->startOfDay(), false)) : null,
            'trial_ends_at' => $subscription->trial_ends_at?->toISOString(),
            'grace_ends_at' => $subscription->grace_ends_at?->toISOString(),
            'ends_at' => $subscription->ends_at?->toISOString(),
        ];
    }

    /**
     * The modules a plan grants — read from `company_plan_features`, the
     * pivot that already existed for this and sat empty. A plan nobody has
     * configured yet (or that doesn't exist) grants the fallback, never zero
     * and never everything.
     *
     * @return list<string>
     */
    public function modulesFor(?CompanyPlan $plan): array
    {
        if (! $plan) {
            return self::FALLBACK_MODULES;
        }

        $modules = $plan->features()->pluck('key')->all();

        return $modules !== [] ? $modules : self::FALLBACK_MODULES;
    }

    /**
     * Storage in megabytes, from the plan.
     *
     * The provisioning engine hardcoded 10 GB for every customer and ignored
     * `storage_limit_gb` entirely, so Starter and Enterprise received the same
     * allowance regardless of what was sold.
     */
    public function planStorageMb(?CompanyPlan $plan): int
    {
        $gb = $plan?->storage_limit_gb;

        return $gb ? (int) $gb * 1024 : 10240;
    }
}
