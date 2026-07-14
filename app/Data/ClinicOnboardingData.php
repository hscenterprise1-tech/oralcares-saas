<?php

namespace App\Data;

use App\Models\User;

class ClinicOnboardingData
{
    public function __construct(
        public readonly string $clinicName,
        public readonly string $clinicSlug,
        public readonly User $owner,
        public readonly string $primaryBranchName
    ) {}
}
