<?php

namespace App\Http\Controllers\Company\People;

use App\EmploymentStatus;
use App\Http\Controllers\Controller;
use App\Models\CompanyEmployee;
use App\Models\User;
use App\Services\EmployeeWorkspaceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class EmployeeController extends Controller
{
    public function __construct(
        protected EmployeeWorkspaceService $workspaceService
    ) {}

    /**
     * Display HQ Employee Directory workspace.
     */
    public function index(Request $request)
    {
        $filters = $request->only(['search', 'department_id', 'team_id', 'employment_status']);
        $payload = $this->workspaceService->getEmployeesPayload($filters);

        return Inertia::render('Company/People/EmployeesWorkspace', $payload);
    }

    /**
     * Display single Employee Detail profile page.
     */
    public function show(int $id)
    {
        $payload = $this->workspaceService->getEmployeeDetailPayload($id);

        return Inertia::render('Company/People/EmployeeDetail', $payload);
    }

    /**
     * Store new Company Employee profile & linked User.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'employee_code' => 'required|string|unique:company_employees,employee_code',
            'designation' => 'required|string|max:255',
            'department_id' => 'required|exists:company_departments,id',
            'team_id' => 'nullable|exists:company_teams,id',
            'manager_id' => 'nullable|exists:company_employees,id',
            'employment_status' => ['required', Rule::enum(EmploymentStatus::class)],
            'joining_date' => 'required|date',
            'timezone' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        /*
         * An unguessable password, and nobody ever learns it.
         *
         * This was a bcrypt of one hard-coded literal, so every employee created
         * through this form shared a single known credential — and `CompanyPortalMiddleware` lets
         * any `company_user` into the portal, which made a guessable email the
         * only thing standing between an outsider and the Company side.
         *
         * The employee sets their own through the invitation flow that already
         * exists: `CompanyEmployeeInvitationService::acceptInvitation()`
         * replaces this value and marks them Active.
         */
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make(Str::random(40)),
            'user_type' => 'company_user',
        ]);

        $employee = CompanyEmployee::create([
            'user_id' => $user->id,
            'employee_code' => $validated['employee_code'],
            'designation' => $validated['designation'],
            'department_id' => $validated['department_id'],
            'team_id' => $validated['team_id'] ?? null,
            'manager_id' => $validated['manager_id'] ?? null,
            'employment_status' => $validated['employment_status'],
            'joining_date' => $validated['joining_date'],
            'timezone' => $validated['timezone'] ?? 'Asia/Kolkata',
            'notes' => $validated['notes'] ?? null,
        ]);

        return back()->with('success', "Employee {$employee->employee_code} added successfully.");
    }

    /**
     * Update existing Company Employee profile.
     */
    public function update(Request $request, CompanyEmployee $employee)
    {
        $validated = $request->validate([
            'designation' => 'required|string|max:255',
            'department_id' => 'required|exists:company_departments,id',
            'team_id' => 'nullable|exists:company_teams,id',
            'manager_id' => 'nullable|exists:company_employees,id',
            'employment_status' => ['required', Rule::enum(EmploymentStatus::class)],
            'joining_date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $employee->update($validated);

        return back()->with('success', 'Employee profile updated.');
    }

    /**
     * Transition employee status.
     */
    public function transitionStatus(Request $request, CompanyEmployee $employee)
    {
        $validated = $request->validate([
            'employment_status' => ['required', Rule::enum(EmploymentStatus::class)],
        ]);

        $this->workspaceService->transitionEmployeeStatus($employee, $validated['employment_status'], $request->user()->id);

        return back()->with('success', "Employee {$employee->employee_code} status updated to {$validated['employment_status']}.");
    }
}
