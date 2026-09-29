<?php

namespace App\Http\Middleware;

use App\Services\ClinicContext;
use App\Services\Provisioning\EntitlementResolver;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one place a tenant is let in or kept out.
 *
 * There was no such place. `CheckLicenseLimit` existed and was registered in no
 * alias list, and `RequireFeatureEntitlement` is aliased but applied to no
 * route — so a subscription could expire and the clinic kept working exactly as
 * before. Nothing enforced anything.
 *
 * Every decision here comes from {@see EntitlementResolver}, which is the single
 * source of truth for what a tenant may do. This middleware contains no dates,
 * no arithmetic and no notion of grace; it asks one question and acts on the
 * answer, so there is no second copy of the rules to drift out of step.
 *
 * Grace is not a lockout. A customer in their seven days gets full access and a
 * banner, because that is what grace is for — the days after a trial ends are
 * when a decision actually gets made, and locking them out then would sabotage
 * the one thing the grace period exists to achieve.
 */
class EnforceSubscriptionWindow
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected EntitlementResolver $entitlements,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $clinic = $this->clinicContext->clinic();

        if (! $clinic) {
            return $next($request);
        }

        $entitlement = $this->entitlements->forClinic($clinic);

        /*
         * A tenant with no subscription at all is left alone rather than locked
         * out. Every clinic provisioned before subscriptions were written would
         * otherwise lose access the moment this shipped, which is a data
         * problem to fix deliberately, not one to discover through support
         * tickets.
         */
        if (! $entitlement['has_subscription']) {
            return $next($request);
        }

        if (! $entitlement['grants_access']) {
            return $this->lockedOut($request, $entitlement);
        }

        /*
         * Shared with every Inertia page so the clinic can show its own banner.
         * Sent for all live states, not just grace — a trial with four days
         * left is worth saying out loud too.
         */
        Inertia::share('subscriptionWindow', [
            'state' => $entitlement['window']['state'],
            'days_remaining' => $entitlement['window']['days_remaining'],
            'plan' => $entitlement['plan']['name'] ?? null,
        ]);

        return $next($request);
    }

    /**
     * @param  array<string, mixed>  $entitlement
     */
    protected function lockedOut(Request $request, array $entitlement): Response
    {
        $message = 'Your access has ended. Contact your account manager to restore it — your clinic data is retained.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'window' => $entitlement['window']], 402);
        }

        /*
         * A Blade error page rather than an Inertia one. The clinic-side screen
         * for this belongs with the rest of that UI; until it exists, rendering
         * a page component that is not there would turn a clear billing message
         * into a blank screen and a console error.
         */
        return response()->view('errors.subscription-lapsed', ['message' => $message], 402);
    }
}
