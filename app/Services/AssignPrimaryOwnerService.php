<?php

namespace App\Services;

use App\ClinicMembershipStatus;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\User;
use App\UserType;
use InvalidArgumentException;

class AssignPrimaryOwnerService
{
    /**
     * Assign a user as the primary owner of a clinic.
     *
     * @throws InvalidArgumentException
     */
    public function execute(Clinic $clinic, User $user): ClinicMembership
    {
        if ($user->user_type !== UserType::ClinicUser) {
            throw new InvalidArgumentException('Only clinic users can be assigned as clinic primary owners.');
        }

        // Unmark any existing primary owner for this clinic
        ClinicMembership::where('clinic_id', $clinic->id)
            ->where('user_id', '!=', $user->id)
            ->where('is_primary_owner', true)
            ->update(['is_primary_owner' => false]);

        $membership = ClinicMembership::updateOrCreate(
            [
                'clinic_id' => $clinic->id,
                'user_id' => $user->id,
            ],
            [
                'is_primary_owner' => true,
                'has_all_branches_access' => true,
                'status' => ClinicMembershipStatus::Active,
            ]
        );

        return $membership;
    }
}
