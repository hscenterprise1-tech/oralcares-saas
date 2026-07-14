<?php

namespace App\Services;

use App\BranchStatus;
use App\ClinicStatus;
use App\Data\ClinicOnboardingData;
use App\Data\ClinicOnboardingResult;
use App\Models\Clinic;
use App\Models\Role;
use App\UserType;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class OnboardClinicService
{
    /**
     * Create a new service instance.
     */
    public function __construct(
        protected AssignPrimaryOwnerService $assignPrimaryOwnerService,
        protected CloneSystemRolesToClinicService $cloneSystemRolesToClinicService
    ) {}

    /**
     * Onboard a new clinic with a primary branch, primary owner, and cloned default roles.
     *
     * @throws InvalidArgumentException|RuntimeException
     */
    public function execute(ClinicOnboardingData $data): ClinicOnboardingResult
    {
        // 1. Owner eligibility check
        if ($data->owner->user_type !== UserType::ClinicUser) {
            throw new InvalidArgumentException('Only clinic users can be assigned as clinic primary owners.');
        }

        // 2. System role template dependency check
        $systemTemplateCount = Role::whereNull('clinic_id')
            ->where('is_system', true)
            ->where('is_active', true)
            ->count();

        if ($systemTemplateCount === 0) {
            throw new RuntimeException('Cannot onboard clinic: no active system role templates are available.');
        }

        // 3. Early unique check for clinic slug (idempotency strategy boundary)
        if (Clinic::where('slug', $data->clinicSlug)->exists()) {
            throw new InvalidArgumentException("A clinic with slug '{$data->clinicSlug}' already exists.");
        }

        // 4. Atomic transaction orchestration
        return DB::transaction(function () use ($data) {
            $clinic = Clinic::create([
                'name' => $data->clinicName,
                'slug' => $data->clinicSlug,
                'status' => ClinicStatus::Active,
            ]);

            $primaryBranch = $clinic->branches()->create([
                'name' => $data->primaryBranchName,
                'slug' => 'main',
                'is_main' => true,
                'status' => BranchStatus::Active,
            ]);

            $ownerMembership = $this->assignPrimaryOwnerService->execute($clinic, $data->owner);

            $this->cloneSystemRolesToClinicService->execute($clinic);

            $clonedRoles = $clinic->roles()->get();

            return new ClinicOnboardingResult(
                $clinic,
                $primaryBranch,
                $ownerMembership,
                $clonedRoles
            );
        });
    }
}
