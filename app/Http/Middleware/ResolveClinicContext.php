<?php

namespace App\Http\Middleware;

use App\ClinicMembershipStatus;
use App\ClinicStatus;
use App\EmploymentStatus;
use App\Scopes\TenantScope;
use App\Services\ClinicContext;
use App\UserType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveClinicContext
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(protected ClinicContext $clinicContext) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Resolve from scratch on every request: a context left over from an
        // earlier request on a long-lived worker must never stand in for
        // this one (for example after the membership stopped qualifying).
        $this->clinicContext->clear();

        if ($user && $user->user_type === UserType::ClinicUser) {
            $requestedSlug = $this->clinicContext->requestedClinicSlug($request);
            $isDomainMode = config('app.routing_mode') === 'domain';

            $activeMemberships = $user->clinicMemberships()
                ->withoutGlobalScope(TenantScope::class)
                ->where('status', ClinicMembershipStatus::Active)
                // A membership in an inactive or suspended clinic opens
                // nothing, including in a session that was signed in before
                // the clinic was suspended.
                ->whereHas('clinic', fn ($query) => $query->where('status', ClinicStatus::Active))
                // A login whose staff record in that clinic has been made
                // inactive, suspended or resigned opens nothing there either,
                // even if nobody remembered to suspend the membership too.
                // (Owners and staff with no staff record are unaffected.)
                ->whereNotExists(fn ($query) => $query->from('clinic_employees')
                    ->whereColumn('clinic_employees.clinic_id', 'clinic_memberships.clinic_id')
                    ->whereColumn('clinic_employees.user_id', 'clinic_memberships.user_id')
                    ->whereIn('clinic_employees.status', EmploymentStatus::accessEnding()))
                ->when(
                    $requestedSlug !== null,
                    // A clinic was explicitly named (always true in domain
                    // mode; opt-in in path mode) — never resolve into a
                    // *different* clinic than the one addressed.
                    fn ($query) => $query->whereHas('clinic', fn ($q) => $q->where('slug', $requestedSlug)),
                    // No slug to bind to. In domain mode that means an
                    // unrecognised host — fail closed. In path mode it means
                    // local dev without a `clinic` param — fall back to
                    // whichever active membership the session already holds.
                    fn ($query) => $isDomainMode ? $query->whereRaw('1 = 0') : $query
                )
                ->with(['clinic', 'user'])
                ->get();

            $resolvedMembership = null;
            $sessionMembershipId = session(ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID);

            if ($sessionMembershipId) {
                $resolvedMembership = $activeMemberships->firstWhere('id', $sessionMembershipId);
                if (! $resolvedMembership) {
                    // stale/invalid/tampered session ID: clear it
                    session()->forget(ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID);
                    session()->forget(ClinicContext::SESSION_BRANCH_ID);
                }
            }

            if (! $resolvedMembership) {
                if ($activeMemberships->count() === 1) {
                    $resolvedMembership = $activeMemberships->first();
                    session([ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $resolvedMembership->id]);
                }
            }

            if ($resolvedMembership) {
                // Initialize user context
                $this->clinicContext->setUser($user);
                $this->clinicContext->setMembership($resolvedMembership);

                // The session's branch, if it is still one this membership may
                // open; otherwise the only branch, when there is exactly one.
                // Bounded lookups: a clinic with hundreds of branches is never
                // loaded whole on every request.
                $branchQuery = $resolvedMembership->accessibleActiveBranches();
                $resolvedBranch = null;

                $sessionBranchId = session(ClinicContext::SESSION_BRANCH_ID);
                if ($sessionBranchId) {
                    $resolvedBranch = (clone $branchQuery)->whereKey($sessionBranchId)->first();
                    if (! $resolvedBranch) {
                        session()->forget(ClinicContext::SESSION_BRANCH_ID);
                    }
                }

                if (! $resolvedBranch) {
                    $candidates = (clone $branchQuery)->limit(2)->get();
                    if ($candidates->count() === 1) {
                        $resolvedBranch = $candidates->first();
                        session([ClinicContext::SESSION_BRANCH_ID => $resolvedBranch->id]);
                    }
                }

                if ($resolvedBranch) {
                    $this->clinicContext->setBranch($resolvedBranch);
                }
            }
        }

        return $next($request);
    }
}
