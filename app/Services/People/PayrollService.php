<?php

namespace App\Services\People;

use App\CompensationCalculationType;
use App\CompensationComponentType;
use App\Models\CompanyEmployee;
use App\Models\EmployeeCompensation;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\PayrollPeriodStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Running payroll.
 *
 * Two rules carry this class. The first is that calculation takes a snapshot:
 * every figure a run needs is copied into the entry and its lines, so nothing a
 * closed run reports can be changed by editing a salary afterwards. The second
 * is that the status order lives in `PayrollPeriodStatus` and nowhere else, so
 * there is no path that reaches Paid without passing approval.
 *
 * What this deliberately does not know: any statutory rate, any attendance or
 * leave input, and any rule about overtime. HQ attendance does not exist yet —
 * the clinic-side `leaves` and `shifts` tables are scoped to a clinic and
 * describe clinic staff, not this company's employees.
 */
class PayrollService
{
    /**
     * The compensation in force for an employee on a given date.
     *
     * The latest arrangement whose window covers the date. A run reads this
     * once, at calculation, and never again.
     */
    public function compensationFor(CompanyEmployee $employee, \DateTimeInterface $on): ?EmployeeCompensation
    {
        return EmployeeCompensation::query()
            ->with('lines.component')
            ->where('employee_id', $employee->id)
            ->whereDate('effective_from', '<=', $on)
            ->where(function ($query) use ($on) {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $on);
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Record a new compensation arrangement, closing the one it supersedes.
     *
     * The previous row is dated out rather than edited, which is what keeps a
     * historical run readable after somebody's pay changes.
     *
     * @param  array<int, array{component_id: int, value: numeric}>  $lines
     */
    public function setCompensation(CompanyEmployee $employee, array $data, array $lines = [], ?User $actor = null): EmployeeCompensation
    {
        return DB::transaction(function () use ($employee, $data, $lines, $actor) {
            $effectiveFrom = Carbon::parse($data['effective_from']);

            /*
             * Close whatever is currently open, the day before the new one
             * starts. Two open arrangements would make "what do they earn"
             * ambiguous.
             */
            EmployeeCompensation::query()
                ->where('employee_id', $employee->id)
                ->whereNull('effective_to')
                ->whereDate('effective_from', '<', $effectiveFrom)
                ->update(['effective_to' => $effectiveFrom->copy()->subDay()->toDateString()]);

            $compensation = EmployeeCompensation::create([
                'employee_id' => $employee->id,
                'base_salary' => $data['base_salary'],
                'effective_from' => $effectiveFrom->toDateString(),
                'effective_to' => $data['effective_to'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor?->id,
            ]);

            foreach ($lines as $line) {
                $compensation->lines()->create([
                    'component_id' => $line['component_id'],
                    'value' => $line['value'],
                ]);
            }

            return $compensation->fresh('lines.component');
        });
    }

    /**
     * Build every entry for a run from the compensation in force on its end date.
     *
     * Recalculating replaces the run's entries wholesale — legitimate while a
     * run is still being prepared, and impossible once it is locked.
     *
     * @return array{entries: int, skipped: list<string>}
     */
    public function calculate(PayrollPeriod $period, ?User $actor = null): array
    {
        $this->assertMutable($period);

        return DB::transaction(function () use ($period, $actor) {
            $period->entries()->delete();

            $skipped = [];
            $gross = 0.0;
            $deductions = 0.0;

            $employees = CompanyEmployee::with('user')->get();

            foreach ($employees as $employee) {
                $compensation = $this->compensationFor($employee, $period->period_end);

                if (! $compensation) {
                    /*
                     * Reported, not guessed. An employee with no arrangement in
                     * force is an exception a person has to resolve — inventing
                     * a salary for them would be the worst possible default.
                     */
                    $skipped[] = $employee->user?->name ?? $employee->employee_code;

                    continue;
                }

                $entry = $this->buildEntry($period, $employee, $compensation);

                $gross += (float) $entry->gross_amount;
                $deductions += (float) $entry->deduction_amount;
            }

            $period->update([
                'status' => PayrollPeriodStatus::Calculated,
                'total_gross' => round($gross, 2),
                'total_deductions' => round($deductions, 2),
                'total_net' => round($gross - $deductions, 2),
                'entry_count' => $period->entries()->count(),
                'calculated_at' => now(),
                'calculated_by' => $actor?->id,
            ]);

            return ['entries' => $period->entries()->count(), 'skipped' => $skipped];
        });
    }

    /**
     * One employee's entry, with every value copied rather than referenced.
     */
    protected function buildEntry(PayrollPeriod $period, CompanyEmployee $employee, EmployeeCompensation $compensation): PayrollEntry
    {
        $base = (float) $compensation->base_salary;

        $entry = PayrollEntry::create([
            'period_id' => $period->id,
            'employee_id' => $employee->id,
            'compensation_id' => $compensation->id,
            'employee_code' => $employee->employee_code,
            'employee_name' => $employee->user?->name ?? $employee->employee_code,
            'base_salary' => $base,
            'gross_amount' => 0,
            'deduction_amount' => 0,
            'net_amount' => 0,
        ]);

        /* The base is itself an earning line, so a payslip adds up on its face. */
        $entry->lines()->create([
            'component_id' => null,
            'component_code' => 'BASE',
            'component_name' => 'Base salary',
            'type' => CompensationComponentType::Earning,
            'amount' => $base,
        ]);

        $gross = $base;
        $deductions = 0.0;

        foreach ($compensation->lines as $line) {
            $component = $line->component;

            if (! $component || ! $component->is_active) {
                continue;
            }

            $amount = $component->calculation_type === CompensationCalculationType::PercentageOfBase
                ? round($base * ((float) $line->value / 100), 2)
                : round((float) $line->value, 2);

            $entry->lines()->create([
                'component_id' => $component->id,
                'component_code' => $component->code,
                'component_name' => $component->name,
                'type' => $component->type,
                'amount' => $amount,
            ]);

            if ($component->type === CompensationComponentType::Earning) {
                $gross += $amount;

                continue;
            }

            $deductions += $amount;
        }

        $entry->update([
            'gross_amount' => round($gross, 2),
            'deduction_amount' => round($deductions, 2),
            'net_amount' => round($gross - $deductions, 2),
        ]);

        return $entry->fresh('lines');
    }

    /**
     * Move a run along, recording who did it.
     *
     * The order is `PayrollPeriodStatus`'s to state; this only refuses anything
     * it does not allow.
     */
    public function transition(PayrollPeriod $period, PayrollPeriodStatus $target, ?User $actor = null, array $context = []): PayrollPeriod
    {
        if (! $period->status->canTransitionTo($target)) {
            throw new InvalidArgumentException(
                "A {$period->status->label()} payroll run cannot become {$target->label()}."
            );
        }

        $stamp = match ($target) {
            PayrollPeriodStatus::PendingApproval => ['submitted_at' => now(), 'submitted_by' => $actor?->id],
            PayrollPeriodStatus::Approved => ['approved_at' => now(), 'approved_by' => $actor?->id],
            PayrollPeriodStatus::Locked => ['locked_at' => now(), 'locked_by' => $actor?->id],
            PayrollPeriodStatus::Paid => [
                'paid_at' => now(),
                'paid_by' => $actor?->id,
                'payment_reference' => $context['payment_reference'] ?? null,
                'payment_notes' => $context['payment_notes'] ?? null,
            ],
            default => [],
        };

        $period->update(['status' => $target] + $stamp);

        return $period->fresh();
    }

    /**
     * Refuse to change money that has already been committed.
     */
    public function assertMutable(PayrollPeriod $period): void
    {
        if (! $period->isMutable()) {
            throw new InvalidArgumentException(
                "This payroll run is {$period->status->label()} and its figures can no longer change."
            );
        }
    }
}
