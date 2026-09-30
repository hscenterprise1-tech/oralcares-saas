<?php

namespace App\Services;

use App\Events\LimitReached;
use App\Exceptions\ResourceLimitExceededException;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\CompanySubscription;
use App\Models\User;
use App\SubscriptionStatus;
use Illuminate\Support\Facades\Cache;

class ResourceLimitService
{
    /**
     * Get the active subscription for a clinic.
     */
    public function getActiveSubscription(Clinic $clinic): ?CompanySubscription
    {
        return $clinic->subscriptions()
            ->where('status', SubscriptionStatus::Active)
            ->first();
    }

    /**
     * Get current user membership count.
     */
    public function getCurrentUserCount(Clinic $clinic): int
    {
        return ClinicMembership::where('clinic_id', $clinic->id)->count();
    }

    /**
     * Get current doctor count.
     */
    public function getCurrentDoctorCount(Clinic $clinic): int
    {
        return ClinicMembership::where('clinic_id', $clinic->id)
            ->whereHas('user', function ($query) use ($clinic) {
                $query->whereIn('id', function ($sub) use ($clinic) {
                    $sub->select('model_id')
                        ->from('spatie_model_has_roles')
                        ->where('model_type', User::class)
                        ->whereIn('branch_id', $clinic->branches()->pluck('id'))
                        ->whereIn('role_id', function ($roleSub) {
                            $roleSub->select('id')->from('spatie_roles')->where('name', 'Doctor');
                        });
                });
            })
            ->count();
    }

    /**
     * Get current branch count.
     */
    public function getCurrentBranchCount(Clinic $clinic): int
    {
        return Branch::where('clinic_id', $clinic->id)->count();
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
