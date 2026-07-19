<?php

namespace App\Http\Middleware;

use App\Exceptions\FeatureDisabledException;
use App\Exceptions\SubscriptionExpiredException;
use App\Exceptions\SubscriptionSuspendedException;
use App\Services\ClinicContext;
use App\Services\FeatureGateService;
use App\Services\ResourceLimitService;
use App\SubscriptionStatus;
use Closure;
use Illuminate\Http\Request;

class RequireFeatureEntitlement
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected FeatureGateService $featureGate,
        protected ResourceLimitService $limitService
    ) {}

    /**
     * Handle an incoming request.
     *
     * @throws FeatureDisabledException|SubscriptionSuspendedException|SubscriptionExpiredException
     */
    public function handle(Request $request, Closure $next, string $featureKey)
    {
        $clinic = $this->clinicContext->getClinic();

        if (! $clinic) {
            return $next($request);
        }

        $subscription = $this->limitService->getActiveSubscription($clinic);

        if (! $subscription) {
            // Fallback check if subscription is suspended or expired in database
            $anySub = $clinic->subscriptions()->latest()->first();
            if ($anySub) {
                if ($anySub->status === SubscriptionStatus::Suspended) {
                    throw new SubscriptionSuspendedException('Your subscription is suspended.');
                }
                if ($anySub->status === SubscriptionStatus::Expired || ($anySub->ends_at && $anySub->ends_at->isPast())) {
                    throw new SubscriptionExpiredException('Your subscription has expired.');
                }
            }

            throw new FeatureDisabledException('No active subscription found for this clinic.');
        }

        // Validate ends_at is not in the past
        if ($subscription->ends_at && $subscription->ends_at->isPast()) {
            $subscription->update(['status' => SubscriptionStatus::Expired]);
            throw new SubscriptionExpiredException('Your subscription has expired.');
        }

        // Validate feature entitlement
        if (! $this->featureGate->hasFeature($clinic, $featureKey)) {
            throw new FeatureDisabledException("Feature '{$featureKey}' is disabled under your plan.");
        }

        return $next($request);
    }
}
