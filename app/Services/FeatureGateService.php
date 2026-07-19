<?php

namespace App\Services;

use App\Events\AddonRequired;
use App\Events\FeatureDisabled;
use App\Exceptions\FeatureDisabledException;
use App\Models\Clinic;

class FeatureGateService
{
    public function __construct(
        protected EntitlementService $entitlementService,
        protected ResourceLimitService $limitService
    ) {}

    /**
     * Determine if the clinic has a feature enabled.
     */
    public function hasFeature(Clinic $clinic, string $featureKey): bool
    {
        $sub = $this->limitService->getActiveSubscription($clinic);
        if (! $sub) {
            return false;
        }

        return $this->entitlementService->hasFeature($sub, $featureKey);
    }

    /**
     * Enforce that a feature is enabled, throwing an exception if not.
     *
     * @throws FeatureDisabledException
     */
    public function requiresFeature(Clinic $clinic, string $featureKey): void
    {
        if (! $this->hasFeature($clinic, $featureKey)) {
            event(new FeatureDisabled($clinic, $featureKey));
            throw new FeatureDisabledException("The feature '{$featureKey}' is not enabled under your subscription.");
        }
    }

    /**
     * Enforce that a clinic has purchased a specific addon.
     *
     * @throws FeatureDisabledException
     */
    public function requiresAddon(Clinic $clinic, string $addonType, string $reason): void
    {
        $sub = $this->limitService->getActiveSubscription($clinic);
        if (! $sub) {
            throw new FeatureDisabledException('No active subscription found.');
        }

        $hasAddon = $sub->addons()
            ->where('addon_type', $addonType)
            ->where('is_active', true)
            ->exists();

        if (! $hasAddon) {
            event(new AddonRequired($clinic, $addonType, $reason));
            throw new FeatureDisabledException("Purchasing the '{$addonType}' addon is required: {$reason}");
        }
    }
}
