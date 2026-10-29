<?php

namespace App\Support\ClinicAccess;

use App\ClinicPermission;
use App\Models\Branch;
use App\Models\ClinicMembership;
use App\Models\User;
use App\Scopes\OrganizationScope;
use App\Services\OwnerMode\OwnerMode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which of the clinic's records a member's lists cover, for one permission.
 *
 * The owner covers the whole clinic. Anyone else covers the branches they
 * may open (all of them with all-branch access, otherwise their assigned
 * ones) at which they also hold the permission: roles are granted per branch,
 * so opening a branch is not the same as being allowed to work there. A
 * record with no branch belongs to the whole clinic and is covered only for a
 * member working across every branch who holds the permission somewhere.
 *
 * The one rule behind every Billing list, Expenses list, summary and the
 * dashboard counts that open them, so a count is always its list's length.
 * The same rule `User::hasClinicPermissionAt()` applies to a single record.
 */
final class BranchReach
{
    /**
     * @param  list<int>|null  $branchIds  null: every branch of the clinic
     */
    private function __construct(
        public readonly ?array $branchIds,
        public readonly bool $includesClinicWide,
    ) {}

    public static function for(ClinicMembership $membership, User $user, ClinicPermission $permission): self
    {
        if ($membership->is_primary_owner) {
            // The owner's privileges reach the whole clinic. A doctor-owner in Doctor Mode
            // reaches only what a doctor's permissions do, at every branch they own.
            $ownerMode = app(OwnerMode::class);
            if ($ownerMode->authorized() || ! $ownerMode->isDoctorOwner()) {
                return new self(null, true);
            }
            if (! in_array($permission->value, OwnerMode::doctorModePermissions(), true)) {
                return new self([], false);
            }

            return new self(null, false);
        }

        $clinicBranches = Branch::query()->withoutGlobalScope(OrganizationScope::class)->where('clinic_id', $membership->clinic_id)->select('id');
        $tables = config('permission.table_names');
        $permissionId = DB::table($tables['permissions'])->where('name', $permission->value)->value('id');
        if ($permissionId === null) {
            return new self([], false);
        }

        $viaRoles = DB::table($tables['model_has_roles'])->where('model_type', User::class)->where('model_id', $user->id)
            ->whereIn('branch_id', $clinicBranches)
            ->whereIn('role_id', DB::table($tables['role_has_permissions'])->where('permission_id', $permissionId)->select('role_id'))
            ->pluck('branch_id');
        $direct = DB::table($tables['model_has_permissions'])->where('model_type', User::class)->where('model_id', $user->id)
            ->whereIn('branch_id', $clinicBranches)->where('permission_id', $permissionId)->pluck('branch_id');
        $permitted = $viaRoles->merge($direct)->map(fn ($id) => (int) $id)->unique();

        if (! $membership->has_all_branches_access) {
            $assigned = $membership->assignedBranches()->withoutGlobalScope(OrganizationScope::class)->pluck('branches.id')->map(fn ($id) => (int) $id);
            $permitted = $permitted->intersect($assigned);
        }

        return new self($permitted->sort()->values()->all(), $membership->has_all_branches_access && $permitted->isNotEmpty());
    }

    /** Whether this reach covers the given branch (null: a clinic-wide record). */
    public function covers(?int $branchId): bool
    {
        if ($branchId === null) {
            return $this->includesClinicWide;
        }

        return $this->branchIds === null || in_array($branchId, $this->branchIds, true);
    }

    /** Whether the reach is the whole clinic. */
    public function isWholeClinic(): bool
    {
        return $this->branchIds === null;
    }

    /**
     * Narrow a query on `$column` to this reach, and to one branch when the
     * member asked for one. A branch outside the reach matches nothing, so a
     * filter never quietly shows other branches in its place.
     */
    public function apply(Builder|\Illuminate\Database\Eloquent\Builder $query, string $column, ?int $onlyBranch = null): void
    {
        if ($onlyBranch !== null) {
            $this->covers($onlyBranch) ? $query->where($column, $onlyBranch) : $query->whereRaw('1 = 0');

            return;
        }
        if ($this->branchIds === null) {
            return;
        }

        $ids = $this->branchIds;
        $query->where(function ($inner) use ($column, $ids) {
            $inner->whereIn($column, $ids === [] ? [0] : $ids);
            if ($this->includesClinicWide) {
                $inner->orWhereNull($column);
            }
        });
    }
}
