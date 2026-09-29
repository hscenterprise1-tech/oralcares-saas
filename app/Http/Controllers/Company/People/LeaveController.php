<?php

namespace App\Http\Controllers\Company\People;

use App\Http\Controllers\Controller;
use App\LeaveRequestStatus;
use App\Models\CompanyEmployee;
use App\Models\CompanyLeaveRequest;
use App\Models\CompanyLeaveType;
use App\Services\People\LeaveBalanceService;
use App\Services\People\LeaveRequestService;
use App\Support\PeopleScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HQ leave.
 *
 * Reading everybody's leave takes `view-leave`; raising or cancelling one takes
 * `manage-leave`; deciding on one takes either a real reporting line to the
 * person or `approve-leave`. Nobody approves their own, whichever of those they
 * hold — that check runs first and is not reachable around.
 */
class LeaveController extends Controller
{
    public function __construct(
        protected LeaveRequestService $requests,
        protected LeaveBalanceService $balances,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $self = PeopleScope::employeeFor($user);
        $year = (int) ($request->integer('year') ?: now()->year);

        $query = CompanyLeaveRequest::query()
            ->with(['employee.user:id,name', 'employee.department:id,name', 'leaveType:id,name,code,colour,is_paid', 'decider:id,name'])
            ->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->when($request->integer('employee_id'), fn ($q, $id) => $q->where('employee_id', $id))
            ->orderByDesc('start_date')
            ->orderByDesc('id');

        PeopleScope::restrict($query, $user, 'view-leave');

        $requests = $query->paginate(20)->withQueryString()
            ->through(fn (CompanyLeaveRequest $leave) => $this->row($leave, $user));

        /* The queue: what is actually waiting on the person reading this. */
        $pending = CompanyLeaveRequest::query()
            ->with(['employee.user:id,name', 'leaveType:id,name,colour'])
            ->pending()
            ->orderBy('start_date')
            ->get()
            ->filter(fn (CompanyLeaveRequest $leave) => PeopleScope::approvalRouteFor($user, $leave) !== null)
            ->map(fn (CompanyLeaveRequest $leave) => $this->row($leave, $user))
            ->values();

        return Inertia::render('Company/People/LeaveOverview', [
            'requests' => $requests,
            'awaitingMe' => $pending,
            'filters' => $request->only(['status', 'employee_id', 'year']),
            'year' => $year,
            'types' => $this->typeOptions(),
            'employees' => $this->employeeOptions($user),
            'myBalances' => $self ? $this->balances->forEmployee($self, $year) : [],
            'me' => $self ? ['employee_id' => $self->id, 'name' => $self->user?->name ?? $self->employee_code] : null,
            'totals' => [
                /* Pending within what this reader may see, not company-wide. */
                'pending' => $this->scopedPendingCount($user),
                'awaiting_me' => $pending->count(),
            ],
            'can' => [
                'viewAll' => Gate::allows('view-leave'),
                'manage' => Gate::allows('manage-leave'),
                'approve' => Gate::allows('approve-leave'),
            ],
        ]);
    }

    /** Balances for everyone the reader may see. */
    public function balances(Request $request): Response
    {
        $user = $request->user();
        $year = (int) ($request->integer('year') ?: now()->year);

        $query = CompanyEmployee::query()->with('user:id,name', 'department:id,name');
        PeopleScope::restrict($query, $user, 'view-leave', 'id');

        $rows = $query->get()->map(fn (CompanyEmployee $employee) => [
            'employee_id' => $employee->id,
            'name' => $employee->user?->name ?? $employee->employee_code,
            'employee_code' => $employee->employee_code,
            'department' => $employee->department?->name,
            'balances' => $this->balances->forEmployee($employee, $year),
        ])->values();

        return Inertia::render('Company/People/LeaveBalances', [
            'year' => $year,
            'rows' => $rows,
            'types' => $this->typeOptions(),
            'can' => ['manage' => Gate::allows('manage-leave')],
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('manage-leave');

        $validated = $request->validate([
            'employee_id' => ['required', 'exists:company_employees,id'],
            'leave_type_id' => ['required', 'exists:company_leave_types,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'days' => ['array'],
            'days.*.portion' => ['required', 'string', Rule::in(['full', 'half', 'part'])],
            'days.*.half_period' => ['nullable', Rule::in(['first_half', 'second_half'])],
            'days.*.hours' => ['nullable', 'numeric', 'min:0.25', 'max:24'],
        ]);

        $employee = CompanyEmployee::findOrFail($validated['employee_id']);

        /*
         * Raising leave for somebody else is a manager or HR act. Without
         * `view-leave` you may only raise your own or a direct report's.
         */
        abort_unless(PeopleScope::canView($request->user(), $employee, 'view-leave'), 403);

        try {
            $this->requests->request(
                $employee,
                CompanyLeaveType::findOrFail($validated['leave_type_id']),
                Carbon::parse($validated['start_date']),
                Carbon::parse($validated['end_date']),
                $validated['reason'] ?? null,
                $validated['days'] ?? [],
                $request->user(),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['start_date' => $e->getMessage()]);
        }

        return back()->with('success', 'Leave requested.');
    }

    /**
     * Approve or reject.
     *
     * The route to the decision is resolved server-side and recorded on it —
     * `manager` when the actor is genuinely this person's manager on record,
     * `hr` through the permission fallback. Self-approval returns 403 before
     * either is considered.
     */
    public function decide(Request $request, CompanyLeaveRequest $leave)
    {
        $leave->load('employee');

        $via = PeopleScope::approvalRouteFor($request->user(), $leave);

        abort_unless($via !== null, 403, 'You are not entitled to decide on this request.');

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $validated['decision'] === 'approved'
                ? $this->requests->approve($leave, $request->user(), $via, $validated['note'] ?? null)
                : $this->requests->reject($leave, $request->user(), $via, $validated['note'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        return back()->with('success', "Request {$validated['decision']}.");
    }

    /** Cancel — the requester's own, or anyone's with `manage-leave`. */
    public function cancel(Request $request, CompanyLeaveRequest $leave)
    {
        $leave->load('employee');

        $self = PeopleScope::employeeFor($request->user());
        $isOwn = $self && (int) $self->id === (int) $leave->employee_id;

        abort_unless($isOwn || Gate::allows('manage-leave'), 403);

        try {
            $this->requests->cancel($leave, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('success', 'Request cancelled.');
    }

    /** @return array<string, mixed> */
    protected function row(CompanyLeaveRequest $leave, $user): array
    {
        return [
            'id' => $leave->id,
            'employee_id' => $leave->employee_id,
            'employee_name' => $leave->employee?->user?->name ?? $leave->employee?->employee_code,
            'employee_code' => $leave->employee?->employee_code,
            'department' => $leave->employee?->department?->name,
            'leave_type' => $leave->leaveType?->name,
            'leave_type_colour' => $leave->leaveType?->colour,
            'is_paid' => $leave->leaveType?->is_paid,
            'start_date' => $leave->start_date?->toDateString(),
            'end_date' => $leave->end_date?->toDateString(),
            'total_days' => (float) $leave->total_days,
            'total_part_day_hours' => (float) $leave->total_part_day_hours,
            'reason' => $leave->reason,
            'status' => $leave->status->value,
            'status_label' => $leave->status->label(),
            'decided_by' => $leave->decider?->name,
            'decided_via' => $leave->decided_via,
            'decided_at' => $leave->decided_at?->format('d M Y'),
            'decision_note' => $leave->decision_note,
            /* Server-derived, so the button can never offer what the route refuses. */
            'can_decide' => PeopleScope::approvalRouteFor($user, $leave) !== null
                && $leave->status === LeaveRequestStatus::Pending,
        ];
    }

    protected function scopedPendingCount($user): int
    {
        $query = CompanyLeaveRequest::query()->pending();
        PeopleScope::restrict($query, $user, 'view-leave');

        return $query->count();
    }

    /** @return list<array<string, mixed>> */
    protected function typeOptions(): array
    {
        return CompanyLeaveType::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (CompanyLeaveType $type) => [
                'id' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'colour' => $type->colour,
                'is_paid' => $type->is_paid,
                'allows_half_day' => $type->allows_half_day,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    protected function employeeOptions($user): array
    {
        $query = CompanyEmployee::query()->with('user:id,name');
        PeopleScope::restrict($query, $user, 'view-leave', 'id');

        return $query->get()
            ->map(fn (CompanyEmployee $employee) => [
                'id' => $employee->id,
                'name' => $employee->user?->name ?? $employee->employee_code,
                'employee_code' => $employee->employee_code,
            ])
            ->values()
            ->all();
    }
}
