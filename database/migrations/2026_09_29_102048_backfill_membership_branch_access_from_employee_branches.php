<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Branch access (`branch_clinic_membership`) is what decides which branches
 * a login can open. Until now it was written only when an invitation was
 * accepted, so a member whose branches were later changed on their staff
 * record could end up with no access rows at all, which means no branches.
 *
 * This fills access for exactly those members (not all-branches, no rows)
 * from the one piece of real evidence: the branches on their own staff
 * record in the same clinic. Rows pointing at a staff record or branch that
 * no longer exists, or at another clinic's branch, are ignored. Members with
 * no such evidence are left without access for a clinic admin to set.
 */
return new class extends Migration
{
    public function up(): void
    {
        $members = DB::table('clinic_memberships')
            ->where('has_all_branches_access', false)
            ->whereNotExists(fn ($query) => $query->from('branch_clinic_membership')
                ->whereColumn('branch_clinic_membership.clinic_membership_id', 'clinic_memberships.id'))
            ->get(['id', 'clinic_id', 'user_id']);

        foreach ($members as $member) {
            $branchIds = DB::table('clinic_employee_branches')
                ->join('clinic_employees', 'clinic_employees.id', '=', 'clinic_employee_branches.clinic_employee_id')
                ->join('branches', 'branches.id', '=', 'clinic_employee_branches.branch_id')
                ->where('clinic_employees.clinic_id', $member->clinic_id)
                ->where('clinic_employees.user_id', $member->user_id)
                ->where('branches.clinic_id', $member->clinic_id)
                ->distinct()
                ->pluck('branches.id');

            foreach ($branchIds as $branchId) {
                DB::table('branch_clinic_membership')->insertOrIgnore([
                    'clinic_membership_id' => $member->id,
                    'branch_id' => $branchId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Access granted here is indistinguishable from access granted by an
        // admin afterwards, so it is not removed on rollback.
    }
};
