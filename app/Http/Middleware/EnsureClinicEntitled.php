<?php

namespace App\Http\Middleware;

use App\Services\ClinicContext;
use App\Services\Provisioning\EntitlementResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Layer 1 of clinic authorization: does the clinic's plan include this
 * module at all? Used as `clinic_entitled:patients`.
 *
 * It reads the same `EntitlementResolver::forClinic()['modules']` list that
 * the clinic nav is filtered by (see `HandleInertiaRequests`). A module
 * hidden from the nav is therefore refused here too, and the plan can no
 * longer be bypassed by typing the URL. The per-user permission check
 * (`clinic_permission:*`) is a separate, later layer.
 */
class EnsureClinicEntitled
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected EntitlementResolver $entitlements,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $clinic = $this->clinicContext->clinic();

        abort_if(! $clinic, 403);

        $entitlement = $this->entitlements->forClinic($clinic);

        /*
         * The same rule `EnforceSubscriptionWindow` applies: a clinic with no
         * subscription row at all predates subscriptions and is left alone,
         * not locked out. Backfilling those clinics' subscriptions is tracked
         * as its own item. Every clinic that does have a subscription is held
         * to its plan's modules.
         */
        if (! $entitlement['has_subscription']) {
            return $next($request);
        }

        abort_unless(
            in_array($module, $entitlement['modules'], true),
            403,
            'Your clinic\'s plan does not include this module.'
        );

        return $next($request);
    }
}
