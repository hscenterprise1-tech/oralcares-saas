<?php

namespace App\Http\Controllers\Clinic\Admin;

use App\ClinicPermission;
use App\ClinicRole;
use App\EmploymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ClinicDepartment;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\Practitioner;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\Security\SecurityAudit;
use App\Services\Staff\StaffDirectory;
use App\Services\Staff\StaffProfileView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * Staff management: the list, add and edit, the profile, and status changes.
 *
 * Every record is held to the active clinic, and a member limited to some
 * branches only sees and edits staff placed at those branches (or not yet
 * placed). Roles keep their own permission (`roles.manage`).
 */
class EmployeeController extends Controller
{
    public function __construct(
        protected StaffDirectory $directory,
        protected StaffProfileView $profiles,
    ) {}

    public function index(Request $request, ClinicContext $context): Response
    {
        $clinic = $context->clinic();
        $membership = $context->membership();
        $search = mb_substr(trim((string) $request->input('q', '')), 0, 60);
        $type = array_key_exists((string) $request->input('type'), StaffDirectory::staffTypes()) ? $request->input('type') : null;

        $visible = $this->directory->visible($clinic, $membership);
        $counts = (clone $visible)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        $employees = $visible
            ->when($search !== '', fn ($q) => $q->where(fn ($match) => $match
                ->where('clinic_employees.name', 'like', "%{$search}%")
                ->orWhere('clinic_employees.email', 'like', "%{$search}%")
                ->orWhere('clinic_employees.phone', 'like', "{$search}%")))
            ->when($type, fn ($q, $value) => $q->where('staff_type', $value))
            ->with([
                'user:id,email',
                'branches:id,name',
                'primaryBranch:id,name',
                'roles',
                'practitionerProfile' => fn ($q) => $q->withoutGlobalScopes()->with(['qualificationRecords', 'primarySpecialty:id,name']),
                'invitations' => fn ($query) => $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->latest(),
            ])
            ->orderBy('clinic_employees.name')
            ->limit(500)
            ->get();

        $this->showCanonicalRoles($employees, $clinic->id);
        $lastActivity = $this->directory->lastActivity($clinic, $employees->pluck('user_id')->filter()->map(fn ($id) => (int) $id)->values()->all());

        return Inertia::render('ClinicPortal/Staff/Index', [
            'employees' => $employees->map(fn (ClinicEmployee $employee) => $this->profiles->row($employee, $lastActivity->get($employee->user_id)))->values(),
            'counts' => collect(StaffDirectory::TABS)->map(fn (array $statuses) => collect($statuses)->sum(fn (EmploymentStatus $status) => (int) ($counts[$status->value] ?? 0)))->all(),
            'filters' => ['q' => $search, 'type' => $type],
            'staffTypes' => $this->profiles->staffTypeOptions(),
            'links' => [
                'index' => route('admin.employees.index'),
                'create' => route('admin.employees.create'),
                'show' => route('admin.employees.show', ['employee' => '__ID__']),
                'documentSettings' => route('admin.staff-documents.settings'),
            ],
        ]);
    }

    public function create(ClinicContext $context): Response
    {
        return Inertia::render('ClinicPortal/Staff/Form', $this->formProps($context, null));
    }

    public function store(Request $request, ClinicContext $context, SecurityAudit $audit): RedirectResponse
    {
        $clinic = $context->clinic();
        $validated = $request->validate($this->rules($context, null));

        $employee = DB::transaction(function () use ($validated, $clinic, $request) {
            $employee = ClinicEmployee::create([
                'clinic_id' => $clinic->id,
                'name' => $validated['name'],
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'status' => $validated['status'],
                'status_changed_at' => now(),
            ] + $this->profileFields($validated));

            $branchIds = array_map('intval', $validated['branches'] ?? []);
            if ($branchIds !== []) {
                $employee->branches()->sync($branchIds);
            }
            $this->syncPrimaryAndDepartment($employee, $validated, $branchIds);

            if (StaffDirectory::isClinical($employee->staff_type)) {
                Practitioner::create(['clinic_employee_id' => $employee->id, 'profession' => StaffDirectory::typeLabel($employee->staff_type), 'is_active' => true]);
            }

            if (! empty($validated['roles'])) {
                abort_unless($request->user()->hasClinicPermission(ClinicPermission::RolesManage), 403, 'You may not assign staff roles.');

                // A new staff record has no login, so these are the roles to
                // grant when their invitation is accepted.
                $employee->roles()->sync($validated['roles']);
            }

            return $employee;
        });

        $audit->record('staff.created', $employee, ['staff_type' => $employee->staff_type]);

        return redirect()->route('admin.employees.show', $employee)->with('success', "{$employee->name} added to staff.");
    }

    public function show(Request $request, ClinicEmployee $employee, ClinicContext $context): Response
    {
        $this->authorizeStaff($employee, $context);

        return Inertia::render('ClinicPortal/Staff/Show', $this->profiles->profile($context, $employee, $request->user(), (string) $request->input('tab', '')));
    }

    public function edit(ClinicEmployee $employee, ClinicContext $context): Response
    {
        $this->authorizeStaff($employee, $context);

        return Inertia::render('ClinicPortal/Staff/Form', $this->formProps($context, $employee));
    }

    public function update(
        Request $request,
        ClinicEmployee $employee,
        ClinicContext $context,
        ClinicRoleAssignmentService $roleAssignments,
        SecurityAudit $audit,
    ): RedirectResponse {
        $this->authorizeStaff($employee, $context);

        $validated = $request->validate($this->rules($context, $employee));
        if (isset($validated['branches']) && $validated['branches'] === [] && $employee->signsInAtPlacedBranches()) {
            $reachable = $this->directory->branches($context->clinic(), $context->membership())->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($employee->branches()->whereNotIn('branches.id', $reachable)->doesntExist()) {
                throw ValidationException::withMessages(['branches' => 'Someone who signs in needs at least one branch. Add a branch, or deactivate them instead.']);
            }
        }

        $statusChanged = $employee->status?->value !== $validated['status'];

        $employee->update([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'status' => $validated['status'],
            'status_changed_at' => $statusChanged ? now() : $employee->status_changed_at,
        ] + $this->profileFields($validated, $employee));

        if (isset($validated['branches'])) {
            $this->placeAtBranches($employee, array_map('intval', $validated['branches']), $context, $request->user());
        }
        $this->syncPrimaryAndDepartment($employee, $validated, $employee->branches()->pluck('branches.id')->map(fn ($id) => (int) $id)->all());

        if (StaffDirectory::isClinical($employee->staff_type) && ! $employee->practitionerProfile()->withoutGlobalScopes()->exists()) {
            Practitioner::create(['clinic_employee_id' => $employee->id, 'profession' => StaffDirectory::typeLabel($employee->staff_type), 'is_active' => true]);
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

        $audit->record('staff.updated', $employee, ['status_changed' => $statusChanged]);

        return redirect()->route('admin.employees.show', $employee)->with('success', 'Staff details saved.');
    }

    /**
     * Deactivate, suspend, reactivate or archive. Archiving keeps the record
     * and its history; it only leaves the active staff.
     */
    public function updateStatus(Request $request, ClinicEmployee $employee, ClinicContext $context, SecurityAudit $audit): RedirectResponse
    {
        $this->authorizeStaff($employee, $context);

        $validated = $request->validate([
            'status' => ['required', Rule::in([EmploymentStatus::Active->value, EmploymentStatus::Inactive->value, EmploymentStatus::Suspended->value, EmploymentStatus::Resigned->value])],
        ]);

        if ($employee->user_id && (int) $employee->user_id === (int) $request->user()->id && $validated['status'] !== EmploymentStatus::Active->value) {
            return back()->withErrors(['status' => 'You cannot deactivate your own staff record.']);
        }

        $before = $employee->status?->value;
        $employee->update(['status' => $validated['status'], 'status_changed_at' => now()]);
        $audit->record('staff.status_changed', $employee, ['from' => $before, 'to' => $validated['status']]);

        $label = match ($validated['status']) {
            'active' => 'reactivated',
            'inactive' => 'deactivated',
            'suspended' => 'suspended',
            default => 'archived',
        };

        return back()->with('success', "{$employee->name} {$label}.");
    }

    public function destroy(ClinicEmployee $employee, ClinicContext $context): RedirectResponse
    {
        $this->authorizeStaff($employee, $context);

        // Ideally check if employee has dependencies.
        $employee->delete();

        return redirect()->route('admin.employees.index')->with('success', 'Employee deleted successfully.');
    }

    private function authorizeStaff(ClinicEmployee $employee, ClinicContext $context): void
    {
        if (! $this->directory->canSee($employee, $context->clinic(), $context->membership())) {
            abort(403);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(ClinicContext $context, ?ClinicEmployee $employee): array
    {
        $clinic = $context->clinic();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('clinic_employees', 'email')->where('clinic_id', $clinic->id)->ignore($employee?->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'status' => ['required', Rule::enum(EmploymentStatus::class)],
            'staff_type' => ['nullable', Rule::in(array_keys(StaffDirectory::staffTypes()))],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::in(['female', 'male', 'other'])],
            'joining_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'department_id' => ['nullable', 'integer', Rule::exists('clinic_departments', 'id')->where('clinic_id', $clinic->id)],
            'primary_branch_id' => ['nullable', 'integer', Rule::in($this->directory->branches($clinic, $context->membership())->pluck('id')->all())],
            'branches' => ['array'],
            'branches.*' => [Rule::exists('branches', 'id')->where('clinic_id', $clinic->id), Rule::in($this->directory->branches($clinic, $context->membership())->pluck('id')->all())],
            'roles' => ['array'],
            'roles.*' => [Rule::exists(config('permission.table_names.roles'), 'id')->whereIn('name', ClinicRole::values())],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function profileFields(array $validated, ?ClinicEmployee $employee = null): array
    {
        $fields = [];
        foreach (['staff_type', 'date_of_birth', 'gender', 'joining_date', 'notes'] as $field) {
            if (array_key_exists($field, $validated)) {
                $fields[$field] = $validated[$field];
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  list<int>  $branchIds
     */
    private function syncPrimaryAndDepartment(ClinicEmployee $employee, array $validated, array $branchIds): void
    {
        if (array_key_exists('primary_branch_id', $validated)) {
            $primary = $validated['primary_branch_id'] ? (int) $validated['primary_branch_id'] : null;
            if ($primary && ! in_array($primary, $branchIds, true)) {
                $employee->branches()->attach($primary);
            }
            $employee->update(['primary_branch_id' => $primary]);
        } elseif (! $employee->primary_branch_id && count($branchIds) === 1) {
            $employee->update(['primary_branch_id' => $branchIds[0]]);
        }

        if (array_key_exists('department_id', $validated)) {
            $employee->departments()->sync($validated['department_id'] ? [(int) $validated['department_id']] : []);
        }
    }

    /**
     * Place staff at the requested branches the acting member can reach,
     * keeping any placements at branches outside their reach, and keep the
     * login's branch access in step.
     *
     * @param  list<int>  $requested
     */
    private function placeAtBranches(ClinicEmployee $employee, array $requested, ClinicContext $context, ?User $actor): void
    {
        $reachable = $this->directory->branches($context->clinic(), $context->membership())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $kept = $employee->branches()->pluck('branches.id')->map(fn ($id) => (int) $id)->reject(fn (int $id) => in_array($id, $reachable, true))->all();

        $employee->branches()->sync(array_values(array_unique([...$requested, ...$kept])));

        // The employee's branches are also where their login may work.
        // Invitation acceptance copies them onto the membership once; keep
        // that copy in step with every later change, so an admin who moves
        // someone between branches actually moves their access.
        $membership = $employee->user_id
            ? ClinicMembership::query()->where('clinic_id', $employee->clinic_id)->where('user_id', $employee->user_id)->first()
            : null;

        if ($membership && ! $membership->has_all_branches_access) {
            $membership->syncAssignedBranches($employee->branches()->get(), $actor?->id);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formProps(ClinicContext $context, ?ClinicEmployee $employee): array
    {
        $clinic = $context->clinic();

        if ($employee) {
            $employee->load(['branches:id', 'departments:id', 'roles']);
            $this->showCanonicalRoles(collect([$employee]), $clinic->id);
        }

        return [
            'employee' => $employee ? [
                'id' => $employee->id,
                'name' => $employee->name,
                'email' => $employee->email,
                'phone' => $employee->phone,
                'status' => $employee->status?->value,
                'staff_type' => $employee->staff_type,
                'date_of_birth' => $employee->date_of_birth?->toDateString(),
                'gender' => $employee->gender,
                'joining_date' => $employee->joining_date?->toDateString(),
                'notes' => $employee->notes,
                'primary_branch_id' => $employee->primary_branch_id,
                'department_id' => $employee->departments->first()?->id,
                'branches' => $employee->branches->pluck('id')->map(fn ($id) => (int) $id)->values(),
                'roles' => $employee->roles->map(fn ($role) => ['id' => (int) $role->id, 'name' => $role->name])->values(),
                'has_login' => $employee->user_id !== null,
            ] : null,
            'branches' => $this->directory->branches($clinic, $context->membership())->map(fn (Branch $branch) => ['id' => $branch->id, 'name' => $branch->name, 'is_main' => (bool) $branch->is_main])->values(),
            'departments' => ClinicDepartment::query()->where('clinic_id', $clinic->id)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'roles' => Role::where('guard_name', 'web')->whereIn('name', ClinicRole::values())->orderBy('name')->get(['id', 'name']),
            'staffTypes' => $this->profiles->staffTypeOptions(),
            'statuses' => collect(EmploymentStatus::cases())->map(fn (EmploymentStatus $status) => ['value' => $status->value, 'label' => ucwords(str_replace('_', ' ', $status->value))])->values(),
            'canAssignRoles' => request()->user()?->hasClinicPermission(ClinicPermission::RolesManage) ?? false,
            'links' => [
                'submit' => $employee ? route('admin.employees.update', $employee) : route('admin.employees.store'),
                'back' => $employee ? route('admin.employees.show', $employee) : route('admin.employees.index'),
            ],
        ];
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
}
