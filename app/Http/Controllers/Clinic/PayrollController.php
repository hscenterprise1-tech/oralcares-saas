<?php

namespace App\Http\Controllers\Clinic;

use App\ClinicPermission;
use App\Http\Controllers\Controller;
use App\Models\ClinicEmployee;
use App\Models\ClinicPayrollEntry;
use App\Models\ClinicPayrollEntryLine;
use App\Models\ClinicPayrollPeriod;
use App\Models\ClinicSalaryAdvance;
use App\Models\ClinicSalaryStructure;
use App\Services\Business\ClinicExpenses;
use App\Services\Business\ClinicPayroll;
use App\Services\ClinicContext;
use App\Services\Security\SecurityAudit;
use App\Support\ClinicClock;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payroll: who is paid how, each month's pay with its lines, advances, and
 * payslips. Salaries are visible only with `payroll.view`; changing or paying
 * them is `payroll.manage`. Everything is this clinic's only.
 */
class PayrollController extends Controller
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected ClinicPayroll $payroll,
    ) {}

    public function index(Request $request): Response
    {
        $clinic = $this->clinicContext->clinic() ?? abort(403);
        $periods = ClinicPayrollPeriod::query()->where('clinic_id', $clinic->id)->orderByDesc('period_start')->limit(24)->get();
        $selected = $request->integer('period') ? $periods->firstWhere('id', $request->integer('period')) : $periods->first();
        $today = ClinicClock::now();
        $employees = ClinicEmployee::query()->where('clinic_id', $clinic->id)->where('status', 'active')->orderBy('name')->limit(500)->get(['id', 'name', 'staff_type']);
        $current = ClinicSalaryStructure::query()->where('clinic_id', $clinic->id)->where('effective_from', '<=', $today->toDateString())
            ->orderByDesc('effective_from')->get()->unique('clinic_employee_id')->keyBy('clinic_employee_id');
        // Open advances, read once for every row below.
        $advances = ClinicSalaryAdvance::query()->where('clinic_id', $clinic->id)->where('status', ClinicSalaryAdvance::OPEN)->orderBy('given_on')->get()->groupBy('clinic_employee_id');

        return Inertia::render('ClinicPortal/Payroll/Index', [
            'periods' => $periods->map(fn (ClinicPayrollPeriod $p) => ['id' => $p->id, 'label' => $p->period_start->format('F Y'), 'status' => $p->status])->values(),
            'period' => $selected ? [
                'id' => $selected->id,
                'label' => $selected->period_start->format('F Y'),
                'status' => $selected->status,
                'entries' => ClinicPayrollEntry::query()->where('payroll_period_id', $selected->id)
                    ->with(['employee:id,name,staff_type', 'lines' => fn ($q) => $q->orderBy('id')])->orderBy('id')->get()
                    ->map(fn (ClinicPayrollEntry $e) => [
                        'id' => $e->id,
                        'employee' => $e->employee?->name,
                        'designation' => $e->employee?->staff_type,
                        'salary_type' => $e->salary_type,
                        'units' => $e->units !== null ? (float) $e->units : null,
                        'gross' => (float) $e->gross,
                        'deductions' => (float) $e->deductions,
                        'net' => (float) $e->net,
                        'status' => $e->status,
                        'payslip_number' => $e->payslip_number,
                        'paid_at' => $e->paid_at?->toIso8601String(),
                        'payment_method' => $e->payment_method,
                        'lines' => $e->lines->map(fn (ClinicPayrollEntryLine $l) => ['id' => $l->id, 'kind' => $l->kind, 'description' => $l->description, 'amount' => (float) $l->amount, 'automatic' => $l->automatic])->values(),
                        'advances' => $advances->get($e->clinic_employee_id, collect())
                            ->map(fn (ClinicSalaryAdvance $a) => ['id' => $a->id, 'outstanding' => $a->outstanding(), 'given_on' => $a->given_on->toDateString()])->values(),
                        'payslip_url' => route('clinic.payroll.payslip', $e->id, false),
                    ])->values(),
            ] : null,
            'employees' => $employees->map(fn (ClinicEmployee $employee) => [
                'id' => $employee->id,
                'name' => $employee->name,
                'designation' => $employee->staff_type,
                'salary' => ($s = $current->get($employee->id)) ? [
                    'type' => $s->salary_type, 'base_amount' => (float) $s->base_amount, 'commission_percent' => $s->commission_percent !== null ? (float) $s->commission_percent : null,
                    'per_session_rate' => $s->per_session_rate !== null ? (float) $s->per_session_rate : null, 'effective_from' => $s->effective_from->toDateString(),
                ] : null,
                'open_advances' => round((float) $advances->get($employee->id, collect())->sum(fn (ClinicSalaryAdvance $a) => $a->outstanding()), 2),
            ])->values(),
            'next_month' => $today->format('Y-m'),
            'can' => ['manage' => $request->user()->hasClinicPermission(ClinicPermission::PayrollManage)],
            'options' => ['types' => ClinicSalaryStructure::TYPES, 'methods' => ClinicExpenses::METHODS, 'line_kinds' => ClinicPayrollEntryLine::MANUAL],
        ]);
    }

    public function salary(Request $request, int $employee): RedirectResponse
    {
        $record = $this->employee($employee);
        $data = $request->validate([
            'salary_type' => ['required', Rule::in(ClinicSalaryStructure::TYPES)],
            'base_amount' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'per_session_rate' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->attempt(fn () => $this->payroll->setSalary($record, $data, $request->user()), 'Salary saved from '.$data['effective_from'].'.');
    }

    public function advance(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'clinic_employee_id' => ['required', 'integer'], 'amount' => ['required', 'numeric', 'min:1', 'max:99999999'],
            'given_on' => ['required', 'date_format:Y-m-d'], 'payment_method' => ['nullable', Rule::in(ClinicExpenses::METHODS)], 'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->attempt(fn () => $this->payroll->giveAdvance($this->employee((int) $data['clinic_employee_id']), (float) $data['amount'], $data['given_on'], $data['payment_method'] ?? null, $data['notes'] ?? null, $request->user()), 'Advance recorded.');
    }

    public function open(Request $request): RedirectResponse
    {
        $month = $request->validate(['month' => ['required', 'date_format:Y-m']])['month'];

        try {
            $period = $this->payroll->open($this->clinicContext->clinic(), $month, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['payroll' => $e->getMessage()]);
        }

        return redirect()->route('clinic.payroll.index', ['period' => $period->id])->with('success', 'Payroll opened as a draft.');
    }

    public function recalculate(int $entry): RedirectResponse
    {
        return $this->attempt(fn () => $this->payroll->recalculate($this->entry($entry)), 'Worked out again from the records.');
    }

    public function units(Request $request, int $entry): RedirectResponse
    {
        $units = (float) $request->validate(['units' => ['required', 'numeric', 'min:0', 'max:744']])['units'];

        return $this->attempt(fn () => $this->payroll->setUnits($this->entry($entry), $units), 'Updated.');
    }

    public function line(Request $request, int $entry): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(ClinicPayrollEntryLine::MANUAL)], 'description' => ['nullable', 'string', 'max:160'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'], 'salary_advance_id' => ['nullable', 'integer'],
        ]);

        return $this->attempt(fn () => $this->payroll->addLine($this->entry($entry), $data['kind'], (string) ($data['description'] ?? ''), (float) $data['amount'], $data['salary_advance_id'] ?? null, $request->user()), 'Line added.');
    }

    public function removeLine(Request $request, int $entry, int $line): RedirectResponse
    {
        $record = ClinicPayrollEntryLine::query()->where('payroll_entry_id', $this->entry($entry)->id)->findOrFail($line);

        return $this->attempt(fn () => $this->payroll->removeLine($record, $request->user()), 'Line removed.');
    }

    public function finalise(Request $request, int $period): RedirectResponse
    {
        $record = ClinicPayrollPeriod::query()->where('clinic_id', $this->clinicContext->clinic()?->id)->findOrFail($period);

        return $this->attempt(fn () => $this->payroll->finalise($record, $request->user()), 'Payroll finalised. Payslips are numbered and can no longer change.');
    }

    public function pay(Request $request, int $entry): RedirectResponse
    {
        $data = $request->validate(['method' => ['required', Rule::in(ClinicExpenses::METHODS)], 'reference' => ['nullable', 'string', 'max:100']]);

        return $this->attempt(fn () => $this->payroll->markPaid($this->entry($entry), $data['method'], $data['reference'] ?? null, $request->user()), 'Marked paid.');
    }

    /** A printable payslip, like a printed bill: a plain page, no portal chrome. */
    public function payslip(int $entry): HttpResponse
    {
        $record = $this->entry($entry)->load(['employee:id,name', 'lines' => fn ($q) => $q->orderBy('id'), 'period']);
        app(SecurityAudit::class)->record('payroll.payslip_viewed', $record, ['payslip' => $record->payslip_number], ['clinic_id' => $record->clinic_id]);

        return response()->view('clinic.payslip_printable', ['entry' => $record, 'clinic' => $this->clinicContext->clinic()]);
    }

    private function employee(int $id): ClinicEmployee
    {
        return ClinicEmployee::query()->where('clinic_id', $this->clinicContext->clinic()?->id)->findOrFail($id);
    }

    private function entry(int $id): ClinicPayrollEntry
    {
        return ClinicPayrollEntry::query()->where('clinic_id', $this->clinicContext->clinic()?->id)->findOrFail($id);
    }

    private function attempt(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (DomainException $e) {
            return back()->withErrors(['payroll' => $e->getMessage()]);
        }

        return back()->with('success', $success);
    }
}
