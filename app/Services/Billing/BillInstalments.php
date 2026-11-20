<?php

namespace App\Services\Billing;

use App\BillingStatus;
use App\Models\PatientBilling;
use App\Models\PatientBillingInstalment;
use App\Models\PatientBillingPayment;
use App\Models\User;
use App\Services\Security\SecurityAudit;
use App\Support\ClinicClock;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A payment plan on a bill: what is owed, split into dated instalments the
 * patient agreed to. The bill stays the one ledger: a payment is allocated
 * to the earliest unpaid instalment as it is recorded, never the other way
 * round, and a plan can only be made for exactly the balance owed.
 */
class BillInstalments
{
    public const MAX = 12;

    /**
     * Make (or remake) the plan: the rows' amounts must add up to the balance owed, dated today or later, in order.
     *
     * @param  list<array{due_on: string, amount: float|int|string}>  $rows
     * @return Collection<int, PatientBillingInstalment>
     */
    public function plan(PatientBilling $bill, array $rows, ?User $by = null, ?int $employeeId = null): Collection
    {
        return DB::transaction(function () use ($bill, $rows, $employeeId) {
            /** @var PatientBilling $locked */
            $locked = PatientBilling::query()->withoutGlobalScopes()->whereKey($bill->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === BillingStatus::Cancelled || $locked->status === BillingStatus::Paid) {
                throw new DomainException('Only a bill with a balance can have a payment plan.');
            }
            $owed = (int) round((float) $locked->outstanding_balance * 100);
            if ($owed <= 0) {
                throw new DomainException('Nothing is owed on this bill.');
            }
            if ($rows === [] || count($rows) > self::MAX) {
                throw new InvalidArgumentException('A payment plan has between 1 and '.self::MAX.' instalments.');
            }

            $today = ClinicClock::now()->toDateString();
            $previous = null;
            $sum = 0;
            foreach ($rows as $row) {
                $due = (string) ($row['due_on'] ?? '');
                $paise = (int) round((float) ($row['amount'] ?? 0) * 100);
                if ($paise <= 0) {
                    throw new InvalidArgumentException('Each instalment must be more than zero.');
                }
                if ($due < $today) {
                    throw new InvalidArgumentException('An instalment cannot be due in the past.');
                }
                if ($previous !== null && $due <= $previous) {
                    throw new InvalidArgumentException('Instalments must fall on later and later days.');
                }
                $previous = $due;
                $sum += $paise;
            }
            if ($sum !== $owed) {
                throw new InvalidArgumentException('The instalments must add up to the balance owed.');
            }

            PatientBillingInstalment::query()->withoutGlobalScopes()->where('patient_billing_id', $locked->id)->delete();
            $made = collect($rows)->values()->map(fn (array $row, int $index) => PatientBillingInstalment::query()->create([
                'patient_billing_id' => $locked->id,
                'clinic_id' => $locked->clinic_id,
                'branch_id' => $locked->branch_id,
                'sequence' => $index + 1,
                'due_on' => $row['due_on'],
                'amount' => round((float) $row['amount'], 2),
                'paid_amount' => 0,
                'created_by' => $employeeId,
            ]));
            app(SecurityAudit::class)->record('billing.instalments.planned', $locked, ['count' => $made->count(), 'total' => $owed / 100]);

            return $made;
        });
    }

    /** Take the plan away (the bill and what was paid stay as they are). */
    public function clear(PatientBilling $bill): void
    {
        DB::transaction(function () use ($bill) {
            PatientBillingInstalment::query()->withoutGlobalScopes()->where('patient_billing_id', $bill->id)->delete();
            app(SecurityAudit::class)->record('billing.instalments.cleared', $bill, []);
        });
    }

    /**
     * A payment goes to the earliest unpaid instalment, then the next, as it was recorded. Called from the payment's own
     * transaction, so the plan can never disagree with the ledger.
     */
    public function allocate(PatientBillingPayment $payment): void
    {
        $rows = PatientBillingInstalment::query()->withoutGlobalScopes()->where('patient_billing_id', $payment->patient_billing_id)
            ->orderBy('sequence')->lockForUpdate()->get();
        $left = (int) round((float) $payment->amount * 100);
        foreach ($rows as $row) {
            if ($left <= 0) {
                break;
            }
            $room = (int) round($row->remaining() * 100);
            if ($room <= 0) {
                continue;
            }
            $take = min($room, $left);
            $row->forceFill(['paid_amount' => ((int) round((float) $row->paid_amount * 100) + $take) / 100])->save();
            $left -= $take;
        }
    }

    /**
     * A bill's plan with where each instalment stands today.
     *
     * @return list<array{id: int, sequence: int, due_on: string, amount: float, paid: float, remaining: float, state: string}>
     */
    public function forBill(PatientBilling $bill): array
    {
        $today = ClinicClock::now()->toDateString();

        return PatientBillingInstalment::query()->withoutGlobalScopes()->where('patient_billing_id', $bill->id)->orderBy('sequence')->get()
            ->map(fn (PatientBillingInstalment $row) => [
                'id' => $row->id, 'sequence' => $row->sequence, 'due_on' => $row->due_on->toDateString(), 'amount' => (float) $row->amount,
                'paid' => (float) $row->paid_amount, 'remaining' => $row->remaining(),
                'state' => $row->remaining() <= 0 ? 'paid' : ($row->due_on->toDateString() < $today ? 'overdue' : ((float) $row->paid_amount > 0 ? 'partial' : 'upcoming')),
            ])->all();
    }

    /**
     * For each of these bills, its next instalment with a balance: when it falls due, how much of it is left, whether it is late,
     * and how many instalments are still to pay. Bills without a plan are left out.
     *
     * @param  list<int>  $billIds
     * @return array<int, array{due_on: string, remaining: float, overdue: bool, left: int}>
     */
    public function nextFor(array $billIds): array
    {
        if ($billIds === []) {
            return [];
        }
        $today = ClinicClock::now()->toDateString();

        return PatientBillingInstalment::query()->withoutGlobalScopes()->whereIn('patient_billing_id', $billIds)
            ->whereColumn('paid_amount', '<', 'amount')->orderBy('sequence')->get()->groupBy('patient_billing_id')
            ->map(fn (Collection $rows) => [
                'due_on' => $rows->first()->due_on->toDateString(), 'remaining' => $rows->first()->remaining(),
                'overdue' => $rows->first()->due_on->toDateString() < $today, 'left' => $rows->count(),
            ])->all();
    }

    /**
     * Instalments overdue or falling due within `$days`, for the desk: one row per instalment with a balance, on a bill still open.
     *
     * @return list<array{id: int, bill_id: int, bill: ?string, patient_id: int, patient: string, due_on: string, remaining: float, overdue: bool}>
     */
    public function dues(int $clinicId, ?int $branchId, int $days = 7, int $limit = 50): array
    {
        $today = ClinicClock::now();

        return PatientBillingInstalment::query()->withoutGlobalScopes()
            ->join('patient_billings', 'patient_billings.id', '=', 'patient_billing_instalments.patient_billing_id')
            ->join('patients', 'patients.id', '=', 'patient_billings.patient_id')
            ->where('patient_billing_instalments.clinic_id', $clinicId)
            ->when($branchId, fn ($query) => $query->where('patient_billing_instalments.branch_id', $branchId))
            ->whereColumn('patient_billing_instalments.paid_amount', '<', 'patient_billing_instalments.amount')
            ->whereNotIn('patient_billings.status', [BillingStatus::Cancelled->value, BillingStatus::Paid->value])
            ->whereDate('patient_billing_instalments.due_on', '<=', $today->addDays($days)->toDateString())
            ->orderBy('patient_billing_instalments.due_on')->limit($limit)
            ->get(['patient_billing_instalments.*', 'patient_billings.billing_number', 'patients.id as pid', 'patients.first_name', 'patients.last_name'])
            ->map(fn ($row) => [
                'id' => $row->id, 'bill_id' => $row->patient_billing_id, 'bill' => $row->billing_number, 'patient_id' => $row->pid,
                'patient' => trim($row->first_name.' '.$row->last_name), 'due_on' => $row->due_on->toDateString(),
                'remaining' => max(0, round((float) $row->amount - (float) $row->paid_amount, 2)), 'overdue' => $row->due_on->toDateString() < $today->toDateString(),
            ])->all();
    }
}
