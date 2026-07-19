<?php

namespace App\Policies;

use App\Models\ClinicMembership;
use App\Models\User;
use App\Services\ClinicContext;

class ClinicMembershipPolicy
{
    public function __construct(protected ClinicContext $clinicContext) {}

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ClinicMembership $membership): bool
    {
        if ($user->isMasterAdmin()) {
            return true;
        }

        return $this->clinicContext->hasClinic() &&
            $membership->clinic_id === $this->clinicContext->clinic()->id;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ClinicMembership $membership): bool
    {
        if ($user->isMasterAdmin()) {
            return true;
        }

        return $this->clinicContext->hasClinic() &&
            $membership->clinic_id === $this->clinicContext->clinic()->id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ClinicMembership $membership): bool
    {
        if ($user->isMasterAdmin()) {
            return true;
        }

        // Primary owner cannot be deleted
        return ! $membership->is_primary_owner &&
            $this->clinicContext->hasClinic() &&
            $membership->clinic_id === $this->clinicContext->clinic()->id;
    }
}
