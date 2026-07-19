<?php

namespace App\Services;

use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\CompanySubscriptionAddon;
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
            $limits = $plan ? [
                'company_plan_id' => $plan->id,
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
}
