<?php

namespace App\Services\People;

use App\LeaveBalanceEntryType;
use App\Models\CompanyEmployee;
use App\Models\CompanyLeaveBalance;
use App\Models\CompanyLeaveBalanceEntry;
use App\Models\CompanyLeaveRequest;
use App\Models\CompanyLeaveType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What people have left, and how they came to have it.
 *
 * Every movement is a recorded entry and the totals on the balance are their
 * sum. That is the whole of the accrual and carry-forward foundation: no rate,
 * no frequency, no cap and no year-end rule is encoded anywhere, because none
 * has been decided. When one is, it writes entries here — and the balances it
 * produced before that stay exactly as they were.
 *
 * Nothing accrues on its own. There is no scheduled job quietly granting days.
 */
class LeaveBalanceService
{
    /**
     * The balance row for one person, type and year, created empty if needed.
     *
     * Creating it empty is deliberate: a person with no entitlement recorded
     * has zero, which is a different statement from having no balance at all.
     */
    public function balanceFor(CompanyEmployee $employee, CompanyLeaveType $type, int $year): CompanyLeaveBalance
    {
        return CompanyLeaveBalance::firstOrCreate([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'period_year' => $year,
        ]);
    }

    /**
     * Record a movement and roll it into the totals.
     *
     * `days` is signed: positive grants, negative takes away. The entry is the
     * fact; the column on the balance is a running total kept in step with it.
     */
    public function record(
        CompanyLeaveBalance $balance,
        LeaveBalanceEntryType $type,
        float $days,
        ?Carbon $effectiveDate = null,
        ?string $reason = null,
        ?CompanyLeaveRequest $request = null,
        ?User $actor = null,
    ): CompanyLeaveBalanceEntry {
        return DB::transaction(function () use ($balance, $type, $days, $effectiveDate, $reason, $request, $actor) {
            $entry = CompanyLeaveBalanceEntry::create([
                'leave_balance_id' => $balance->id,
                'entry_type' => $type,
                'days' => round($days, 2),
                'effective_date' => ($effectiveDate ?? now())->toDateString(),
                'reason' => $reason,
                'leave_request_id' => $request?->id,
                'created_by' => $actor?->id,
            ]);

            /*
             * Consumption and reversal both move `used` — one up, one down —
             * while everything else moves an entitlement column. A reversal is
             * recorded with negative days, so the arithmetic is the same in
             * both directions and nothing has to be edited backwards.
             */
            $column = $type->column();
            $balance->{$column} = round((float) $balance->{$column} + round($days, 2), 2);
            $balance->save();

            return $entry;
        });
    }

    /**
     * Take the days an approved request needs out of the balance.
     *
     * Allowed to overdraw. Refusing a request that exceeds what is left would
     * be a policy — some companies allow negative balances, some do not — and
     * nothing here has decided which. The overdraft is reported, not blocked.
     */
    public function consume(CompanyLeaveRequest $request, ?User $actor = null): ?CompanyLeaveBalanceEntry
    {
        $days = (float) $request->total_days;

        /*
         * A request made entirely of part-days consumes nothing, because a
         * part-day is recorded in hours and never converted into a fraction of
         * a day — that conversion needs a standard working day this system does
         * not define.
         */
        if ($days <= 0) {
            return null;
        }

        $balance = $this->balanceFor($request->employee, $request->leaveType, $request->periodYear());

        return $this->record(
            $balance,
            LeaveBalanceEntryType::Consumption,
            $days,
            $request->start_date,
            'Leave approved.',
            $request,
            $actor,
        );
    }

    /**
     * Give back what a cancelled request had taken.
     *
     * Recorded as its own entry rather than by deleting the consumption. The
     * history of a balance should read as what happened, not as what is
     * currently true.
     */
    public function reverse(CompanyLeaveRequest $request, ?User $actor = null): ?CompanyLeaveBalanceEntry
    {
        $consumed = (float) $request->balanceEntries()
            ->where('entry_type', LeaveBalanceEntryType::Consumption->value)
            ->sum('days');

        $alreadyReturned = abs((float) $request->balanceEntries()
            ->where('entry_type', LeaveBalanceEntryType::Reversal->value)
            ->sum('days'));

        $outstanding = round($consumed - $alreadyReturned, 2);

        if ($outstanding <= 0) {
            return null;
        }

        $balance = $this->balanceFor($request->employee, $request->leaveType, $request->periodYear());

        return $this->record(
            $balance,
            LeaveBalanceEntryType::Reversal,
            -$outstanding,
            now(),
            'Leave cancelled.',
            $request,
            $actor,
        );
    }

    /**
     * Every balance for one employee in one year, including types they have
     * never touched.
     *
     * A type with no balance row still appears, at zero. Hiding it would make
     * "you have no sick leave recorded" look like "there is no such thing as
     * sick leave here".
     *
     * @return list<array<string, mixed>>
     */
    public function forEmployee(CompanyEmployee $employee, int $year): array
    {
        $balances = CompanyLeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('period_year', $year)
            ->get()
            ->keyBy('leave_type_id');

        return CompanyLeaveType::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function (CompanyLeaveType $type) use ($balances) {
                $balance = $balances->get($type->id);

                return [
                    'leave_type_id' => $type->id,
                    'code' => $type->code,
                    'name' => $type->name,
                    'colour' => $type->colour,
                    'is_paid' => $type->is_paid,
                    'opening_balance' => (float) ($balance?->opening_balance ?? 0),
                    'accrued' => (float) ($balance?->accrued ?? 0),
                    'carried_forward' => (float) ($balance?->carried_forward ?? 0),
                    'adjusted' => (float) ($balance?->adjusted ?? 0),
                    'used' => (float) ($balance?->used ?? 0),
                    'entitled' => $balance?->entitled() ?? 0.0,
                    'available' => $balance?->available() ?? 0.0,
                    'has_balance' => $balance !== null,
                ];
            })
            ->values()
            ->all();
    }
}
