<?php

namespace App\Http\Controllers\Clinic\Admin;

use App\ClinicPermission;
use App\ClinicRole;
use App\EmploymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class EmployeeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(ClinicContext $context): Response
    {
        $employees = ClinicEmployee::where('clinic_id', $context->clinic()->id)
            ->with(['user', 'branches', 'roles', 'invitations' => function ($query) {
                $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->latest();
            }])
            ->latest()
            ->get();

        $this->showCanonicalRoles($employees, $context->clinic()->id);

        return Inertia::render('Clinic/Admin/Employees/Index', [
            'employees' => $employees,
            'employmentStatuses' => EmploymentStatus::cases(),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(ClinicContext $context): Response
    {
        $branches = Branch::where('clinic_id', $context->clinic()->id)->get();
        $roles = Role::where('guard_name', 'web')->whereIn('name', ClinicRole::values())->get();

        return Inertia::render('Clinic/Admin/Employees/Create', [
            'branches' => $branches,
            'roles' => $roles,
            'employmentStatuses' => EmploymentStatus::cases(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, ClinicContext $context): RedirectResponse
    {
        // This method handles creating an employee profile without an associated user account initially.
        // Or it could be used for basic staff that don't need login. For those who do, we use the Invitation flow.

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'status' => ['required', Rule::enum(EmploymentStatus::class)],
            'branches' => ['array'],
            'branches.*' => [Rule::exists('branches', 'id')->where('clinic_id', $context->clinic()->id)],
            'roles' => ['array'],
            'roles.*' => [Rule::exists(config('permission.table_names.roles'), 'id')->whereIn('name', ClinicRole::values())],
        ]);

        $employee = ClinicEmployee::create([
            'clinic_id' => $context->clinic()->id,
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'status' => $validated['status'],
        ]);

        if (! empty($validated['branches'])) {
            $employee->branches()->sync($validated['branches']);
        }

        if (! empty($validated['roles'])) {
            abort_unless($request->user()->hasClinicPermission(ClinicPermission::RolesManage), 403, 'You may not assign staff roles.');

            // A new staff record has no login, so these are the roles to
            // grant when their invitation is accepted.
            $employee->roles()->sync($validated['roles']);
        }

        return redirect()->route('admin.employees.index')->with('success', 'Employee created successfully.');
    }

    /**
     * The role ids that count for this staff member now: their Spatie roles
     * on this clinic's branches once they have a login, else the roles held
     * for their invitation.
     *
     * @return Collection<int, int>
     */
    private function currentRoleIds(ClinicEmployee $employee): Collection
    {
        if (! $employee->user_id) {
            return $employee->roles()->pluck(config('permission.table_names.roles').'.id')->map(fn ($id) => (int) $id);
        }

        return $this->spatieRoleIdsByUser([$employee->user_id], $employee->clinic_id)->get($employee->user_id, collect());
    }

    /**
     * Replace the `roles` shown for staff who have a login with their real
     * Spatie roles, so the page never shows the stale invitation copy.
     *
     * @param  Collection<int, ClinicEmployee>  $employees
     */
    private function showCanonicalRoles(Collection $employees, int $clinicId): void
    {
        $linked = $employees->filter(fn (ClinicEmployee $employee) => $employee->user_id !== null);

        if ($linked->isEmpty()) {
            return;
        }

        $roleIdsByUser = $this->spatieRoleIdsByUser($linked->pluck('user_id')->all(), $clinicId);
        $roles = Role::whereIn('id', $roleIdsByUser->flatten()->unique())->get()->keyBy('id');

        foreach ($linked as $employee) {
            $employee->setRelation('roles', $roleIdsByUser->get($employee->user_id, collect())->map(fn (int $id) => $roles->get($id))->filter()->values());
        }
    }

    /**
     * One query for the Spatie role ids each user holds on any branch of the
     * clinic.
     *
     * @param  array<int, int>  $userIds
     * @return Collection<int, Collection<int, int>>
     */
    private function spatieRoleIdsByUser(array $userIds, int $clinicId): Collection
    {
        return DB::table(config('permission.table_names.model_has_roles'))
            ->where('model_type', (new User)->getMorphClass())
            ->whereIn('model_id', $userIds)
            ->whereIn('branch_id', Branch::withoutGlobalScopes()->where('clinic_id', $clinicId)->select('id'))
            ->get(['model_id', 'role_id'])
            ->groupBy('model_id')
            ->map(fn (Collection $rows) => $rows->pluck('role_id')->map(fn ($id) => (int) $id)->unique()->values());
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ClinicEmployee $employee, ClinicContext $context): Response
    {
        if ($employee->clinic_id !== $context->clinic()->id) {
            abort(403);
        }

        $branches = Branch::where('clinic_id', $context->clinic()->id)->get();
        $roles = Role::where('guard_name', 'web')->whereIn('name', ClinicRole::values())->get();

        $employee->load(['branches', 'roles', 'invitations' => function ($query) {
            $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->latest();
        }]);

        $this->showCanonicalRoles(collect([$employee]), $context->clinic()->id);

        return Inertia::render('Clinic/Admin/Employees/Edit', [
            'employee' => $employee,
            'branches' => $branches,
            'roles' => $roles,
            'employmentStatuses' => EmploymentStatus::cases(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(
        Request $request,
        ClinicEmployee $employee,
        ClinicContext $context,
        ClinicRoleAssignmentService $roleAssignments
    ): RedirectResponse {
        if ($employee->clinic_id !== $context->clinic()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'status' => ['required', Rule::enum(EmploymentStatus::class)],
            'branches' => ['array'],
            'branches.*' => [Rule::exists('branches', 'id')->where('clinic_id', $context->clinic()->id)],
            'roles' => ['array'],
            'roles.*' => [Rule::exists(config('permission.table_names.roles'), 'id')->whereIn('name', ClinicRole::values())],
        ]);

        $employee->update([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'status' => $validated['status'],
        ]);

        if (isset($validated['branches'])) {
            $employee->branches()->sync($validated['branches']);

            // The employee's branches are also where their login may work.
            // Invitation acceptance copies them onto the membership once;
            // keep that copy in step with every later change, so an admin
            // who moves someone between branches actually moves their access.
            $membership = $employee->user_id
                ? ClinicMembership::query()->where('clinic_id', $employee->clinic_id)->where('user_id', $employee->user_id)->first()
                : null;

            if ($membership && ! $membership->has_all_branches_access) {
                $membership->syncAssignedBranches($employee->branches()->get(), $request->user()?->id);
            }
        }

        if (isset($validated['roles'])) {
            $requested = collect($validated['roles'])->map(fn ($id) => (int) $id)->sort()->values()->all();
            $current = $this->currentRoleIds($employee)->sort()->values()->all();

            // Changing what someone may do is its own permission; managing
            // staff details is not enough to hand out roles.
            if ($requested !== $current) {
                abort_unless($request->user()->hasClinicPermission(ClinicPermission::RolesManage), 403, 'You may not change staff roles.');
            }

            if ($employee->user_id) {
                // Linked staff: Spatie roles, per branch, are the only roles
                // that count. They are set on each branch the person works at,
                // never on whichever branch the acting admin has open.
                $roleNames = Role::whereIn('id', $requested)->pluck('name')->toArray();
                $roleAssignments->syncRoles($employee->user, $roleNames, $employee->branches()->get());
            } else {
                // No login yet: keep the roles to grant when their invitation
                // is accepted. Nothing reads these for access checks.
                $employee->roles()->sync($requested);
            }
        }

        return redirect()->route('admin.employees.index')->with('success', 'Employee updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ClinicEmployee $employee, ClinicContext $context): RedirectResponse
    {
        if ($employee->clinic_id !== $context->clinic()->id) {
            abort(403);
        }

        // Ideally check if employee has dependencies.
        $employee->delete();

        return redirect()->route('admin.employees.index')->with('success', 'Employee deleted successfully.');
    }
}
