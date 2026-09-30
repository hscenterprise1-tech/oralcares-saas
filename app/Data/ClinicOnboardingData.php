<?php

namespace App\Data;

use App\Models\Organization;
use App\Models\User;

/**
 * What it takes to stand up a clinic.
 *
 * `organization` is required, and that is the point. This service used to
 * create a clinic with no account behind it — three of the four clinics in the
 * database have a null `organization_id` and no branches or memberships for
 * exactly that reason, while the one provisioned through the customer engine
 * carries its account correctly. A clinic with no organisation belongs to
 * nobody: it cannot be billed, scoped, or found from the account it serves.
 */
class ClinicOnboardingData
{
    public function __construct(
        public readonly string $clinicName,
        public readonly string $clinicSlug,
        public readonly User $owner,
        public readonly string $primaryBranchName,
        public readonly Organization $organization
    ) {}
}
