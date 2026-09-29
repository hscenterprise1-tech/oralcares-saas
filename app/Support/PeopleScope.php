<?php

namespace App\Support;

use App\Models\CompanyEmployee;
use App\Models\CompanyLeaveRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Who may see and decide whose attendance and leave.
 *
 * Three answers, in this order:
 *
 *   1. Whoever holds the module's read permission sees everybody. Master admins
 *      arrive here through the `Gate::before` bypass like everywhere else — the
 *      bypass is the architecture, not a special case written into this file.
 *   2. A manager sees their direct reports.
 *   3. Everyone else sees themselves, and only if they have an employee record.
 *
 * The manager check reads `company_employees.manager_id` and verifies the
 * actual reporting line. It is deliberately *direct* reports only: a
 * skip-level chain would be a policy nobody has stated, and quietly walking one
 * would hand a director approval rights over people they were never given.
 */
class PeopleScope
{
    /** The employee record behind a login, if there is one. */
    public static function employeeFor(?User $user): ?CompanyEmployee
    {
        if (! $user) {
            return null;
        }

        return CompanyEmployee::query()->where('user_id', $user->id)->first();
    }

    /**
     * Whether the actor may read this employee's attendance and leave.
     */
    public static function canView(User $actor, CompanyEmployee $employee, string $permission): bool
    {
        if (Gate::forUser($actor)->allows($permission)) {
            return true;
        }

        $self = self::employeeFor($actor);

        if (! $self) {
            return false;
        }

        return $self->id === $employee->id || self::managesDirectly($self, $employee);
    }

    /** Whether `$manager` is the employee's own manager, on record. */
    public static function managesDirectly(CompanyEmployee $manager, CompanyEmployee $employee): bool
    {
        return $employee->manager_id !== null && (int) $employee->manager_id === (int) $manager->id;
    }

    /**
     * How this actor is entitled to decide on a leave request, or null.
     *
     * Returns the route the authority came by — `manager` or `hr` — because two
     * people can both be allowed for different reasons and which one applied is
     * worth recording on the decision.
     *
     * Self-approval is refused outright, and refused *before* either check: a
     * manager who is somehow their own manager still cannot sign off their own
     * leave, and neither can an HR holder approving their own request.
     */
    public static function approvalRouteFor(User $actor, CompanyLeaveRequest $request): ?string
    {
        $self = self::employeeFor($actor);

        if ($self && (int) $self->id === (int) $request->employee_id) {
            return null;
        }

        if ($self && self::managesDirectly($self, $request->employee)) {
            return 'manager';
        }

        /*
         * The fallback, and the reason it exists: four of the nine people on
         * record have no manager at all, so a manager-only chain would leave
         * their requests permanently undecidable.
         */
        if (Gate::forUser($actor)->allows('approve-leave')) {
            return 'hr';
        }

        return null;
    }

    /**
     * Narrow a query on `employee_id` to what this actor may read.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    public static function restrict(Builder $query, User $actor, string $permission, string $column = 'employee_id'): void
    {
        if (Gate::forUser($actor)->allows($permission)) {
            return;
        }

        $self = self::employeeFor($actor);

        if (! $self) {
            /* No employee record and no permission: nothing to see. */
            $query->whereRaw('1 = 0');

            return;
        }

        $visible = $self->directReports()->pluck('id')->push($self->id)->unique()->all();

        $query->whereIn($column, $visible);
    }
}
