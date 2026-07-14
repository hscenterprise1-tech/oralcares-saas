<?php

namespace App\Services;

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\User;
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
     * Determine if a clinic is active.
     */
    public function hasClinic(): bool
    {
        return $this->clinic !== null;
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
     * Clear all resolved contexts.
     */
    public function clear(): void
    {
        $this->user = null;
        $this->membership = null;
        $this->clinic = null;
        $this->branch = null;
    }
}
