<?php

namespace App\Http\Controllers\Company\People;

use App\DepartmentStatus;
use App\Http\Controllers\Controller;
use App\Models\CompanyDepartment;
use App\Models\CompanyTeam;
use App\TeamStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The HQ org chart: departments, and the teams inside them.
 *
 * Nine departments and nine teams have existed in this database with no way to
 * see or change either. `company_employees.department_id` and `.team_id` point
 * here — the employee directory has always read departments to file people —
 * so the structure was editable only by writing SQL.
 *
 * These are NOT the CRM's Sales Teams. That is a different table (`teams`) with
 * a different job: `organization_id`, `assignment_strategy`, `territory_map`
 * and `round_robin_cursor` exist to route leads to reps, and it is scoped per
 * tenant. This one (`company_teams`) has `department_id` and no tenant column,
 * because it describes the vendor's own staff. Merging them would break lead
 * assignment and misfile HQ employees; they stay separate.
 */
class StructureController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', CompanyDepartment::class);

        $departments = CompanyDepartment::query()
            ->withCount('teams')
            ->with(['teams' => fn ($q) => $q->withCount('employees')->orderBy('name')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (CompanyDepartment $department) => [
                'id' => $department->id,
                'name' => $department->name,
                'code' => $department->code,
                'description' => $department->description,
                'status' => $department->status?->value ?? (string) $department->status,
                'sort_order' => (int) ($department->sort_order ?? 0),
                'teams_count' => $department->teams_count,
                /*
                 * Counted through the team rather than the department, because
                 * `company_employees` files a person against both and the team
                 * is the narrower fact.
                 */
                'employees_count' => $department->teams->sum('employees_count'),
                'teams' => $department->teams->map(fn (CompanyTeam $team) => [
                    'id' => $team->id,
                    'name' => $team->name,
                    'code' => $team->code,
                    'description' => $team->description,
                    'status' => $team->status?->value ?? (string) $team->status,
                    'employees_count' => $team->employees_count,
                ])->values(),
            ])
            ->values();

        return Inertia::render('Company/People/StructureWorkspace', [
            'departments' => $departments,
            'can' => [
                'manageDepartments' => Gate::allows('create', CompanyDepartment::class),
                'manageTeams' => Gate::allows('create', CompanyTeam::class),
            ],
        ]);
    }

    public function storeDepartment(Request $request)
    {
        Gate::authorize('create', CompanyDepartment::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('company_departments', 'code')],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::enum(DepartmentStatus::class)],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $department = CompanyDepartment::create($validated);

        return back()->with('success', "{$department->name} created.");
    }

    public function updateDepartment(Request $request, CompanyDepartment $department)
    {
        Gate::authorize('update', $department);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('company_departments', 'code')->ignore($department->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::enum(DepartmentStatus::class)],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $department->update($validated);

        return back()->with('success', "{$department->name} updated.");
    }

    public function storeTeam(Request $request, CompanyDepartment $department)
    {
        Gate::authorize('create', CompanyTeam::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('company_teams', 'code')],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::enum(TeamStatus::class)],
        ]);

        $team = $department->teams()->create($validated);

        return back()->with('success', "{$team->name} added to {$department->name}.");
    }

    public function updateTeam(Request $request, CompanyDepartment $department, CompanyTeam $team)
    {
        Gate::authorize('update', $team);
        abort_unless((int) $team->department_id === (int) $department->id, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('company_teams', 'code')->ignore($team->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::enum(TeamStatus::class)],
            /* Moving a team to another department is a real reorganisation. */
            'department_id' => ['nullable', 'exists:company_departments,id'],
        ]);

        $team->update($validated);

        return back()->with('success', "{$team->name} updated.");
    }
}
