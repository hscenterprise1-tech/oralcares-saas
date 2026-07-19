<?php

namespace App\Services;

use App\Events\WorkspaceInitialized;
use App\Models\Clinic;
use Illuminate\Support\Facades\DB;

class ClinicWorkspaceInitializationService
{
    public function __construct(
        protected ClinicSettingsService $clinicSettingsService,
        protected BranchSettingsService $branchSettingsService,
        protected DepartmentInitializationService $departmentService,
        protected RoomInitializationService $roomService
    ) {}

    /**
     * Centralized workspace initialization for a clinic.
     */
    public function initializeWorkspace(Clinic $clinic): void
    {
        DB::transaction(function () use ($clinic) {
            // 1. Clinic settings
            $this->clinicSettingsService->initializeDefaultSettings($clinic);

            // 2. Clinic departments
            $this->departmentService->initializeDefaultDepartments($clinic);

            // 3. Branch settings and rooms
            foreach ($clinic->branches as $branch) {
                $this->branchSettingsService->initializeDefaultSettings($branch);
                $this->roomService->initializeDefaultRooms($branch);
            }

            event(new WorkspaceInitialized($clinic));
        });
    }
}
