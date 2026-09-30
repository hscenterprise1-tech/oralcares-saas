<?php

namespace App\Services;

use App\BranchStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Organization;
use Illuminate\Support\Str;

class BranchProvisioningService
{
    /**
     * Provision a new branch for an organization.
     *
     * A branch belongs to a clinic; `organization_id` is carried alongside for
     * scoping, not as its owner. Passing the clinic is what keeps
     * `branches.clinic_id` populated — provisioning previously omitted it, so
     * every branch created from a won deal was orphaned from the tenant the
     * clinic portal routes by.
     */
    public function provision(Organization $organization, string $name, bool $isMain = false, ?Clinic $clinic = null): Branch
    {
        return Branch::create([
            'organization_id' => $organization->id,
            'clinic_id' => $clinic?->id,
            'name' => $name,
            'slug' => Str::slug($name),
            'is_main' => $isMain,
            'status' => BranchStatus::Active,
        ]);
    }
}
