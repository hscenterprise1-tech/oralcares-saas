<?php

namespace App\Services\Staff;

use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\Practitioner;
use App\Models\User;
use App\Services\Security\SecurityAudit;
use Illuminate\Support\Facades\DB;

/**
 * Adds a staff record to a clinic. The one place a ClinicEmployee is
 * created, shared by the staff form and the onboarding invitations step.
 *
 * Callers validate first; branch ids must already be checked against the
 * clinic and the acting member's reach.
 */
class StaffEnrolment
{
    public function __construct(protected SecurityAudit $audit) {}

    /**
     * @param  array{name: string, email?: ?string, phone?: ?string, status: string, staff_type?: ?string, date_of_birth?: ?string, gender?: ?string, joining_date?: ?string, notes?: ?string}  $details
     * @param  list<int>  $branchIds
     * @param  list<int>  $roleIds  roles to grant when the invitation is accepted
     */
    public function enrol(Clinic $clinic, array $details, array $branchIds, ?int $primaryBranchId, array $roleIds, ?User $actor): ClinicEmployee
    {
        $employee = DB::transaction(function () use ($clinic, $details, $branchIds, $primaryBranchId, $roleIds) {
            $employee = ClinicEmployee::create([
                'clinic_id' => $clinic->id,
                'name' => $details['name'],
                'email' => $details['email'] ?? null,
                'phone' => $details['phone'] ?? null,
                'status' => $details['status'],
                'status_changed_at' => now(),
            ] + array_intersect_key($details, array_flip(['staff_type', 'date_of_birth', 'gender', 'joining_date', 'notes'])));

            if ($branchIds !== []) {
                $employee->branches()->sync($branchIds);
            }

            if ($primaryBranchId) {
                if (! in_array($primaryBranchId, $branchIds, true)) {
                    $employee->branches()->attach($primaryBranchId);
                }
                $employee->update(['primary_branch_id' => $primaryBranchId]);
            } elseif (count($branchIds) === 1) {
                $employee->update(['primary_branch_id' => $branchIds[0]]);
            }

            if (StaffDirectory::isClinical($employee->staff_type)) {
                Practitioner::create(['clinic_employee_id' => $employee->id, 'profession' => StaffDirectory::typeLabel($employee->staff_type), 'is_active' => true]);
            }

            if ($roleIds !== []) {
                $employee->roles()->sync($roleIds);
            }

            return $employee;
        });

        $this->audit->record('staff.created', $employee, ['staff_type' => $employee->staff_type], ['clinic_id' => $clinic->id, 'actor_user_id' => $actor?->id]);

        return $employee;
    }
}
