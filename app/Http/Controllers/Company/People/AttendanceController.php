<?php

namespace App\Http\Controllers\Company\People;

use App\AttendanceSource;
use App\AttendanceStatus;
use App\Http\Controllers\Controller;
use App\Models\CompanyAttendanceRecord;
use App\Models\CompanyEmployee;
use App\Services\People\AttendanceService;
use App\Services\People\WorkingCalendarService;
use App\Support\PeopleScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HQ attendance.
 *
 * Reading somebody else's day takes `view-attendance`; recording or correcting
 * one takes `manage-attendance`. Without either, a person sees themselves and
 * their own direct reports and nothing else — every list goes through
 * `PeopleScope`, so the surface can never show a row the routes would refuse.
 *
 * Company-side only. The clinic `leaves` and `shifts` tables belong to
 * `clinic_employees` and are not read or written here.
 */
class AttendanceController extends Controller
{
    public function __construct(
        protected AttendanceService $attendance,
        protected WorkingCalendarService $calendar,
    ) {}

    public function index(Request $request): Response
    {
        $date = $request->filled('date')
            ? Carbon::parse($request->string('date')->toString())
            : now();

        $records = CompanyAttendanceRecord::query()
            ->with(['employee.user:id,name', 'employee.department:id,name'])
            ->whereDate('work_date', $date->toDateString());

        PeopleScope::restrict($records, $request->user(), 'view-attendance');

        $self = PeopleScope::employeeFor($request->user());

        return Inertia::render('Company/People/AttendanceOverview', [
            'summary' => $this->attendance->dailySummary($date),
            'records' => $records->get()->map(fn (CompanyAttendanceRecord $record) => $this->row($record))->values(),
            'filters' => ['date' => $date->toDateString()],
            'me' => $self ? [
                'employee_id' => $self->id,
                'name' => $self->user?->name ?? $self->employee_code,
                'timezone' => $self->resolvedTimezone(),
                'today' => $this->myToday($self),
            ] : null,
            'can' => [
                'viewAll' => Gate::allows('view-attendance'),
                'manage' => Gate::allows('manage-attendance'),
            ],
        ]);
    }

    /** One employee's month, day by day. */
    public function show(Request $request, CompanyEmployee $employee): Response
    {
        abort_unless(PeopleScope::canView($request->user(), $employee, 'view-attendance'), 403);

        $employee->load('user:id,name,email', 'department:id,name', 'manager.user:id,name');

        $month = Carbon::createFromFormat('Y-m', $request->string('month')->toString() ?: now()->format('Y-m'))
            ?: now();

        return Inertia::render('Company/People/AttendanceDetail', [
            'employee' => [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'name' => $employee->user?->name ?? $employee->employee_code,
                'designation' => $employee->designation,
                'department' => $employee->department?->name,
                'manager' => $employee->manager?->user?->name,
                'timezone' => $employee->resolvedTimezone(),
                /* Said out loud: three of nine carry no timezone of their own. */
                'timezone_is_fallback' => $employee->timezone === null,
            ],
            'summary' => $this->attendance->monthlySummary($employee, (int) $month->year, (int) $month->month),
            'can' => ['manage' => Gate::allows('manage-attendance')],
        ]);
    }

    /** The employee's own check-in / check-out. */
    public function clock(Request $request)
    {
        $employee = PeopleScope::employeeFor($request->user());

        abort_unless($employee !== null, 403, 'You have no employee record to log attendance against.');

        $validated = $request->validate(['action' => ['required', Rule::in(['in', 'out'])]]);

        try {
            $validated['action'] === 'in'
                ? $this->attendance->checkIn($employee, $request->user())
                : $this->attendance->checkOut($employee, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['action' => $e->getMessage()]);
        }

        return back()->with('success', $validated['action'] === 'in' ? 'Checked in.' : 'Checked out.');
    }

    /**
     * Record a day on somebody's behalf.
     *
     * How a manager or HR marks an absence. Gated on `manage-attendance`, and
     * scoped besides — a manager may only do this for their own reports.
     */
    public function store(Request $request)
    {
        Gate::authorize('manage-attendance');

        $validated = $request->validate([
            'employee_id' => ['required', 'exists:company_employees,id'],
            'work_date' => ['required', 'date'],
            'status' => ['required', Rule::enum(AttendanceStatus::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $employee = CompanyEmployee::findOrFail($validated['employee_id']);

        abort_unless(PeopleScope::canView($request->user(), $employee, 'view-attendance'), 403);

        $this->attendance->mark(
            $employee,
            Carbon::parse($validated['work_date']),
            AttendanceStatus::from($validated['status']),
            Gate::allows('view-attendance') ? AttendanceSource::Hr : AttendanceSource::Manager,
            $request->user(),
            $validated['notes'] ?? null,
        );

        return back()->with('success', 'Attendance recorded.');
    }

    /**
     * Change a day that already exists, with a reason.
     *
     * Every changed field becomes its own correction row. A reason is required
     * by the service, not merely encouraged here.
     */
    public function correct(Request $request, CompanyAttendanceRecord $record)
    {
        Gate::authorize('manage-attendance');

        $record->load('employee');

        abort_unless(PeopleScope::canView($request->user(), $record->employee, 'view-attendance'), 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'status' => ['nullable', Rule::enum(AttendanceStatus::class)],
            'check_in_at' => ['nullable', 'date'],
            'check_out_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $changes = [];

        if (array_key_exists('status', $validated) && $validated['status'] !== null) {
            $changes['status'] = AttendanceStatus::from($validated['status']);
        }

        foreach (['check_in_at', 'check_out_at'] as $field) {
            if (array_key_exists($field, $validated)) {
                $changes[$field] = $validated[$field] ? Carbon::parse($validated[$field]) : null;
            }
        }

        if (array_key_exists('notes', $validated)) {
            $changes['notes'] = $validated['notes'];
        }

        if ($changes === []) {
            throw ValidationException::withMessages(['reason' => 'Nothing was changed.']);
        }

        try {
            $this->attendance->correct($record, $changes, $validated['reason'], $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('success', 'Correction recorded.');
    }

    /** @return array<string, mixed> */
    protected function row(CompanyAttendanceRecord $record): array
    {
        $timezone = $record->timezone ?: $record->employee?->resolvedTimezone() ?? config('app.timezone');

        return [
            'id' => $record->id,
            'employee_id' => $record->employee_id,
            'employee_name' => $record->employee?->user?->name ?? $record->employee?->employee_code,
            'employee_code' => $record->employee?->employee_code,
            'department' => $record->employee?->department?->name,
            'work_date' => $record->work_date->toDateString(),
            'status' => $record->status->value,
            'status_label' => $record->status->label(),
            'check_in_at' => $record->check_in_at?->setTimezone($timezone)->format('H:i'),
            'check_out_at' => $record->check_out_at?->setTimezone($timezone)->format('H:i'),
            'worked_minutes' => $record->worked_minutes,
            'timezone' => $timezone,
            'source' => $record->source->value,
            'source_label' => $record->source->label(),
            'is_open' => $record->isOpen(),
        ];
    }

    /** @return array<string, mixed>|null */
    protected function myToday(CompanyEmployee $employee): ?array
    {
        $today = $this->attendance->localDate($employee);

        $record = CompanyAttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $today->toDateString())
            ->first();

        return $record ? $this->row($record) : null;
    }
}
