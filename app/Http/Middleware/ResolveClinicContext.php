<?php

namespace App\Http\Middleware;

use App\BranchStatus;
use App\ClinicMembershipStatus;
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
            $activeMemberships = $user->clinicMemberships()
                ->withoutGlobalScope(TenantScope::class)
                ->where('status', ClinicMembershipStatus::Active)
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
