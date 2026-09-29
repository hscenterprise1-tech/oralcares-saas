<?php

namespace App\Services\Staff;

use App\ClinicMembershipStatus;
use App\EmploymentStatus;
use App\Models\Branch;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Links staff records to the logins they belong to, only on unambiguous
 * same-clinic evidence, and reports the state of every clinic's staff
 * identity.
 *
 * A staff record is linked to a login only when all of these hold:
 * - a login exists with exactly the staff record's email,
 * - that login has an ACTIVE membership in the SAME clinic,
 * - that login is not already the login of another staff record there,
 * - the staff record is not inactive, suspended or resigned.
 * Everything else is left untouched and reported with the reason.
 *
 * Linking never grants roles. Branch access is filled from the staff
 * record's own same-clinic branches only when the membership has none.
 */
class StaffIdentityLinker
{
    /**
     * @return array{
     *     clinic_id: int, employees: int, linked: int, unlinked: int, practitioners: int,
     *     membership_branch_rows: int, members_without_branch_access: int,
     *     invalid_staff_branch_rows: int, unmigrated_availability_rows: int,
     *     candidates: list<array{employee_id: int, user_id: int, membership_id: int}>,
     *     unresolved: list<array{employee_id: int, reason: string}>,
     *     skipped: list<array{employee_id: int, reason: string}>
     * }
     */
    public function report(int $clinicId): array
    {
        $employees = DB::table('clinic_employees')->where('clinic_id', $clinicId);

        [$candidates, $unresolved, $skipped] = $this->linkCandidates($clinicId);

        return [
            'clinic_id' => $clinicId,
            'employees' => (clone $employees)->count(),
            'linked' => (clone $employees)->whereNotNull('user_id')->count(),
            'unlinked' => (clone $employees)->whereNull('user_id')->count(),
            'practitioners' => DB::table('doctor_profiles')->where('clinic_id', $clinicId)->count(),
            'membership_branch_rows' => DB::table('branch_clinic_membership')
                ->join('clinic_memberships', 'clinic_memberships.id', '=', 'branch_clinic_membership.clinic_membership_id')
                ->where('clinic_memberships.clinic_id', $clinicId)
                ->count(),
            'members_without_branch_access' => DB::table('clinic_memberships')
                ->where('clinic_id', $clinicId)
                ->where('status', ClinicMembershipStatus::Active->value)
                ->where('has_all_branches_access', false)
                ->where('is_primary_owner', false)
                ->whereNotExists(fn ($query) => $query->from('branch_clinic_membership')
                    ->whereColumn('branch_clinic_membership.clinic_membership_id', 'clinic_memberships.id'))
                ->count(),
            'invalid_staff_branch_rows' => DB::table('clinic_employee_branches')
                ->join('clinic_employees', 'clinic_employees.id', '=', 'clinic_employee_branches.clinic_employee_id')
                ->leftJoin('branches', 'branches.id', '=', 'clinic_employee_branches.branch_id')
                ->where('clinic_employees.clinic_id', $clinicId)
                ->where(fn ($query) => $query->whereNull('branches.id')->orWhereColumn('branches.clinic_id', '!=', 'clinic_employees.clinic_id'))
                ->count(),
            'unmigrated_availability_rows' => DB::table('doctor_availabilities')
                ->join('doctor_profiles', 'doctor_profiles.id', '=', 'doctor_availabilities.doctor_profile_id')
                ->where('doctor_profiles.clinic_id', $clinicId)
                ->whereNotExists(fn ($query) => $query->from('practitioner_schedules')
                    ->whereColumn('practitioner_schedules.clinic_employee_id', 'doctor_profiles.clinic_employee_id')
                    ->whereColumn('practitioner_schedules.branch_id', 'doctor_availabilities.branch_id')
                    ->whereColumn('practitioner_schedules.start_time', 'doctor_availabilities.start_time'))
                ->count(),
            'candidates' => $candidates,
            'unresolved' => $unresolved,
            'skipped' => $skipped,
        ];
    }

    /**
     * Link every unambiguous candidate in the clinic. Safe to run again: a
     * linked staff record is never a candidate.
     *
     * @return int the number of staff records linked
     */
    public function link(int $clinicId): int
    {
        [$candidates] = $this->linkCandidates($clinicId);
        $linked = 0;

        foreach ($candidates as $candidate) {
            DB::transaction(function () use ($candidate, &$linked) {
                $employee = ClinicEmployee::withoutGlobalScopes()->lockForUpdate()->find($candidate['employee_id']);

                if (! $employee || $employee->user_id !== null) {
                    return;
                }

                $employee->update(['user_id' => $candidate['user_id']]);
                $linked++;

                $membership = ClinicMembership::withoutGlobalScopes()->find($candidate['membership_id']);

                if ($membership && ! $membership->has_all_branches_access && ! $membership->assignedBranches()->exists()) {
                    $branches = Branch::withoutGlobalScopes()
                        ->whereIn('id', DB::table('clinic_employee_branches')->where('clinic_employee_id', $employee->id)->select('branch_id'))
                        ->where('clinic_id', $employee->clinic_id)
                        ->get();

                    if ($branches->isNotEmpty()) {
                        $membership->syncAssignedBranches($branches);
                    }
                }
            });
        }

        return $linked;
    }

    /**
     * @return array{0: list<array{employee_id: int, user_id: int, membership_id: int}>, 1: list<array{employee_id: int, reason: string}>, 2: list<array{employee_id: int, reason: string}>}
     */
    private function linkCandidates(int $clinicId): array
    {
        $unlinked = DB::table('clinic_employees')
            ->where('clinic_id', $clinicId)
            ->whereNull('user_id')
            ->get(['id', 'email', 'status']);

        $users = DB::table('users')
            ->whereIn('email', $unlinked->pluck('email')->filter()->unique())
            ->get(['id', 'email'])
            ->keyBy(fn ($user) => mb_strtolower($user->email));

        $memberships = DB::table('clinic_memberships')
            ->where('clinic_id', $clinicId)
            ->whereIn('user_id', $users->pluck('id'))
            ->get(['id', 'user_id', 'status'])
            ->keyBy('user_id');

        $alreadyLinked = DB::table('clinic_employees')
            ->where('clinic_id', $clinicId)
            ->whereIn('user_id', $users->pluck('id'))
            ->pluck('user_id')
            ->flip();

        $candidates = [];
        $unresolved = [];
        $skipped = [];
        $claimed = new Collection;

        foreach ($unlinked as $employee) {
            $user = $employee->email ? $users->get(mb_strtolower($employee->email)) : null;
            $membership = $user ? $memberships->get($user->id) : null;

            $reason = match (true) {
                ! $employee->email => 'no email on the staff record',
                ! $user => null,
                ! $membership => 'a login with this email has no membership in this clinic',
                $membership->status !== ClinicMembershipStatus::Active->value => 'the login\'s membership in this clinic is not active',
                $alreadyLinked->has($user->id) => 'the login is already linked to another staff record in this clinic',
                $claimed->contains($user->id) => 'more than one staff record matches the same login',
                in_array($employee->status, EmploymentStatus::accessEnding(), true) => 'the staff record is inactive, suspended or resigned',
                default => false,
            };

            if ($reason === null) {
                // No login with this email: nothing to link, and nothing wrong.
                $skipped[] = ['employee_id' => (int) $employee->id, 'reason' => 'no login uses this email'];

                continue;
            }

            if ($reason !== false) {
                $unresolved[] = ['employee_id' => (int) $employee->id, 'reason' => $reason];

                continue;
            }

            $claimed->push($user->id);
            $candidates[] = ['employee_id' => (int) $employee->id, 'user_id' => (int) $user->id, 'membership_id' => (int) $membership->id];
        }

        return [$candidates, $unresolved, $skipped];
    }
}
