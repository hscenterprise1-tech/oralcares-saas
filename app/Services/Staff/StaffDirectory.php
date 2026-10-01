<?php

namespace App\Services\Staff;

use App\EmploymentStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\Practitioner;
use App\Models\SecurityAuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who the acting member may see and manage among a clinic's staff, and how a
 * staff record reads in the staff list.
 *
 * A member with every branch (the owner, or all-branches access) sees all
 * staff. Anyone else sees staff placed at one of their branches, plus staff
 * with no placement yet (a new hire not yet placed). The clinic always comes
 * from the session, never from the request.
 */
class StaffDirectory
{
    /** Staff list tabs, and the stored statuses each one shows. */
    public const TABS = [
        'active' => [EmploymentStatus::Active, EmploymentStatus::Verified],
        'invited' => [EmploymentStatus::Invited, EmploymentStatus::PendingInvitation],
        'inactive' => [EmploymentStatus::Inactive],
        'suspended' => [EmploymentStatus::Suspended],
        'archived' => [EmploymentStatus::Resigned],
    ];

    /**
     * @return Builder<ClinicEmployee>
     */
    public function visible(Clinic $clinic, ?ClinicMembership $membership): Builder
    {
        $query = ClinicEmployee::withoutGlobalScopes()->where('clinic_employees.clinic_id', $clinic->id);

        if ($this->clinicWide($membership)) {
            return $query;
        }

        $branchIds = $this->branches($clinic, $membership)->pluck('id');

        return $query->where(fn (Builder $scope) => $scope
            ->whereDoesntHave('branches')
            ->orWhereHas('branches', fn (Builder $branches) => $branches->whereIn('branches.id', $branchIds)));
    }

    public function canSee(ClinicEmployee $employee, Clinic $clinic, ?ClinicMembership $membership): bool
    {
        return (int) $employee->clinic_id === $clinic->id
            && $this->visible($clinic, $membership)->whereKey($employee->id)->exists();
    }

    /**
     * The branches the member may place staff at and see staff of.
     *
     * @return Collection<int, Branch>
     */
    public function branches(Clinic $clinic, ?ClinicMembership $membership): Collection
    {
        if (! $membership) {
            return collect();
        }

        return $membership->accessibleActiveBranches()->orderByDesc('is_main')->orderBy('name')->get(['branches.id', 'branches.name', 'branches.is_main']);
    }

    public function clinicWide(?ClinicMembership $membership): bool
    {
        return (bool) ($membership?->is_primary_owner || $membership?->has_all_branches_access);
    }

    /**
     * Staff types from the catalogue, with labels and whether each is clinical.
     *
     * @return array<string, array{label: string, clinical: bool}>
     */
    public static function staffTypes(): array
    {
        return config('clinic_staff.staff_types', []);
    }

    public static function isClinical(?string $staffType): bool
    {
        return (bool) (self::staffTypes()[$staffType]['clinical'] ?? false);
    }

    /**
     * The staff type that counts for checklists: the recorded one, else
     * "doctor" for anyone with a practitioner profile, else "other".
     */
    public static function effectiveType(ClinicEmployee $employee): string
    {
        if ($employee->staff_type) {
            return $employee->staff_type;
        }

        $hasProfile = $employee->relationLoaded('practitionerProfile')
            ? $employee->practitionerProfile !== null
            : Practitioner::withoutGlobalScopes()->where('clinic_employee_id', $employee->id)->exists();

        return $hasProfile ? 'doctor' : 'other';
    }

    public static function typeLabel(?string $staffType): ?string
    {
        return $staffType ? (self::staffTypes()[$staffType]['label'] ?? ucwords(str_replace('_', ' ', $staffType))) : null;
    }

    /**
     * The tab a stored status belongs to.
     */
    public static function tabOf(?EmploymentStatus $status): string
    {
        foreach (self::TABS as $tab => $statuses) {
            if (in_array($status, $statuses, true)) {
                return $tab;
            }
        }

        return 'active';
    }

    /**
     * The latest thing each linked user did in this clinic, in one query.
     *
     * @param  list<int>  $userIds
     * @return Collection<int, string>
     */
    public function lastActivity(Clinic $clinic, array $userIds): Collection
    {
        if ($userIds === []) {
            return collect();
        }

        return SecurityAuditLog::query()
            ->whereIn('actor_user_id', $userIds)
            ->where(fn (Builder $q) => $q->where('clinic_id', $clinic->id)->orWhereNull('clinic_id'))
            ->groupBy('actor_user_id')
            ->selectRaw('actor_user_id, MAX(occurred_at) as last_at')
            ->pluck('last_at', 'actor_user_id');
    }
}
