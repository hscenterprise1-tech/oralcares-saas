<?php

namespace App\Http\Middleware;

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicStatus;
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

                // Eagerly query accessible branches
                $branchQuery = $resolvedMembership->clinic->branches()
                    ->where('status', BranchStatus::Active);

                if (! $resolvedMembership->has_all_branches_access) {
                    $branchQuery->whereHas('memberships', function ($query) use ($resolvedMembership) {
                        $query->where('clinic_membership_id', $resolvedMembership->id);
                    });
                }

                $accessibleActiveBranches = $branchQuery->get();
                $resolvedBranch = null;

                if ($accessibleActiveBranches->count() === 1) {
                    $resolvedBranch = $accessibleActiveBranches->first();
                    session([ClinicContext::SESSION_BRANCH_ID => $resolvedBranch->id]);
                } elseif ($accessibleActiveBranches->count() > 1) {
                    $sessionBranchId = session(ClinicContext::SESSION_BRANCH_ID);
                    if ($sessionBranchId) {
                        $resolvedBranch = $accessibleActiveBranches->firstWhere('id', $sessionBranchId);
                        if (! $resolvedBranch) {
                            session()->forget(ClinicContext::SESSION_BRANCH_ID);
                        }
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
