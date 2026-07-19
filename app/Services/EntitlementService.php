<?php

namespace App\Services;

use App\Models\CompanyFeature;
use App\Models\CompanySubscription;
use App\Models\CompanySubscriptionEntitlement;
use App\SubscriptionStatus;

class EntitlementService
{
    /**
     * Check if a feature is enabled on the subscription.
     */
    public function hasFeature(CompanySubscription $subscription, string $featureKey): bool
    {
        if ($subscription->status !== SubscriptionStatus::Active) {
            return false;
        }

        $override = $subscription->entitlements()
            ->whereHas('feature', function ($query) use ($featureKey) {
                $query->where('key', $featureKey);
            })
            ->first();

        if ($override !== null) {
            return (bool) $override->is_enabled;
        }

        if (! $subscription->plan) {
            return false;
        }

        return $subscription->plan->features()
            ->where('key', $featureKey)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Add or update an entitlement override on the subscription.
     */
    public function setEntitlementOverride(
        CompanySubscription $subscription,
        string $featureKey,
        bool $isEnabled,
        ?int $usageLimit = null,
        bool $isUnlimited = false
    ): CompanySubscriptionEntitlement {
        $feature = CompanyFeature::where('key', $featureKey)->firstOrFail();

        return CompanySubscriptionEntitlement::updateOrCreate(
            [
                'company_subscription_id' => $subscription->id,
                'company_feature_id' => $feature->id,
            ],
            [
                'is_enabled' => $isEnabled,
                'usage_limit' => $usageLimit,
                'is_unlimited' => $isUnlimited,
            ]
        );
    }
}
