<?php

namespace App\Data;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;

class ClinicOnboardingResult
{
    public function __construct(
        public readonly Clinic $clinic,
        public readonly Branch $primaryBranch,
        public readonly ClinicMembership $ownerMembership
    ) {}
}
