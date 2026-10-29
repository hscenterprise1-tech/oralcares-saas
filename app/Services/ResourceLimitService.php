<?php

namespace App\Services;

use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\EmploymentStatus;
use App\Events\LimitReached;
use App\Exceptions\ResourceLimitExceededException;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\CompanySubscription;
use App\Models\User;
use App\Scopes\OrganizationScope;
use App\Services\Provisioning\ClinicSubscriptionSelector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ResourceLimitService
{
    public function __construct(protected ClinicSubscriptionSelector $subscriptions) {}

    /**
     * The subscription seat limits are read from: the clinic's most recent
     * active one (`ClinicSubscriptionSelector::forSeats`).
     */
    public function getActiveSubscription(Clinic $clinic): ?CompanySubscription
    {
        return $this->subscriptions->forSeats($clinic);
    }

    /**
     * The logins holding a seat: memberships that can sign in.
     *
     * A seat is held by an active membership whose staff record, if it has
     * one, is not inactive, suspended or resigned — the rule sign-in itself
     * applies (`ClinicLoginController`, `ResolveClinicContext`). An invitation
     * not yet accepted has no membership, so it holds none either.
     */
    public function getCurrentUserCount(Clinic $clinic): int
    {
        return $this->seatHolders($clinic)->count();
    }

    /**
     * The seat-holding logins that take a doctor seat: those with a role
     * `ClinicRole::seat()` maps to the doctor seat, at one of the clinic's
     * branches.
     */
    public function getCurrentDoctorCount(Clinic $clinic): int
    {
        return $this->doctorsAmong($this->seatHolders($clinic), $clinic)->count();
    }

    /**
     * Whether this login has a role that takes a doctor seat here.
     */
    public function holdsDoctorRole(Clinic $clinic, User $user): bool
    {
        return $this->doctorRoleHolders($clinic)->where('model_id', $user->id)->exists();
    }

    /**
     * The gate before a login starts holding a seat — an invitation accepted,
     * a login reactivated, a staff login moved into a doctor role: the plan
     * must have room for one more of that seat (`$asDoctor`) and one more
     * login overall. `$holder` is a login that already holds a seat and is
     * only changing its kind; its own seat is left out of the count.
     *
     * Call it inside the transaction that grants the seat. It locks the
     * clinic's row, so two grants for one clinic take turns, and counts with
     * locking reads, so the second sees the seat the first committed even
     * when its transaction read something earlier.
     *
     * @throws ResourceLimitExceededException
     */
    public function assertSeatAvailable(Clinic $clinic, bool $asDoctor, ?User $holder = null): void
    {
        Clinic::query()->withoutGlobalScopes()->whereKey($clinic->id)->lockForUpdate()->first(['id']);

        $sub = $this->getActiveSubscription($clinic);
        if (! $sub) {
            throw new ResourceLimitExceededException('No active subscription found. Contact your account manager.');
        }

        $total = $this->seatHolders($clinic, $holder?->id)->sharedLock()->count();
        $doctors = $this->doctorsAmong($this->seatHolders($clinic, $holder?->id), $clinic)->sharedLock()->count();

        if ($asDoctor) {
            $limit = $this->getAllowedDoctors($sub);
            if ($doctors >= $limit) {
                event(new LimitReached($clinic, 'doctors', $limit, $doctors));
                throw new ResourceLimitExceededException("Your plan allows up to {$limit} doctors. Contact your account manager to add more seats.");
            }
        } else {
            $limit = $this->getAllowedStaff($sub);
            if ($limit !== null && $total - $doctors >= $limit) {
                event(new LimitReached($clinic, 'staff', $limit, $total - $doctors));
                throw new ResourceLimitExceededException("Your plan allows up to {$limit} staff members. Contact your account manager to add more seats.");
            }
        }

        $limit = $this->getAllowedUsers($sub);
        if ($total >= $limit) {
            event(new LimitReached($clinic, 'users', $limit, $total));
            throw new ResourceLimitExceededException("Your plan allows up to {$limit} users in total. Contact your account manager to add more seats.");
        }
    }

    /**
     * @return Builder<ClinicMembership>
     */
    private function seatHolders(Clinic $clinic, ?int $exceptUserId = null): Builder
    {
        return ClinicMembership::query()->withoutGlobalScopes()
            ->where('clinic_memberships.clinic_id', $clinic->id)
            ->where('clinic_memberships.status', ClinicMembershipStatus::Active)
            ->whereNotExists(fn ($query) => $query->from('clinic_employees')
                ->whereColumn('clinic_employees.clinic_id', 'clinic_memberships.clinic_id')
                ->whereColumn('clinic_employees.user_id', 'clinic_memberships.user_id')
                ->whereIn('clinic_employees.status', EmploymentStatus::accessEnding()))
            ->when($exceptUserId !== null, fn ($query) => $query->where('clinic_memberships.user_id', '!=', $exceptUserId));
    }

    /**
     * @param  Builder<ClinicMembership>  $memberships
     * @return Builder<ClinicMembership>
     */
    private function doctorsAmong(Builder $memberships, Clinic $clinic): Builder
    {
        return $memberships->whereIn('clinic_memberships.user_id', $this->doctorRoleHolders($clinic)->select('model_id'));
    }

    /** Role assignments, at this clinic's branches, to a role that takes a doctor seat. */
    private function doctorRoleHolders(Clinic $clinic): QueryBuilder
    {
        return DB::table('spatie_model_has_roles')
            ->where('model_type', User::class)
            ->whereIn('branch_id', Branch::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->select('id'))
            ->whereIn('role_id', DB::table('spatie_roles')->whereIn('name', ClinicRole::doctorSeatRoles())->select('id'));
    }

    /**
     * Get current branch count.
     */
    /**
     * Every branch the clinic has, whoever is asking. A branch belongs to its
     * clinic; the organisation scope must not hide one from the count the
     * plan's branch limit is enforced against.
     */
    public function getCurrentBranchCount(Clinic $clinic): int
    {
        return Branch::withoutGlobalScope(OrganizationScope::class)->where('clinic_id', $clinic->id)->count();
    }

    /**
     * Get current non-doctor staff count.
     *
     * `max_staff` is a sub-cap under `max_users`, not a second independent
     * population — every membership is either a doctor or staff, so this is
     * the complement of {@see getCurrentDoctorCount()} rather than its own
     * role-matching query.
     */
    public function getCurrentStaffCount(Clinic $clinic): int
    {
        return $this->getCurrentUserCount($clinic) - $this->getCurrentDoctorCount($clinic);
    }

    /**
     * Get current storage usage in GB.
     */
    public function getCurrentStorageGb(Clinic $clinic): float
    {
        return (float) Cache::get("clinic_storage_usage_{$clinic->id}", 0.0);
    }

    /**
     * Calculate allowed users including addons.
     */
    public function getAllowedUsers(CompanySubscription $sub): int
    {
        $extra = $sub->addons()->where('addon_type', 'additional_users')->where('is_active', true)->sum('quantity');

        return $sub->max_users + $extra;
    }

    /**
     * Calculate allowed doctors including addons.
     */
    public function getAllowedDoctors(CompanySubscription $sub): int
    {
        $extra = $sub->addons()->where('addon_type', 'additional_doctors')->where('is_active', true)->sum('quantity');

        return $sub->max_doctors + $extra;
    }

    /**
     * Calculate allowed branches including addons.
     */
    public function getAllowedBranches(CompanySubscription $sub): int
    {
        $extra = $sub->addons()->where('addon_type', 'extra_branch')->where('is_active', true)->sum('quantity');

        return $sub->max_branches + $extra;
    }

    /**
     * Calculate allowed staff including addons.
     *
     * Null means the plan hasn't set a distinct staff sub-cap — the seat
     * ceiling (`max_users`) still applies, this sub-cap just doesn't.
     */
    public function getAllowedStaff(CompanySubscription $sub): ?int
    {
        if ($sub->max_staff === null) {
            return null;
        }

        $extra = $sub->addons()->where('addon_type', 'additional_staff')->where('is_active', true)->sum('quantity');

        return $sub->max_staff + $extra;
    }

    /**
     * Calculate allowed storage in GB including addons.
     */
    public function getAllowedStorageGb(CompanySubscription $sub): float
    {
        $extra = $sub->addons()->where('addon_type', 'extra_storage')->where('is_active', true)->sum('quantity') * 10; // 10 GB per addon quantity

        return (float) ($sub->storage_limit_gb + $extra);
    }

    /**
     * Check if clinic can create a new user.
     */
    public function canCreateUser(Clinic $clinic): bool
    {
        $sub = $this->getActiveSubscription($clinic);
        if (! $sub) {
            return false;
        }

        return $this->getCurrentUserCount($clinic) < $this->getAllowedUsers($sub);
    }

    /**
     * Enforce user limit, throwing an exception if exceeded.
     *
     * @throws ResourceLimitExceededException
     */
    public function enforceUserLimit(Clinic $clinic): void
    {
        $sub = $this->getActiveSubscription($clinic);
        if (! $sub) {
            throw new ResourceLimitExceededException('No active subscription found. Contact your account manager.');
        }

        $limit = $this->getAllowedUsers($sub);
        $usage = $this->getCurrentUserCount($clinic);

        if ($usage >= $limit) {
            event(new LimitReached($clinic, 'users', $limit, $usage));
            throw new ResourceLimitExceededException("Your plan allows up to {$limit} users in total. Contact your account manager to add more seats.");
        }
    }

    /**
     * Check if clinic can create a new doctor.
     */
    public function canCreateDoctor(Clinic $clinic): bool
    {
        $sub = $this->getActiveSubscription($clinic);
        if (! $sub) {
            return false;
        }

        return $this->getCurrentDoctorCount($clinic) < $this->getAllowedDoctors($sub);
    }

    /**
     * Enforce doctor limit, throwing an exception if exceeded.
     *
     * @throws ResourceLimitExceededException
     */
    public function enforceDoctorLimit(Clinic $clinic): void
    {
        $sub = $this->getActiveSubscription($clinic);
        if (! $sub) {
            throw new ResourceLimitExceededException('No active subscription found. Contact your account manager.');
        }

        $limit = $this->getAllowedDoctors($sub);
        $usage = $this->getCurrentDoctorCount($clinic);

        if ($usage >= $limit) {
            event(new LimitReached($clinic, 'doctors', $limit, $usage));
            throw new ResourceLimitExceededException("Your plan allows up to {$limit} doctors. Contact your account manager to add more seats.");
        }
    }

    /**
     * Check if clinic can create a new staff member (non-doctor).
     *
     * A plan with no `max_staff` set has no sub-cap — only the overall
     * `max_users` ceiling applies, so this passes unconditionally.
     */
    public function canCreateStaff(Clinic $clinic): bool
    {
        $sub = $this->getActiveSubscription($clinic);
        if (! $sub) {
            return false;
        }

        $limit = $this->getAllowedStaff($sub);
        if ($limit === null) {
            return true;
        }

        return $this->getCurrentStaffCount($clinic) < $limit;
    }

    /**
     * Enforce the staff sub-cap, throwing an exception if exceeded.
     *
     * No-op when the plan hasn't set `max_staff` — see {@see canCreateStaff()}.
     *
     * @throws ResourceLimitExceededException
     */
    public function enforceStaffLimit(Clinic $clinic): void
    {
        $sub = $this->getActiveSubscription($clinic);
        if (! $sub) {
            throw new ResourceLimitExceededException('No active subscription found. Contact your account manager.');
        }

        $limit = $this->getAllowedStaff($sub);
        if ($limit === null) {
            return;
        }

        $usage = $this->getCurrentStaffCount($clinic);

        if ($usage >= $limit) {
            event(new LimitReached($clinic, 'staff', $limit, $usage));
            throw new ResourceLimitExceededException("Your plan allows up to {$limit} staff members. Contact your account manager to add more seats.");
        }
    }

    /**
     * Check if clinic can create a new branch.
     */
    public function canCreateBranch(Clinic $clinic): bool
    {
        $sub = $this->getActiveSubscription($clinic);
        if (! $sub) {
            return false;
        }

        return $this->getCurrentBranchCount($clinic) < $this->getAllowedBranches($sub);
    }

    /**
     * Enforce branch limit, throwing an exception if exceeded.
     *
     * @throws ResourceLimitExceededException
     */
    public function enforceBranchLimit(Clinic $clinic): void
    {
        $sub = $this->getActiveSubscription($clinic);
        if (! $sub) {
            throw new ResourceLimitExceededException('No active subscription found. Contact your account manager.');
        }

        $limit = $this->getAllowedBranches($sub);
        $usage = $this->getCurrentBranchCount($clinic);

        if ($usage >= $limit) {
            event(new LimitReached($clinic, 'branches', $limit, $usage));
            throw new ResourceLimitExceededException("Your plan allows up to {$limit} branches. Contact your account manager to add more.");
        }
    }

    /**
     * Check if clinic can upload a file of given size.
     */
    public function canUploadFile(Clinic $clinic, float $fileSizeGb): bool
    {
        $sub = $this->getActiveSubscription($clinic);
        if (! $sub) {
            return false;
        }

        $allowed = $this->getAllowedStorageGb($sub);
        $usage = $this->getCurrentStorageGb($clinic);

        return ($usage + $fileSizeGb) <= $allowed;
    }

    /**
     * Enforce storage limit.
     *
     * @throws ResourceLimitExceededException
     */
    public function enforceStorageLimit(Clinic $clinic, float $fileSizeGb): void
    {
        $sub = $this->getActiveSubscription($clinic);
        if (! $sub) {
            throw new ResourceLimitExceededException('No active subscription found.');
        }

        $allowed = $this->getAllowedStorageGb($sub);
        $usage = $this->getCurrentStorageGb($clinic);

        if (($usage + $fileSizeGb) > $allowed) {
            event(new LimitReached($clinic, 'storage', $allowed, (int) $usage));
            throw new ResourceLimitExceededException('Storage limit of '.$allowed.' GB exceeded.');
        }
    }
}
