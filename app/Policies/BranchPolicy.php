<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;
use App\Services\ClinicContext;

class BranchPolicy
{
    public function __construct(protected ClinicContext $clinicContext) {}

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Branch $branch): bool
    {
        if ($user->isMasterAdmin()) {
            return true;
        }

        // Must match active clinic context and user must have branch access
        return $this->clinicContext->hasClinic() &&
            $branch->clinic_id === $this->clinicContext->clinic()->id &&
            $this->clinicContext->membership()->hasAccessToBranch($branch);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Branch $branch): bool
    {
        if ($user->isMasterAdmin()) {
            return true;
        }

        return $this->clinicContext->hasClinic() &&
            $branch->clinic_id === $this->clinicContext->clinic()->id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Branch $branch): bool
    {
        if ($user->isMasterAdmin()) {
            return true;
        }

        return $this->clinicContext->hasClinic() &&
            $branch->clinic_id === $this->clinicContext->clinic()->id;
    }
}
