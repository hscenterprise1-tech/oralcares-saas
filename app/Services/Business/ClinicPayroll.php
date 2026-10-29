<?php

namespace App\Services\Business;

use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicPayrollEntry;
use App\Models\ClinicPayrollEntryLine;
use App\Models\ClinicPayrollPeriod;
use App\Models\ClinicSalaryAdvance;
use App\Models\ClinicSalaryStructure;
use App\Models\Leave;
use App\Models\User;
use App\Services\OwnerMode\OwnerMode;
use App\Services\Security\SecurityAudit;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The one writer of clinic pay.
 *
 * A salary structure (a new row per change) says how someone is paid. A
 * month's payroll is opened as a draft: one entry per employee with a
 * structure, its lines worked out from what the clinic recorded — base pay,
 * per-session pay for completed sessions, commission on payments collected
 * on the practitioner's bills, approved unpaid leave — plus what a person
 * adds (incentives, allowances, deductions, advance recovery). Finalising
 * locks it and numbers the payslips; paying records how, and recovers the
 * advances it deducted.
 *
 * This is internal pay control. Statutory deductions (PF, ESI, TDS,
 * professional tax) are not calculated; a clinic adds them as deduction lines.
 */
class ClinicPayroll
{
    public function __construct(
        protected SecurityAudit $audit,
        protected OwnerMode $ownerMode,
    ) {}

    /**
     * @param  array{salary_type: string, base_amount: float|string, commission_percent?: float|string|null, per_session_rate?: float|string|null, effective_from: string, notes?: ?string}  $data
     */
    public function setSalary(ClinicEmployee $employee, array $data, User $by): ClinicSalaryStructure
    {
        $this->ownerMode->requireForSensitiveAction($by, 'payroll.salary', OwnerMode::STANDARD, ['employee_id' => $employee->id]);
        if (! in_array($data['salary_type'], ClinicSalaryStructure::TYPES, true)) {
            throw new DomainException('Choose how this person is paid.');
        }
        if ((float) $data['base_amount'] < 0 || (float) ($data['commission_percent'] ?? 0) < 0 || (float) ($data['commission_percent'] ?? 0) > 100) {
            throw new DomainException('Amounts must be positive and a commission at most 100%.');
        }

        $structure = ClinicSalaryStructure::query()->withoutGlobalScopes()->updateOrCreate(
            ['clinic_employee_id' => $employee->id, 'effective_from' => $data['effective_from']],
            [
                'clinic_id' => $employee->clinic_id,
                'salary_type' => $data['salary_type'],
                'base_amount' => round((float) $data['base_amount'], 2),
                'commission_percent' => filled($data['commission_percent'] ?? null) ? round((float) $data['commission_percent'], 2) : null,
                'per_session_rate' => filled($data['per_session_rate'] ?? null) ? round((float) $data['per_session_rate'], 2) : null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $by->id,
            ],
        );
        // The amount itself is not written to the trail: salaries are private.
        $this->audit->record('payroll.salary_set', $structure, ['employee_id' => $employee->id, 'type' => $structure->salary_type, 'effective_from' => $structure->effective_from->toDateString()], ['clinic_id' => $employee->clinic_id]);

        return $structure;
    }

    public function structureOn(ClinicEmployee $employee, CarbonImmutable $day): ?ClinicSalaryStructure
    {
        return ClinicSalaryStructure::query()->withoutGlobalScopes()->where('clinic_employee_id', $employee->id)
            ->where('effective_from', '<=', $day->toDateString())->orderByDesc('effective_from')->first();
    }

    public function giveAdvance(ClinicEmployee $employee, float $amount, string $givenOn, ?string $method, ?string $notes, User $by): ClinicSalaryAdvance
    {
        $this->ownerMode->requireForSensitiveAction($by, 'payroll.advance', OwnerMode::STANDARD, ['employee_id' => $employee->id]);
        if ($amount <= 0) {
            throw new DomainException('An advance must be more than zero.');
        }
        $advance = ClinicSalaryAdvance::query()->withoutGlobalScopes()->create([
            'clinic_id' => $employee->clinic_id, 'clinic_employee_id' => $employee->id, 'amount' => round($amount, 2), 'given_on' => $givenOn,
            'payment_method' => $method, 'notes' => $notes, 'status' => ClinicSalaryAdvance::OPEN, 'created_by' => $by->id,
        ]);
        $this->audit->record('payroll.advance_given', $advance, ['employee_id' => $employee->id], ['clinic_id' => $employee->clinic_id]);

        return $advance;
    }

    /** Open a month's payroll as a draft, with an entry for everyone who has a salary. */
    public function open(Clinic $clinic, string $month, User $by): ClinicPayrollPeriod
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', "{$month}-01")->startOfDay();
        $end = $start->endOfMonth()->startOfDay();

        return DB::transaction(function () use ($clinic, $start, $end, $by) {
            if (ClinicPayrollPeriod::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('period_start', $start->toDateString())->exists()) {
                throw new DomainException('This month\'s payroll is already open.');
            }
            $period = ClinicPayrollPeriod::query()->withoutGlobalScopes()->create([
                'clinic_id' => $clinic->id, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(), 'status' => ClinicPayrollPeriod::DRAFT, 'created_by' => $by->id,
            ]);
            ClinicEmployee::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('status', 'active')->orderBy('id')
                ->chunkById(200, function ($employees) use ($period, $end) {
                    foreach ($employees as $employee) {
                        $structure = $this->structureOn($employee, $end);
                        if ($structure === null) {
                            continue;
                        }
                        $entry = ClinicPayrollEntry::query()->withoutGlobalScopes()->create([
                            'clinic_id' => $period->clinic_id, 'payroll_period_id' => $period->id, 'clinic_employee_id' => $employee->id,
                            'salary_structure_id' => $structure->id, 'salary_type' => $structure->salary_type,
                            'units' => in_array($structure->salary_type, ['daily', 'hourly'], true) ? $this->defaultUnits($structure, $employee, $period) : null,
                            'status' => 'draft',
                        ]);
                        $this->recalculate($entry);
                    }
                });
            $this->audit->record('payroll.period_opened', $period, ['month' => $start->format('Y-m')], ['clinic_id' => $clinic->id]);

            return $period;
        });
    }

    /** Work the automatic lines out again from the records; the lines a person added stay. */
    public function recalculate(ClinicPayrollEntry $entry): ClinicPayrollEntry
    {
        $period = ClinicPayrollPeriod::query()->withoutGlobalScopes()->findOrFail($entry->payroll_period_id);
        $this->assertDraft($period);
        $structure = ClinicSalaryStructure::query()->withoutGlobalScopes()->findOrFail($entry->salary_structure_id);
        $start = CarbonImmutable::parse($period->period_start);
        $end = CarbonImmutable::parse($period->period_end);
        $daysInPeriod = (int) $start->diffInDays($end) + 1;

        ClinicPayrollEntryLine::query()->where('payroll_entry_id', $entry->id)->where('automatic', true)->delete();
        $line = fn (string $kind, string $description, float $amount) => abs($amount) < 0.005 ? null : ClinicPayrollEntryLine::query()->create([
            'payroll_entry_id' => $entry->id, 'clinic_id' => $entry->clinic_id, 'kind' => $kind, 'description' => $description, 'amount' => round($amount, 2), 'automatic' => true,
        ]);

        $base = (float) $structure->base_amount;
        match ($structure->salary_type) {
            'monthly' => $line('base', 'Monthly salary', $base),
            'daily' => $line('base', (float) $entry->units.' days × '.number_format($base, 2), $base * (float) $entry->units),
            'hourly' => $line('base', (float) $entry->units.' hours × '.number_format($base, 2), $base * (float) $entry->units),
            default => null,
        };

        if ($structure->salary_type === 'monthly') {
            $unpaid = $this->unpaidLeaveDays($entry->clinic_employee_id, $start, $end);
            if ($unpaid > 0) {
                $line('unpaid_leave', "{$unpaid} ".($unpaid === 1 ? 'day' : 'days').' unpaid leave', -($base / $daysInPeriod) * $unpaid);
            }
        }

        $rate = (float) ($structure->per_session_rate ?? ($structure->salary_type === 'per_session' ? $structure->base_amount : 0));
        if ($rate > 0) {
            $sessions = DB::table('physio_sessions')->where('clinic_id', $entry->clinic_id)->where('therapist_id', $entry->clinic_employee_id)
                ->where('status', 'completed')->whereNull('reversed_at')
                ->where('completed_at', '>=', $start->toDateTimeString())->where('completed_at', '<', $end->addDay()->toDateTimeString())->count();
            $line('session_pay', "{$sessions} completed ".($sessions === 1 ? 'session' : 'sessions').' × '.number_format($rate, 2), $sessions * $rate);
        }

        if ((float) $structure->commission_percent > 0) {
            $collected = (float) DB::table('patient_billing_payments')->join('patient_billings', 'patient_billings.id', '=', 'patient_billing_payments.patient_billing_id')
                ->where('patient_billing_payments.clinic_id', $entry->clinic_id)->where('patient_billings.doctor_id', $entry->clinic_employee_id)
                ->where('patient_billing_payments.paid_at', '>=', $start->toDateTimeString())->where('patient_billing_payments.paid_at', '<', $end->addDay()->toDateTimeString())
                ->sum('patient_billing_payments.amount');
            $refunded = (float) DB::table('patient_billing_refunds')->join('patient_billings', 'patient_billings.id', '=', 'patient_billing_refunds.patient_billing_id')
                ->where('patient_billing_refunds.clinic_id', $entry->clinic_id)->where('patient_billings.doctor_id', $entry->clinic_employee_id)
                ->where('patient_billing_refunds.created_at', '>=', $start->toDateTimeString())->where('patient_billing_refunds.created_at', '<', $end->addDay()->toDateTimeString())
                ->sum('patient_billing_refunds.amount');
            $net = max(0.0, $collected - $refunded);
            $line('commission', number_format((float) $structure->commission_percent, 2).'% of ₹'.number_format($net, 2).' collected', $net * (float) $structure->commission_percent / 100);
        }

        return $this->total($entry);
    }

    public function setUnits(ClinicPayrollEntry $entry, float $units): ClinicPayrollEntry
    {
        if (! in_array($entry->salary_type, ['daily', 'hourly'], true) || $units < 0 || $units > 744) {
            throw new DomainException('Days or hours apply only to daily or hourly pay, and must be realistic.');
        }
        $entry->update(['units' => round($units, 2)]);

        return $this->recalculate($entry);
    }

    public function addLine(ClinicPayrollEntry $entry, string $kind, string $description, float $amount, ?int $advanceId, User $by): ClinicPayrollEntryLine
    {
        $this->assertDraft(ClinicPayrollPeriod::query()->withoutGlobalScopes()->findOrFail($entry->payroll_period_id));
        if (! in_array($kind, ClinicPayrollEntryLine::MANUAL, true) || $amount <= 0) {
            throw new DomainException('Add an incentive, allowance, deduction or advance recovery, more than zero.');
        }
        $advance = null;
        if ($kind === 'advance_recovery') {
            $advance = ClinicSalaryAdvance::query()->withoutGlobalScopes()->where('clinic_employee_id', $entry->clinic_employee_id)->where('status', ClinicSalaryAdvance::OPEN)->find($advanceId)
                ?? throw new DomainException('Choose one of this person\'s open advances.');
            if ($amount > $advance->outstanding() + 0.001) {
                throw new DomainException('That is more than is left on the advance.');
            }
        }
        $signed = in_array($kind, ['deduction', 'advance_recovery'], true) ? -$amount : $amount;
        $line = ClinicPayrollEntryLine::query()->create([
            'payroll_entry_id' => $entry->id, 'clinic_id' => $entry->clinic_id, 'kind' => $kind, 'description' => mb_substr(trim($description) ?: ucfirst(str_replace('_', ' ', $kind)), 0, 160),
            'amount' => round($signed, 2), 'automatic' => false, 'salary_advance_id' => $advance?->id,
        ]);
        $this->total($entry);
        $this->audit->record('payroll.line_added', $entry, ['kind' => $kind], ['clinic_id' => $entry->clinic_id]);

        return $line;
    }

    public function removeLine(ClinicPayrollEntryLine $line, User $by): void
    {
        $entry = ClinicPayrollEntry::query()->withoutGlobalScopes()->findOrFail($line->payroll_entry_id);
        $this->assertDraft(ClinicPayrollPeriod::query()->withoutGlobalScopes()->findOrFail($entry->payroll_period_id));
        if ($line->automatic) {
            throw new DomainException('Worked-out lines change with the records, not by hand.');
        }
        $line->delete();
        $this->total($entry);
        $this->audit->record('payroll.line_removed', $entry, ['kind' => $line->kind], ['clinic_id' => $entry->clinic_id]);
    }

    /** Lock the period and number its payslips. */
    public function finalise(ClinicPayrollPeriod $period, User $by): ClinicPayrollPeriod
    {
        $this->ownerMode->requireForSensitiveAction($by, 'payroll.finalise', OwnerMode::FRESH, ['payroll_period_id' => $period->id]);

        return DB::transaction(function () use ($period, $by) {
            $locked = ClinicPayrollPeriod::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($period->id);
            $this->assertDraft($locked);
            $entries = ClinicPayrollEntry::query()->withoutGlobalScopes()->where('payroll_period_id', $locked->id)->orderBy('id')->get();
            if ($entries->contains(fn (ClinicPayrollEntry $e) => (float) $e->net < 0)) {
                throw new DomainException('Someone\'s pay comes to less than zero. Fix their deductions first.');
            }
            $prefix = 'PS-'.CarbonImmutable::parse($locked->period_start)->format('Ym').'-';
            foreach ($entries->values() as $index => $entry) {
                $entry->update(['status' => 'approved', 'payslip_number' => $prefix.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT)]);
            }
            $locked->update(['status' => ClinicPayrollPeriod::FINALISED, 'finalised_at' => now(), 'finalised_by' => $by->id]);
            $this->audit->record('payroll.period_finalised', $locked, ['entries' => $entries->count()], ['clinic_id' => $locked->clinic_id]);

            return $locked;
        });
    }

    /** Record one person's pay as paid, and recover the advances it deducted. */
    public function markPaid(ClinicPayrollEntry $entry, string $method, ?string $reference, User $by): ClinicPayrollEntry
    {
        $this->ownerMode->requireForSensitiveAction($by, 'payroll.pay', OwnerMode::FRESH, ['payroll_entry_id' => $entry->id]);
        if (! in_array($method, ClinicExpenses::METHODS, true)) {
            throw new DomainException('Choose how it was paid.');
        }

        return DB::transaction(function () use ($entry, $method, $reference) {
            $locked = ClinicPayrollEntry::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($entry->id);
            if ($locked->status !== 'approved') {
                throw new DomainException('Only a finalised, unpaid payslip can be paid.');
            }
            $locked->update(['status' => 'paid', 'paid_at' => now(), 'payment_method' => $method, 'reference' => $reference]);
            foreach (ClinicPayrollEntryLine::query()->where('payroll_entry_id', $locked->id)->whereNotNull('salary_advance_id')->get() as $recovery) {
                $advance = ClinicSalaryAdvance::query()->withoutGlobalScopes()->lockForUpdate()->find($recovery->salary_advance_id);
                if ($advance) {
                    $recovered = round((float) $advance->recovered_amount + abs((float) $recovery->amount), 2);
                    $advance->update(['recovered_amount' => $recovered, 'status' => $recovered + 0.001 >= (float) $advance->amount ? ClinicSalaryAdvance::RECOVERED : ClinicSalaryAdvance::OPEN]);
                }
            }
            $period = ClinicPayrollPeriod::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($locked->payroll_period_id);
            if (! ClinicPayrollEntry::query()->withoutGlobalScopes()->where('payroll_period_id', $period->id)->where('status', '!=', 'paid')->exists()) {
                $period->update(['status' => ClinicPayrollPeriod::PAID]);
            }
            $this->audit->record('payroll.paid', $locked, ['method' => $method], ['clinic_id' => $locked->clinic_id]);

            return $locked;
        });
    }

    private function total(ClinicPayrollEntry $entry): ClinicPayrollEntry
    {
        $lines = ClinicPayrollEntryLine::query()->where('payroll_entry_id', $entry->id)->pluck('amount')->map(fn ($a) => (float) $a);
        $gross = round($lines->filter(fn ($a) => $a > 0)->sum(), 2);
        $deductions = round(abs($lines->filter(fn ($a) => $a < 0)->sum()), 2);
        $entry->update(['gross' => $gross, 'deductions' => $deductions, 'net' => round($gross - $deductions, 2)]);

        return $entry;
    }

    /** Days in the period, less approved unpaid leave, for daily pay; 0 hours for hourly (entered by hand). */
    private function defaultUnits(ClinicSalaryStructure $structure, ClinicEmployee $employee, ClinicPayrollPeriod $period): float
    {
        if ($structure->salary_type === 'hourly') {
            return 0;
        }
        $start = CarbonImmutable::parse($period->period_start);
        $end = CarbonImmutable::parse($period->period_end);

        return max(0, ((int) $start->diffInDays($end) + 1) - $this->unpaidLeaveDays($employee->id, $start, $end));
    }

    private function unpaidLeaveDays(int $employeeId, CarbonImmutable $start, CarbonImmutable $end): int
    {
        return (int) Leave::query()->withoutGlobalScopes()->where('employee_id', $employeeId)->where('leave_type', 'unpaid')->where('status', Leave::APPROVED)
            ->where('start_date', '<=', $end->toDateString())->where('end_date', '>=', $start->toDateString())->get(['start_date', 'end_date'])
            ->sum(function (Leave $leave) use ($start, $end) {
                $from = CarbonImmutable::parse($leave->start_date)->max($start);
                $to = CarbonImmutable::parse($leave->end_date)->min($end);

                return $from->greaterThan($to) ? 0 : (int) $from->diffInDays($to) + 1;
            });
    }

    private function assertDraft(ClinicPayrollPeriod $period): void
    {
        if ($period->status !== ClinicPayrollPeriod::DRAFT) {
            throw new DomainException('This payroll is finalised; it can no longer change.');
        }
    }
}
