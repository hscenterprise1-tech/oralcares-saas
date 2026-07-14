<?php

namespace App\Data;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;

class ClinicOnboardingResult
{
    /**
     * @param  Collection<int, Role>  $clonedRoles
     */
    public function __construct(
        public readonly Clinic $clinic,
        public readonly Branch $primaryBranch,
        public readonly ClinicMembership $ownerMembership,
        public readonly Collection $clonedRoles
    ) {}
}
