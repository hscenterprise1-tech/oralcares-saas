<?php

namespace App\Services;

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\User;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;

class ClinicContext
{
    public const SESSION_CLINIC_MEMBERSHIP_ID = 'active_clinic_membership_id';

    public const SESSION_BRANCH_ID = 'active_branch_id';

    private ?User $user = null;

    private ?ClinicMembership $membership = null;

    private ?Clinic $clinic = null;

    private ?Branch $branch = null;

    /** @var ClinicEmployee|false|null false once resolution has been tried and found nothing, to avoid re-querying */
    private ClinicEmployee|false|null $employee = null;

    /** True while `runForClinic()` is running work for a clinic with no user or session behind it. */
    private bool $systemScoped = false;

    /** True while `withoutTenancy()` is running deliberately cross-clinic work. */
    private bool $tenancyBypassed = false;

    /**
     * Run `$callback` with `$clinic` (and optionally one of its branches) as
     * the tenant, for work that has no signed-in user: queued jobs, scheduled
     * tasks, artisan commands.
     *
     * The tenant comes only from the arguments, never from a session, the
     * authenticated user or leftover state. Clinic-scoped queries inside the
     * callback are filtered to this clinic even in a console process (see
     * `TenantScope`). The previous context is restored afterwards, so one job
     * or loop iteration cannot leak its clinic into the next.
     */
    public function runForClinic(Clinic $clinic, ?Branch $branch, callable $callback): mixed
    {
        if ($branch && $branch->clinic_id !== $clinic->id) {
            throw new InvalidArgumentException('Branch does not belong to the clinic being run for.');
        }

        $previous = [$this->user, $this->membership, $this->clinic, $this->branch, $this->employee, $this->systemScoped, $this->tenancyBypassed];

        $this->user = null;
        $this->membership = null;
        $this->clinic = $clinic;
        $this->branch = $branch;
        $this->employee = null;
        $this->systemScoped = true;
        $this->tenancyBypassed = false;

        try {
            return $callback();
        } finally {
            [$this->user, $this->membership, $this->clinic, $this->branch, $this->employee, $this->systemScoped, $this->tenancyBypassed] = $previous;
        }
    }

    /**
     * Run deliberately cross-clinic work, such as platform-wide telemetry or
     * maintenance, inside a queued job without tripping the missing-tenant
     * guard. It must be written out at the call site, so every place that
     * reads across clinics is visible in review.
     */
    public function withoutTenancy(callable $callback): mixed
    {
        $previous = $this->tenancyBypassed;
        $this->tenancyBypassed = true;

        try {
            return $callback();
        } finally {
            $this->tenancyBypassed = $previous;
        }
    }

    public function isSystemScoped(): bool
    {
        return $this->systemScoped;
    }

    public function tenancyBypassed(): bool
    {
        return $this->tenancyBypassed;
    }

    /**
     * Get the active user.
     */
    public function user(): ?User
    {
        return $this->user ?: auth()->user();
    }

    /**
     * Set the current user.
     */
    public function setUser(?User $user): void
    {
        $this->user = $user;
    }

    /**
     * Get the active clinic membership.
     */
    public function membership(): ?ClinicMembership
    {
        return $this->membership;
    }

    /**
     * Get the active clinic.
     */
    public function clinic(): ?Clinic
    {
        return $this->clinic;
    }

    /**
     * Get the active branch.
     */
    public function branch(): ?Branch
    {
        return $this->branch;
    }

    /**
     * The active user's staff identity within the active clinic, if they
     * have one — there's no `User`-`ClinicEmployee` relation to lean on, so
     * this resolves it by `user_id` + `clinic_id` and caches the result for
     * the rest of the request (`false` once resolution has been tried and
     * found nothing, distinct from never having been asked).
     */
    public function currentEmployee(): ?ClinicEmployee
    {
        if ($this->employee !== null) {
            return $this->employee ?: null;
        }

        $user = $this->user();
        $clinic = $this->clinic();

        if (! $user || ! $clinic) {
            $this->employee = false;

            return null;
        }

        $this->employee = ClinicEmployee::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->where('user_id', $user->id)
            ->with('practitionerProfile')
            ->first() ?: false;

        return $this->employee ?: null;
    }

    /**
     * Determine if a clinic is active.
     */
    public function hasClinic(): bool
    {
        return $this->clinic !== null;
    }

    /**
     * The branch a branch-scoped screen works in: the active branch, else
     * the first active branch this membership may open (main branch first).
     * Null when the membership may open no branch at all.
     */
    public function workingBranch(): ?Branch
    {
        if ($this->branch) {
            return $this->branch;
        }

        $membership = $this->membership;

        if (! $membership || ! $this->clinic) {
            return null;
        }

        return Branch::withoutGlobalScopes()
            ->where('clinic_id', $this->clinic->id)
            ->where('status', BranchStatus::Active)
            ->when(! $membership->has_all_branches_access, fn ($query) => $query->whereIn(
                'id',
                fn ($access) => $access->select('branch_id')->from('branch_clinic_membership')->where('clinic_membership_id', $membership->id),
            ))
            ->orderByDesc('is_main')
            ->orderBy('id')
            ->first();
    }

    /**
     * The working branch, or a 403 for a member with no branch access. Use it
     * wherever records are read or written for one branch.
     */
    public function requireBranch(): Branch
    {
        return $this->workingBranch() ?? abort(403, 'You do not have access to any branch of this clinic.');
    }

    /**
     * For lists that may show the whole clinic: the working branch, or null
     * only when this member may see every branch (owner or all-branches
     * access). A member without branch access gets a 403, never the whole
     * clinic's records.
     */
    public function branchScopeOrAbort(): ?Branch
    {
        $membership = $this->membership;

        if ($membership && ($membership->has_all_branches_access || $membership->is_primary_owner)) {
            return $this->branch;
        }

        return $this->requireBranch();
    }

    /**
     * Determine if a branch is active.
     */
    public function hasBranch(): bool
    {
        return $this->branch !== null;
    }

    /**
     * Set the active clinic membership.
     *
     * @throws InvalidArgumentException
     */
    public function setMembership(ClinicMembership $membership): void
    {
        $currentUser = $this->user();

        if (! $currentUser || $membership->user_id !== $currentUser->id) {
            throw new InvalidArgumentException('Membership does not belong to the current authenticated user.');
        }

        if ($membership->status !== ClinicMembershipStatus::Active) {
            throw new InvalidArgumentException('Cannot select an inactive or suspended clinic membership.');
        }

        $this->membership = $membership;
        $this->clinic = $membership->clinic;

        // Clear active branch if it becomes inaccessible under the new membership
        if ($this->branch && ! $membership->hasAccessToBranch($this->branch)) {
            $this->setBranch(null);
        }
    }

    /**
     * Set the active branch.
     *
     * @throws LogicException|InvalidArgumentException
     */
    public function setBranch(?Branch $branch): void
    {
        if ($branch === null) {
            $this->branch = null;

            return;
        }

        if (! $this->membership) {
            throw new LogicException('Cannot set active branch without an active clinic membership.');
        }

        if ($branch->clinic_id !== $this->clinic->id) {
            throw new InvalidArgumentException('Branch does not belong to the active clinic.');
        }

        if ($branch->status !== BranchStatus::Active) {
            throw new InvalidArgumentException('Cannot select an inactive or suspended branch.');
        }

        if (! $this->membership->hasAccessToBranch($branch)) {
            throw new InvalidArgumentException('Branch is not accessible to the active membership.');
        }

        $this->branch = $branch;
    }

    /**
     * Programmatically activate a membership (updates session and local state).
     */
    public function activateMembership(ClinicMembership $membership): void
    {
        $this->setMembership($membership);
        session([self::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id]);

        // If the branch was cleared due to inconsistency, clear it from session
        if ($this->branch === null) {
            session()->forget(self::SESSION_BRANCH_ID);
        }
    }

    /**
     * Programmatically activate a branch (updates session and local state).
     */
    public function activateBranch(Branch $branch): void
    {
        $this->setBranch($branch);
        session([self::SESSION_BRANCH_ID => $branch->id]);
    }

    /**
     * The clinic slug the current request is addressed to, if resolvable.
     *
     * Domain mode reads it off the subdomain (`{clinic}.oralcares.test`) —
     * always present for a real request, so a clinic user's session can
     * never resolve into a *different* clinic than the one in the URL (see
     * `ResolveClinicContext`, which scopes membership resolution to it, and
     * `ClinicPortalMiddleware`, which 404s when it's absent).
     *
     * Path mode (local dev without wildcard DNS) has no subdomain to bind
     * to, so it only returns a slug when the request explicitly names one
     * via a `clinic` input; otherwise the caller falls back to whichever
     * clinic the session already resolved.
     */
    public function requestedClinicSlug(Request $request): ?string
    {
        if (config('app.routing_mode') === 'path') {
            return $request->input('clinic');
        }

        $host = $request->getHost();
        $appHost = parse_url(config('app.url'), PHP_URL_HOST);

        if (! $appHost) {
            return null;
        }

        if ($host === $appHost || ! str_ends_with($host, '.'.$appHost)) {
            return null;
        }

        $subdomain = substr($host, 0, -strlen('.'.$appHost));

        return $subdomain === 'admin' ? null : $subdomain;
    }

    /**
     * Clear all resolved contexts.
     */
    public function clear(): void
    {
        $this->user = null;
        $this->membership = null;
        $this->clinic = null;
        $this->branch = null;
        $this->employee = null;
    }
}
