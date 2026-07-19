<?php

namespace App\Services;

use App\EmploymentStatus;
use App\Models\Clinic;
use App\Models\ClinicEmployee;

class ClinicOrganizationService
{
    /**
     * Create a clinic employee profile.
     */
    public function createEmployee(Clinic $clinic, array $data): ClinicEmployee
    {
        return ClinicEmployee::create(array_merge([
            'clinic_id' => $clinic->id,
            'status' => EmploymentStatus::PendingInvitation,
        ], $data));
    }

    /**
     * Assign branches to a clinic employee.
     */
    public function assignBranches(ClinicEmployee $employee, array $branchIds): void
    {
        $employee->branches()->sync($branchIds);
    }

    /**
     * Assign departments to a clinic employee.
     */
    public function assignDepartments(ClinicEmployee $employee, array $departmentIds): void
    {
        $employee->departments()->sync($departmentIds);
    }

    /**
     * Assign rooms to a clinic employee.
     */
    public function assignRooms(ClinicEmployee $employee, array $roomIds): void
    {
        $employee->rooms()->sync($roomIds);
    }

    /**
     * Establish hierarchy by setting who the employee reports to.
     */
    public function setReportTo(ClinicEmployee $employee, ClinicEmployee $manager): void
    {
        $employee->update(['reports_to_id' => $manager->id]);
    }
}
