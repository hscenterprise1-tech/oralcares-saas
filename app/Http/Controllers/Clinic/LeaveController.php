<?php

namespace App\Http\Controllers\Clinic;

use App\AppointmentStatus;
use App\ClinicPermission;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\ClinicEmployee;
use App\Models\Leave;
use App\Services\ClinicContext;
use App\Services\Staff\LeaveWorkflow;
use App\Services\Staff\StaffDirectory;
use App\Support\ClinicClock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Leave: staff request their own; approvers (`leave.approve`) see the
 * requests of the staff they can see, decide them, and review the
 * appointments each leave affects. Approving never moves or cancels an
 * appointment.
 */
class LeaveController extends Controller
{
    public function __construct(
        protected ClinicContext $context,
        protected StaffDirectory $directory,
        protected LeaveWorkflow $workflow,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $clinic = $this->context->clinic();
        $approver = $user->hasClinicPermission(ClinicPermission::LeaveApprove);
        abort_unless($approver || $user->hasClinicPermission(ClinicPermission::LeaveRequest), 403);

        $me = $this->context->currentEmployee();
        $branchScope = $this->branchScope();
        $status = in_array($request->input('status'), [Leave::REQUESTED, Leave::APPROVED, Leave::REJECTED, Leave::CANCELLED, 'upcoming'], true) ? $request->input('status') : null;

        $leaves = Leave::query()
            ->where('clinic_id', $clinic->id)
            ->when(! $approver, fn ($q) => $q->where('employee_id', $me?->id ?? 0))
            ->when($approver, fn ($q) => $q->whereIn('employee_id', $this->directory->visible($clinic, $this->context->membership())->select('clinic_employees.id')))
            ->when($status === 'upcoming', fn ($q) => $q->where('status', Leave::APPROVED)->whereDate('end_date', '>=', ClinicClock::today()->toDateString()))
            ->when($status && $status !== 'upcoming', fn ($q) => $q->where('status', $status))
            ->with(['employee:id,name,staff_type', 'decider:id,name', 'requester:id,name'])
            ->orderByRaw("CASE status WHEN 'requested' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")
            ->orderByDesc('start_date')
            ->limit(200)
            ->get();
        $affected = $this->workflow->affectedCounts($leaves, $branchScope);

        $focus = $request->integer('leave') ?: null;
        $focusLeave = $focus ? $leaves->firstWhere('id', $focus) : null;

        return Inertia::render('ClinicPortal/Leave/Index', [
            'leaves' => $leaves->map(fn (Leave $leave) => [
                'id' => $leave->id,
                'employee' => ['id' => $leave->employee_id, 'name' => $leave->employee?->name, 'staff_type' => StaffDirectory::typeLabel($leave->employee?->staff_type)],
                'type' => $leave->leave_type,
                'type_label' => config("clinic_staff.leave_types.{$leave->leave_type}", ucfirst((string) $leave->leave_type)),
                'status' => $leave->status,
                'start_date' => $leave->start_date->toDateString(),
                'end_date' => $leave->end_date->toDateString(),
                'days' => $leave->start_date->diffInDays($leave->end_date) + 1,
                'reason' => $leave->reason,
                'note' => $leave->note,
                'decision_note' => $leave->decision_note,
                'decided_by' => $leave->decider?->name,
                'requested_by' => $leave->requester?->name,
                'affected' => $affected[$leave->id] ?? 0,
                'is_mine' => $me && $leave->employee_id === $me->id,
            ])->values(),
            'affected' => $focusLeave ? $this->affectedList($focusLeave, $branchScope) : null,
            'focus' => $focusLeave?->id,
            'filters' => ['status' => $status],
            'staff' => $approver
                ? $this->directory->visible($clinic, $this->context->membership())
                    ->whereIn('status', ['active', 'verified'])
                    ->orderBy('name')->limit(500)->get(['clinic_employees.id', 'name'])
                    ->map(fn (ClinicEmployee $employee) => ['id' => $employee->id, 'name' => $employee->name])->values()
                : [],
            'me' => $me ? ['id' => $me->id, 'name' => $me->name] : null,
            'leaveTypes' => config('clinic_staff.leave_types'),
            'can' => [
                'approve' => $approver,
                'request' => $user->hasClinicPermission(ClinicPermission::LeaveRequest) || $approver,
                'manageAppointments' => $user->hasClinicPermission(ClinicPermission::AppointmentManage),
            ],
            'links' => [
                'index' => route('clinic.leave.index'),
                'store' => route('clinic.leave.store'),
                'appointments' => route('clinic.appointments.index'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $approver = $user->hasClinicPermission(ClinicPermission::LeaveApprove);
        abort_unless($approver || $user->hasClinicPermission(ClinicPermission::LeaveRequest), 403);
        $clinic = $this->context->clinic();

        $validated = $request->validate([
            'employee_id' => ['nullable', 'integer'],
            'leave_type' => ['required', Rule::in(array_keys(config('clinic_staff.leave_types')))],
            'start_date' => ['required', 'date', 'after_or_equal:'.ClinicClock::today()->toDateString()],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        // Staff request their own leave; only an approver may request for
        // someone else, and only for staff they can see.
        $me = $this->context->currentEmployee();
        $employeeId = $validated['employee_id'] ?? $me?->id;
        $employee = $employeeId ? ClinicEmployee::withoutGlobalScopes()->where('clinic_id', $clinic->id)->find($employeeId) : null;
        abort_unless($employee, 422, 'Choose who the leave is for.');
        if ($employee->id !== $me?->id) {
            abort_unless($approver && $this->directory->canSee($employee, $clinic, $this->context->membership()), 403);
        }

        $leave = $this->workflow->request($clinic, $employee, $validated, $user);

        return redirect()->route('clinic.leave.index', ['leave' => $leave->id])->with('success', 'Leave requested.');
    }

    public function approve(Request $request, Leave $leave): RedirectResponse
    {
        $this->assertDecidable($leave);
        $this->workflow->approve($leave, $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null, $request->user());

        $affected = $this->workflow->affected($leave, $this->branchScope())->count();

        return redirect()->route('clinic.leave.index', ['leave' => $leave->id])->with('success', $affected > 0
            ? "Leave approved. {$affected} ".($affected === 1 ? 'appointment is affected and needs review.' : 'appointments are affected and need review.')
            : 'Leave approved. No booked appointments are affected.');
    }

    public function reject(Request $request, Leave $leave): RedirectResponse
    {
        $this->assertDecidable($leave);
        $this->workflow->reject($leave, $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null, $request->user());

        return back()->with('success', 'Leave rejected.');
    }

    public function cancel(Request $request, Leave $leave): RedirectResponse
    {
        abort_unless((int) $leave->clinic_id === $this->context->clinic()->id, 404);
        $me = $this->context->currentEmployee();
        $own = $me && $leave->employee_id === $me->id;
        if (! $own) {
            $this->assertDecidable($leave);
        }

        $this->workflow->cancel($leave, $request->user());

        return back()->with('success', 'Leave cancelled.');
    }

    private function assertDecidable(Leave $leave): void
    {
        $clinic = $this->context->clinic();
        abort_unless((int) $leave->clinic_id === $clinic->id, 404);
        abort_unless(request()->user()->hasClinicPermission(ClinicPermission::LeaveApprove), 403);

        $employee = ClinicEmployee::withoutGlobalScopes()->find($leave->employee_id);
        abort_unless($employee && $this->directory->canSee($employee, $clinic, $this->context->membership()), 403);
    }

    /**
     * @return list<int>|null
     */
    private function branchScope(): ?array
    {
        return $this->directory->clinicWide($this->context->membership())
            ? null
            : $this->directory->branches($this->context->clinic(), $this->context->membership())->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  list<int>|null  $branchScope
     * @return list<array<string, mixed>>
     */
    private function affectedList(Leave $leave, ?array $branchScope): array
    {
        return $this->workflow->affected($leave, $branchScope)
            ->with(['patient:id,first_name,last_name,name,phone', 'service:id,name', 'branch:id,name'])
            ->orderBy('scheduled_at')
            ->limit(100)
            ->get()
            ->map(fn (Appointment $appointment) => [
                'id' => $appointment->id,
                'start' => $appointment->scheduled_at->format('Y-m-d\TH:i:s'),
                'patient' => $appointment->patient?->full_name ?? $appointment->prospect_name ?? 'New patient',
                'phone' => $appointment->patient?->phone ?? $appointment->prospect_phone,
                'service' => $appointment->service?->name,
                'branch' => $appointment->branch?->name,
                'branch_id' => $appointment->branch_id,
                'status' => $appointment->status?->value ?? AppointmentStatus::Scheduled->value,
            ])->values()->all();
    }
}
