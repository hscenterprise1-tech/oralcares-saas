<?php

namespace App\Services;

use App\Events\SubscriptionActivated;
use App\Events\SubscriptionExpired;
use App\Events\SubscriptionRenewed;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\CompanySubscriptionAddon;
use App\Services\Finance\MrrService;
use App\SubscriptionStatus;
use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    /**
     * Create a new subscription, copying resource limits from plan if provided.
     */
    public function createSubscription(array $subData, ?CompanyPlan $plan = null): CompanySubscription
    {
        return DB::transaction(function () use ($subData, $plan) {
            /*
             * MRR is stamped here, once, from the price in force today.
             *
             * Recording it on the subscription is what lets a plan price change
             * later leave historical revenue alone. An explicit `mrr` in
             * $subData wins, so a negotiated rate is not overwritten by the
             * list price.
             */
            $limits = $plan ? [
                'company_plan_id' => $plan->id,
                'mrr' => $subData['mrr'] ?? app(MrrService::class)->snapshotValueFor($plan),
                'max_users' => $plan->max_users ?? 5,
                'max_doctors' => $plan->max_doctors ?? 3,
                'max_branches' => $plan->max_branches ?? 1,
                'storage_limit_gb' => $plan->storage_limit_gb ?? 10,
            ] : [];

            $subscription = CompanySubscription::create(array_merge($limits, $subData));

            $subscription->refresh();

            return $subscription;
        });
    }

    /**
     * Transition subscription status lifecycle.
     */
    public function transitionStatus(CompanySubscription $subscription, SubscriptionStatus|string $newStatus): void
    {
        $statusEnum = $newStatus instanceof SubscriptionStatus ? $newStatus : SubscriptionStatus::from($newStatus);

        $subscription->status = $statusEnum;
        if ($statusEnum === SubscriptionStatus::Cancelled) {
            $subscription->canceled_at = now();
        }
        $subscription->save();

        if ($statusEnum === SubscriptionStatus::Active && $subscription->clinic) {
            event(new SubscriptionActivated($subscription->clinic, $subscription));
        } elseif ($statusEnum === SubscriptionStatus::Expired && $subscription->clinic) {
            event(new SubscriptionExpired($subscription->clinic, $subscription));
        }
    }

    /**
     * Purchase a new addon for a subscription.
     */
    public function purchaseAddon(
        CompanySubscription $subscription,
        string $addonType,
        int $qty,
        float $price
    ): CompanySubscriptionAddon {
        return $subscription->addons()->create([
            'addon_type' => $addonType,
            'quantity' => $qty,
            'price' => $price,
            'is_active' => true,
            'activated_at' => now(),
        ]);
    }

    /**
     * Activate subscription.
     */
    public function activateSubscription(CompanySubscription $subscription): void
    {
        $this->transitionStatus($subscription, SubscriptionStatus::Active);
    }

    /**
     * Renew subscription.
     */
    public function renewSubscription(CompanySubscription $subscription, int $monthsToAdd = 12): void
    {
        DB::transaction(function () use ($subscription, $monthsToAdd) {
            $currentEndsAt = $subscription->ends_at ?? now();
            $subscription->ends_at = $currentEndsAt->addMonths($monthsToAdd);
            $subscription->status = SubscriptionStatus::Active;
            $subscription->save();

            if ($subscription->clinic) {
                event(new SubscriptionRenewed($subscription->clinic, $subscription));
            }
        });
    }

    /**
     * Cancel subscription.
     */
    public function cancelSubscription(CompanySubscription $subscription): void
    {
        $this->transitionStatus($subscription, SubscriptionStatus::Cancelled);
    }

    /**
     * Suspend subscription.
     */
    public function suspendSubscription(CompanySubscription $subscription): void
    {
        $this->transitionStatus($subscription, SubscriptionStatus::Suspended);
    }

    /**
     * Check if a subscription is under a grace period (e.g. within 14 days after ends_at).
     */
    public function isUnderGracePeriod(CompanySubscription $subscription): bool
    {
        if ($subscription->status !== SubscriptionStatus::Expired) {
            return false;
        }

        $endsAt = $subscription->ends_at;
        if (! $endsAt) {
            return false;
        }

        // Default grace period of 14 days
        $graceEnd = $endsAt->copy()->addDays(14);

        return now()->between($endsAt, $graceEnd);
    }
}
