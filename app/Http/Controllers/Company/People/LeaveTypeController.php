<?php

namespace App\Http\Controllers\Company\People;

use App\Http\Controllers\Controller;
use App\LeaveBalanceEntryType;
use App\Models\CompanyEmployee;
use App\Models\CompanyLeaveType;
use App\Services\People\LeaveBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The leave types this company offers, and the entries behind a balance.
 *
 * Nothing is seeded. What leave a company offers is theirs to define, and the
 * clinic migration's "casual, sick, vacation, emergency, custom" was a comment
 * — never an enum, never validated, and never this company's decision.
 *
 * `is_paid` is configuration that changes no figure anywhere today. It is the
 * seam payroll will read when somebody decides unpaid leave should affect pay;
 * until then nothing consumes it.
 */
class LeaveTypeController extends Controller
{
    public function __construct(protected LeaveBalanceService $balances) {}

    public function index(Request $request): Response
    {
        Gate::authorize('view-leave');

        $types = CompanyLeaveType::query()
            ->withCount('requests')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (CompanyLeaveType $type) => [
                'id' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'description' => $type->description,
                'is_paid' => $type->is_paid,
                'requires_approval' => $type->requires_approval,
                'allows_half_day' => $type->allows_half_day,
                'is_active' => $type->is_active,
                'sort_order' => $type->sort_order,
                'colour' => $type->colour,
                'requests_count' => $type->requests_count,
            ])
            ->values();

        return Inertia::render('Company/People/LeaveTypes', [
            'types' => $types,
            'can' => ['manage' => Gate::allows('manage-leave')],
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('manage-leave');

        $validated = $this->validated($request);

        CompanyLeaveType::create($validated + ['created_by' => $request->user()?->id]);

        return back()->with('success', "{$validated['name']} added.");
    }

    public function update(Request $request, CompanyLeaveType $leaveType)
    {
        Gate::authorize('manage-leave');

        $leaveType->update($this->validated($request, $leaveType));

        return back()->with('success', "{$leaveType->name} updated.");
    }

    /**
     * Grant, carry forward or adjust days on a balance.
     *
     * This is the accrual and carry-forward foundation in use: a person records
     * the movement and it becomes a ledger entry. Nothing accrues on its own —
     * there is no rate, no frequency and no cap anywhere in this system,
     * because none has been decided.
     */
    public function recordBalanceEntry(Request $request)
    {
        Gate::authorize('manage-leave');

        $validated = $request->validate([
            'employee_id' => ['required', 'exists:company_employees,id'],
            'leave_type_id' => ['required', 'exists:company_leave_types,id'],
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'entry_type' => ['required', Rule::in([
                LeaveBalanceEntryType::Opening->value,
                LeaveBalanceEntryType::Accrual->value,
                LeaveBalanceEntryType::CarryForward->value,
                LeaveBalanceEntryType::Adjustment->value,
            ])],
            'days' => ['required', 'numeric'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $balance = $this->balances->balanceFor(
            CompanyEmployee::findOrFail($validated['employee_id']),
            CompanyLeaveType::findOrFail($validated['leave_type_id']),
            (int) $validated['period_year'],
        );

        $this->balances->record(
            $balance,
            LeaveBalanceEntryType::from($validated['entry_type']),
            (float) $validated['days'],
            Carbon::create((int) $validated['period_year'], 1, 1),
            $validated['reason'] ?? null,
            null,
            $request->user(),
        );

        return back()->with('success', 'Balance entry recorded.');
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?CompanyLeaveType $existing = null): array
    {
        return $request->validate([
            'code' => [
                'required', 'string', 'max:32',
                Rule::unique('company_leave_types', 'code')->ignore($existing?->id),
            ],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_paid' => ['boolean'],
            'requires_approval' => ['boolean'],
            'allows_half_day' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'colour' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);
    }
}
