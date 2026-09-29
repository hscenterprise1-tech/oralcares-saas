<?php

namespace App\Http\Controllers\Company\People;

use App\Http\Controllers\Controller;
use App\Models\CompanyPayroll;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\PayrollPeriodStatus;
use App\Services\People\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payroll runs.
 *
 * Reading takes `view-payroll`; changing anything takes `manage-payroll`;
 * moving a run past approval takes `approve-payroll`. That separation is the
 * point — `POST /company/finance/payroll` used to be gated on `view-payroll`,
 * so anybody who could look at the payroll could also write a record and mark
 * it paid for any amount.
 */
class PayrollController extends Controller
{
    public function __construct(protected PayrollService $payroll) {}

    public function index(Request $request): Response
    {
        Gate::authorize('view-payroll');

        $periods = PayrollPeriod::query()
            ->withCount('entries')
            ->orderByDesc('period_start')
            ->paginate(12)
            ->withQueryString()
            ->through(fn (PayrollPeriod $period) => $this->periodRow($period));

        return Inertia::render('Company/People/PayrollWorkspace', [
            'periods' => $periods,
            'can' => [
                'manage' => Gate::allows('manage-payroll'),
                'approve' => Gate::allows('approve-payroll'),
            ],
            /*
             * The eighteen rows that predate all of this. Shown, counted and
             * never touched: they carry no period, no components and no
             * approval, because none of that existed when they were written.
             */
            'legacy' => [
                'count' => CompanyPayroll::count(),
                'total_net' => (float) CompanyPayroll::sum('net_amount'),
            ],
        ]);
    }

    public function show(Request $request, PayrollPeriod $period): Response
    {
        Gate::authorize('view-payroll');

        $period->load(['entries.lines', 'creator:id,name', 'approver:id,name']);

        return Inertia::render('Company/People/PayrollPeriodDetail', [
            'period' => $this->periodRow($period) + [
                'payment_reference' => $period->payment_reference,
                'payment_notes' => $period->payment_notes,
                'trail' => [
                    'created' => $this->stamp($period->created_at, $period->creator?->name),
                    'calculated' => $this->stamp($period->calculated_at, null),
                    'submitted' => $this->stamp($period->submitted_at, null),
                    'approved' => $this->stamp($period->approved_at, $period->approver?->name),
                    'locked' => $this->stamp($period->locked_at, null),
                    'paid' => $this->stamp($period->paid_at, null),
                ],
            ],
            'entries' => $period->entries->map(fn (PayrollEntry $entry) => [
                'id' => $entry->id,
                'employee_code' => $entry->employee_code,
                'employee_name' => $entry->employee_name,
                'base_salary' => (float) $entry->base_salary,
                'gross_amount' => (float) $entry->gross_amount,
                'deduction_amount' => (float) $entry->deduction_amount,
                'net_amount' => (float) $entry->net_amount,
            ])->values(),
            'allowedNext' => collect($period->status->allowedNext())
                ->map(fn (PayrollPeriodStatus $s) => ['value' => $s->value, 'label' => $s->label()])
                ->values(),
            'can' => [
                'manage' => Gate::allows('manage-payroll'),
                'approve' => Gate::allows('approve-payroll'),
            ],
        ]);
    }

    /** One employee's payslip: the snapshot, rendered. */
    public function payslip(Request $request, PayrollPeriod $period, PayrollEntry $entry): Response
    {
        Gate::authorize('view-payroll');
        abort_unless((int) $entry->period_id === (int) $period->id, 404);

        $entry->load('lines');

        return Inertia::render('Company/People/Payslip', [
            'period' => [
                'id' => $period->id,
                'code' => $period->code,
                'label' => $period->label,
                'period_start' => $period->period_start?->toDateString(),
                'period_end' => $period->period_end?->toDateString(),
                'status' => $period->status->value,
                'status_label' => $period->status->label(),
            ],
            'entry' => [
                'id' => $entry->id,
                'employee_code' => $entry->employee_code,
                'employee_name' => $entry->employee_name,
                'base_salary' => (float) $entry->base_salary,
                'gross_amount' => (float) $entry->gross_amount,
                'deduction_amount' => (float) $entry->deduction_amount,
                'net_amount' => (float) $entry->net_amount,
            ],
            'earnings' => $this->lines($entry, 'earning'),
            'deductions' => $this->lines($entry, 'deduction'),
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('manage-payroll');

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ]);

        /*
         * One run per window. The unique index enforces it; checking first
         * turns a database error into a message about the thing that went
         * wrong.
         */
        /*
         * whereDate, not where: `period_start` is cast to a date, so a plain
         * where() binds a full timestamp and never matches the date column —
         * the check passed straight through to the unique index and returned a
         * 500 instead of a validation message.
         */
        $clash = PayrollPeriod::whereDate('period_start', $validated['period_start'])
            ->whereDate('period_end', $validated['period_end'])
            ->exists();

        if ($clash) {
            throw ValidationException::withMessages([
                'period_start' => 'A payroll run already covers that window.',
            ]);
        }

        $period = PayrollPeriod::create($validated + [
            'code' => 'PR-'.Carbon::parse($validated['period_start'])->format('Ymd').'-'.Str::upper(Str::random(4)),
            'status' => PayrollPeriodStatus::Draft,
            'created_by' => $request->user()?->id,
        ]);

        return redirect()
            ->route('company.people.payroll.show', $period)
            ->with('success', "Payroll run {$period->code} created.");
    }

    public function calculate(Request $request, PayrollPeriod $period)
    {
        Gate::authorize('manage-payroll');

        try {
            $result = $this->payroll->calculate($period, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        $message = "Calculated {$result['entries']} ".($result['entries'] === 1 ? 'entry' : 'entries').'.';

        if ($result['skipped'] !== []) {
            $message .= ' Skipped '.count($result['skipped']).' with no compensation on record: '.implode(', ', $result['skipped']).'.';
        }

        return back()->with('success', $message);
    }

    public function transition(Request $request, PayrollPeriod $period)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(PayrollPeriodStatus::class)],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'payment_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $target = PayrollPeriodStatus::from($validated['status']);

        /*
         * Approving is its own authority. Everything else on a run is
         * `manage-payroll`, so the person who calculates a run does not
         * automatically get to sign it off.
         */
        Gate::authorize($target === PayrollPeriodStatus::Approved ? 'approve-payroll' : 'manage-payroll');

        try {
            $this->payroll->transition($period, $target, $request->user(), $validated);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('success', "Payroll run is now {$target->label()}.");
    }

    /** @return array<string, mixed> */
    protected function periodRow(PayrollPeriod $period): array
    {
        return [
            'id' => $period->id,
            'code' => $period->code,
            'label' => $period->label,
            'period_start' => $period->period_start?->toDateString(),
            'period_end' => $period->period_end?->toDateString(),
            'status' => $period->status->value,
            'status_label' => $period->status->label(),
            'is_mutable' => $period->isMutable(),
            'total_gross' => (float) $period->total_gross,
            'total_deductions' => (float) $period->total_deductions,
            'total_net' => (float) $period->total_net,
            'entry_count' => (int) ($period->entries_count ?? $period->entry_count),
        ];
    }

    /** @return array<string, string>|null */
    protected function stamp(?\DateTimeInterface $at, ?string $who): ?array
    {
        return $at ? ['at' => $at->format('d M Y, H:i'), 'by' => $who] : null;
    }

    /** @return list<array<string, mixed>> */
    protected function lines(PayrollEntry $entry, string $type): array
    {
        return $entry->lines
            ->filter(fn ($line) => ($line->type->value ?? (string) $line->type) === $type)
            ->map(fn ($line) => [
                'code' => $line->component_code,
                'name' => $line->component_name,
                'amount' => (float) $line->amount,
            ])
            ->values()
            ->all();
    }
}
