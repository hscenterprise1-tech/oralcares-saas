<?php

namespace App\Http\Controllers\Clinic\Admin;

use App\ClinicRole;
use App\EmploymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ClinicEmployee;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'status' => ['required', Rule::enum(EmploymentStatus::class)],
            'branches' => ['array'],
            'branches.*' => ['exists:branches,id'],
            'roles' => ['array'],
            'roles.*' => [Rule::exists(config('permission.table_names.roles'), 'id')],
        ]);

        $employee = ClinicEmployee::create([
            'clinic_id' => $context->clinic()->id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'status' => $validated['status'],
        ]);

        if (! empty($validated['branches'])) {
            $employee->branches()->sync($validated['branches']);
        }

        if (! empty($validated['roles'])) {
            $employee->roles()->sync($validated['roles']);
        }

        return redirect()->route('admin.employees.index')->with('success', 'Employee created successfully.');
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
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'status' => ['required', Rule::enum(EmploymentStatus::class)],
            'branches' => ['array'],
            'branches.*' => ['exists:branches,id'],
            'roles' => ['array'],
            'roles.*' => [Rule::exists(config('permission.table_names.roles'), 'id')],
        ]);

        $employee->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'status' => $validated['status'],
        ]);

        if (isset($validated['branches'])) {
            $employee->branches()->sync($validated['branches']);
        }

        if (isset($validated['roles'])) {
            $employee->roles()->sync($validated['roles']);

            // If there's an associated User, sync their Spatie roles too —
            // scoped to each branch the employee can access, not whichever
            // branch happens to be active in the acting admin's own session.
            if ($employee->user_id) {
                $roleNames = Role::whereIn('id', $validated['roles'])->pluck('name')->toArray();
                $roleAssignments->syncRoles($employee->user, $roleNames, $employee->branches()->get());
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
