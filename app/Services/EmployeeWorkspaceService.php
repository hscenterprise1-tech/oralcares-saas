<?php

namespace App\Services;

use App\EmploymentStatus;
use App\Models\CompanyDepartment;
use App\Models\CompanyEmployee;
use App\Models\CompanyTeam;

class EmployeeWorkspaceService
{
    /**
     * Get payload for the Employees Workspace (Directory + KPIs).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getEmployeesPayload(array $filters = []): array
    {
        $query = CompanyEmployee::with([
            'user:id,name,email',
            'department:id,name,code',
            'team:id,name,code',
            'manager.user:id,name',
        ]);

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('designation', 'like', "%{$search}%")
                    ->orWhere('employee_code', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        if (! empty($filters['department_id'])) {
            $query->where('department_id', (int) $filters['department_id']);
        }

        if (! empty($filters['team_id'])) {
            $query->where('team_id', (int) $filters['team_id']);
        }

        if (! empty($filters['employment_status'])) {
            $query->where('employment_status', $filters['employment_status']);
        }

        $employees = $query->orderBy('id', 'asc')->paginate(15)->withQueryString();

        $departments = CompanyDepartment::where('status', 'active')
            ->orderBy('sort_order', 'asc')
            ->get(['id', 'name', 'code']);

        $teams = CompanyTeam::where('status', 'active')
            ->orderBy('name', 'asc')
            ->get(['id', 'department_id', 'name', 'code']);

        // KPI Summary Counters
        $totalEmployees = CompanyEmployee::count();
        $activeEmployees = CompanyEmployee::where('employment_status', 'active')
            ->orWhere('employment_status', 'verified')
            ->count();
        $managersCount = CompanyEmployee::has('directReports')->count();

        $departmentBreakdown = CompanyDepartment::withCount('employees')
            ->get(['id', 'name', 'code'])
            ->map(function ($dept) {
                return [
                    'id' => $dept->id,
                    'name' => $dept->name,
                    'count' => $dept->employees_count,
                ];
            });

        $allEmployeesList = (clone $query)->get();

        return [
            'employees' => $allEmployeesList,
            'departments' => $departments,
            'teams' => $teams,
            'filters' => $filters,
            'kpi' => [
                'total_employees' => $totalEmployees,
                'active_employees' => $activeEmployees,
                'managers_count' => $managersCount,
                'department_breakdown' => $departmentBreakdown,
            ],
        ];
    }

    /**
     * Get payload for single Employee Detail profile page.
     *
     * @return array<string, mixed>
     */
    public function getEmployeeDetailPayload(int $employeeId): array
    {
        $employee = CompanyEmployee::with([
            'user:id,name,email,user_type,created_at',
            'department',
            'team',
            'manager.user:id,name,email',
            'directReports.user:id,name,email',
            'activities' => function ($q) {
                $q->latest()->limit(20);
            },
        ])->findOrFail($employeeId);

        return [
            'employee' => $employee,
        ];
    }

    /**
     * Transition employee status and log audit activity.
     */
    public function transitionEmployeeStatus(CompanyEmployee $employee, string $newStatus, ?int $userId = null): CompanyEmployee
    {
        /*
         * The old status is an EmploymentStatus, and putting an enum straight
         * into a string throws — every status transition 500'd on this line.
         */
        $previous = $employee->employment_status;
        $previous = $previous instanceof EmploymentStatus ? $previous->value : (string) $previous;

        $employee->update(['employment_status' => $newStatus]);

        /*
         * `company_employee_activities`, in the shape that table actually has.
         *
         * This wrote `user_id` and `type` — columns belonging to the polymorphic
         * `activities` table — into the employee audit log, and never supplied
         * `event`, which is NOT NULL. Every status change failed on the insert.
         *
         * The columns here match `CompanyEmployeeInvitationService::logActivity`,
         * which is the writer that works. That table records what happened and
         * where from; it has no actor column, so `$userId` is not stored.
         */
        $employee->activities()->create([
            'event' => 'Status Changed',
            'description' => "Employment status changed from {$previous} to {$newStatus}.",
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return $employee->fresh(['user', 'department', 'team', 'manager']);
    }
}
