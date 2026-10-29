<?php

namespace App\Services\Billing;

use App\BillingStatus;
use App\ClinicPermission;
use App\Services\ClinicContext;
use App\Support\ClinicAccess\BranchReach;
use App\Support\ClinicClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The Billing work center's read model: bills, collections, refunds and
 * adjustments, and the financial summary, all from the canonical patient
 * billing tables (`patient_billings` and its payments, refunds and
 * adjustments) — never the clinic's legacy `invoices`, which are counted
 * only to say they are left out. Every list and the totals beside it come
 * from one filtered query, so a total is always the sum of its rows.
 *
 * Scope is `BranchReach`: the owner sees the clinic; anyone else the branches
 * they may open and hold the permission at. Dates are calendar days at the
 * clinic (`ClinicClock`), stored in UTC: a bill counts on its issue day, a
 * collection on its payment day, a refund or adjustment on the day it was
 * recorded, an expense on the day it was spent.
 *
 * Reading only. Every change goes through its own service (`PaymentService`,
 * `ClinicExpenses`), which this never bypasses.
 */
class BillingRegister
{
    public const PAGE = 25;

    /**
     * The filters a Billing page accepts, cleaned: unknown values are dropped,
     * never trusted.
     *
     * @return array{q: string, status: ?string, branch: ?int, from: ?string, to: ?string, method: ?string, type: ?string}
     */
    public function filters(Request $request): array
    {
        $date = fn (string $key) => is_string($value = $request->query($key)) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
        $from = $date('from');
        $to = $date('to');
        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 80),
            'status' => in_array($request->query('status'), array_column(BillingStatus::cases(), 'value'), true) ? $request->query('status') : null,
            'branch' => $request->integer('branch') ?: null,
            'from' => $from,
            'to' => $to,
            'method' => is_string($request->query('method')) && preg_match('/^[a-z_]{2,30}$/', $request->query('method')) ? $request->query('method') : null,
            'type' => in_array($request->query('type'), ['refund', 'adjustment'], true) ? $request->query('type') : null,
        ];
    }

    /** Bills issued in the filters' range, at the reach's branches. */
    public function bills(int $clinicId, BranchReach $reach, array $filters): Builder
    {
        $query = DB::table('patient_billings')->where('patient_billings.clinic_id', $clinicId);
        $reach->apply($query, 'patient_billings.branch_id', $filters['branch']);
        $this->onDays($query, 'patient_billings.issued_at', $filters['from'], $filters['to']);
        if ($filters['status'] !== null) {
            $query->where('patient_billings.status', $filters['status']);
        }
        $this->matching($query, $filters['q'], 'patient_billings.billing_number', 'patient_billings.patient_id', $clinicId);

        return $query;
    }

    /**
     * What the bills list totals: every figure is a sum of the same rows the
     * list pages through. Cancelled bills are listed but carry no money.
     *
     * @return array{count: int, billed: float, collected: float, refunded: float, adjusted: float, outstanding: float}
     */
    public function billTotals(Builder $bills): array
    {
        $live = fn () => (clone $bills)->where('patient_billings.status', '!=', BillingStatus::Cancelled->value);
        $row = $live()->selectRaw('sum(final_amount) as billed, sum(paid_amount) as collected, sum(refunded_amount) as refunded, sum(adjusted_amount) as adjusted, sum(outstanding_balance) as outstanding')->first();

        return [
            'count' => (clone $bills)->count(),
            'billed' => round((float) $row->billed, 2),
            'collected' => round((float) $row->collected, 2),
            'refunded' => round((float) $row->refunded, 2),
            'adjusted' => round((float) $row->adjusted, 2),
            'outstanding' => round((float) $row->outstanding, 2),
        ];
    }

    /** Payments received in the filters' range, at the reach's branches. */
    public function collections(int $clinicId, BranchReach $reach, array $filters): Builder
    {
        $query = DB::table('patient_billing_payments')
            ->join('patient_billings', 'patient_billings.id', '=', 'patient_billing_payments.patient_billing_id')
            ->where('patient_billing_payments.clinic_id', $clinicId);
        $reach->apply($query, 'patient_billing_payments.branch_id', $filters['branch']);
        $this->onDays($query, 'patient_billing_payments.paid_at', $filters['from'], $filters['to']);
        if ($filters['method'] !== null) {
            $query->where('patient_billing_payments.payment_method', $filters['method']);
        }
        $this->matching($query, $filters['q'], 'patient_billings.billing_number', 'patient_billings.patient_id', $clinicId, 'patient_billing_payments.receipt_number');

        return $query;
    }

    /**
     * Refunds and adjustments recorded in the range, one list, newest first.
     * Both are recorded at once by the service that makes them: there is no
     * request or approval stage behind either.
     */
    public function reversals(int $clinicId, BranchReach $reach, array $filters): Builder
    {
        $part = function (string $table, string $type, string $number, string $byColumn, string $byTable) use ($clinicId, $reach, $filters): Builder {
            $query = DB::table($table)
                ->join('patient_billings', 'patient_billings.id', '=', "{$table}.patient_billing_id")
                ->leftJoin($byTable, "{$byTable}.id", '=', "{$table}.{$byColumn}")
                ->where("{$table}.clinic_id", $clinicId)
                ->selectRaw("'{$type}' as type, {$table}.id, {$table}.patient_billing_id as bill_id, patient_billings.billing_number as bill_number, patient_billings.patient_id, {$table}.branch_id, {$table}.amount, {$table}.reason, {$table}.created_at, {$number} as number, ".($type === 'refund' ? "{$table}.payment_method" : 'null').' as method, '."{$byTable}.name as recorded_by");
            $reach->apply($query, "{$table}.branch_id", $filters['branch']);
            $this->onDays($query, "{$table}.created_at", $filters['from'], $filters['to']);
            $this->matching($query, $filters['q'], 'patient_billings.billing_number', 'patient_billings.patient_id', $clinicId);

            return $query;
        };

        $refunds = $part('patient_billing_refunds', 'refund', 'patient_billing_refunds.refund_number', 'refunded_by', 'clinic_employees');
        $adjustments = $part('patient_billing_adjustments', 'adjustment', 'null', 'adjusted_by', 'users');

        $union = match ($filters['type']) {
            'refund' => $refunds,
            'adjustment' => $adjustments,
            default => $refunds->unionAll($adjustments),
        };

        return DB::query()->fromSub($union, 'reversals');
    }

    /** @return array{count: int, refunded: float, adjusted: float} */
    public function reversalTotals(Builder $reversals): array
    {
        $row = (clone $reversals)->selectRaw("count(*) as n, sum(case when type = 'refund' then amount else 0 end) as refunded, sum(case when type = 'adjustment' then amount else 0 end) as adjusted")->first();

        return ['count' => (int) $row->n, 'refunded' => round((float) $row->refunded, 2), 'adjusted' => round((float) $row->adjusted, 2)];
    }

    /**
     * The financial summary for a range: only figures the records support,
     * each with its date basis. Outstanding and expenses to pay are balances
     * as of now, not period figures. Expenses only for whoever may see them.
     *
     * @return array<string, mixed>
     */
    public function summary(int $clinicId, BranchReach $reach, ?BranchReach $expenses, array $filters): array
    {
        $only = $filters['branch'];
        $billed = DB::table('patient_billings')->where('clinic_id', $clinicId)->where('status', '!=', BillingStatus::Cancelled->value);
        $reach->apply($billed, 'branch_id', $only);
        $this->onDays($billed, 'issued_at', $filters['from'], $filters['to']);
        $billedRow = (clone $billed)->selectRaw('count(*) as n, sum(final_amount) as billed, sum(discount_amount) as discounts')->first();

        $payments = DB::table('patient_billing_payments')->where('clinic_id', $clinicId);
        $reach->apply($payments, 'branch_id', $only);
        $this->onDays($payments, 'paid_at', $filters['from'], $filters['to']);
        $byMethod = (clone $payments)->groupBy('payment_method')->selectRaw('payment_method, count(*) as n, sum(amount) as total')->orderByDesc('total')->get();
        $collected = (float) $byMethod->sum('total');

        $refunds = DB::table('patient_billing_refunds')->where('clinic_id', $clinicId);
        $reach->apply($refunds, 'branch_id', $only);
        $this->onDays($refunds, 'created_at', $filters['from'], $filters['to']);
        $refunded = (float) (clone $refunds)->sum('amount');

        $adjustments = DB::table('patient_billing_adjustments')->where('clinic_id', $clinicId);
        $reach->apply($adjustments, 'branch_id', $only);
        $this->onDays($adjustments, 'created_at', $filters['from'], $filters['to']);
        $adjusted = (float) (clone $adjustments)->sum('amount');

        $open = DB::table('patient_billings')->where('clinic_id', $clinicId)
            ->whereIn('status', [BillingStatus::Pending->value, BillingStatus::PartiallyPaid->value])->where('outstanding_balance', '>', 0);
        $reach->apply($open, 'branch_id', $only);

        $branches = DB::table('branches')->where('clinic_id', $clinicId)->orderByDesc('is_main')->orderBy('name')->get(['id', 'name'])
            ->filter(fn ($branch) => $reach->covers((int) $branch->id) && ($only === null || (int) $branch->id === $only))->values();
        $perBranch = fn (Builder $query, string $sum) => (clone $query)->groupBy('branch_id')->selectRaw("branch_id, sum({$sum}) as total")->pluck('total', 'branch_id');
        $billedBy = $perBranch($billed, 'final_amount');
        $collectedBy = $perBranch($payments, 'amount');
        $refundedBy = $perBranch($refunds, 'amount');
        $outstandingBy = $perBranch($open, 'outstanding_balance');

        $spending = null;
        if ($expenses !== null) {
            $spent = DB::table('clinic_expenses')->where('clinic_id', $clinicId)->whereIn('status', ['approved', 'paid']);
            $expenses->apply($spent, 'branch_id', $only);
            if ($filters['from'] !== null) {
                $spent->where('spent_on', '>=', $filters['from']);
            }
            if ($filters['to'] !== null) {
                $spent->where('spent_on', '<=', $filters['to']);
            }
            $toPay = DB::table('clinic_expenses')->where('clinic_id', $clinicId)->where('status', 'approved');
            $expenses->apply($toPay, 'branch_id', $only);
            $spending = [
                'spent' => round((float) (clone $spent)->sum('amount'), 2),
                'paid' => round((float) (clone $spent)->where('status', 'paid')->sum('amount'), 2),
                'to_pay' => round((float) $toPay->sum('amount'), 2),
            ];
        }

        // The clinic's records in the earlier invoice system (`payments` is the Company side's, not a clinic's).
        $legacy = DB::table('invoices')->where('clinic_id', $clinicId)->count();

        return [
            'figures' => [
                'bills' => (int) $billedRow->n,
                'billed' => round((float) $billedRow->billed, 2),
                'discounts' => round((float) $billedRow->discounts, 2),
                'collected' => round($collected, 2),
                'refunded' => round($refunded, 2),
                'net_collected' => round($collected - $refunded, 2),
                'adjusted' => round($adjusted, 2),
                'outstanding' => round((float) (clone $open)->sum('outstanding_balance'), 2),
            ],
            'by_method' => $byMethod->map(fn ($row) => ['method' => (string) $row->payment_method, 'count' => (int) $row->n, 'total' => round((float) $row->total, 2)])->values()->all(),
            'by_branch' => $branches->map(fn ($branch) => [
                'id' => (int) $branch->id,
                'name' => $branch->name,
                'billed' => round((float) ($billedBy[$branch->id] ?? 0), 2),
                'collected' => round((float) ($collectedBy[$branch->id] ?? 0), 2),
                'refunded' => round((float) ($refundedBy[$branch->id] ?? 0), 2),
                'outstanding' => round((float) ($outstandingBy[$branch->id] ?? 0), 2),
            ])->all(),
            'expenses' => $spending,
            'legacy_invoices' => $legacy,
        ];
    }

    /**
     * Billed and collected for each clinic day from `$from` to `$to`
     * inclusive, on the same basis as {@see summary()}: bills issued and not
     * cancelled, payments received, at the reach's branches. Each day's
     * figures sum to what the Billing lists show for that day.
     *
     * Rows are bucketed by the clinic's own calendar day in PHP, so a time
     * zone with a half-hour offset still lands every record on the right day.
     *
     * @return list<array{date: string, billed: float, collected: float}>
     */
    public function daily(int $clinicId, BranchReach $reach, ?int $branch, string $from, string $to): array
    {
        $days = [];
        for ($day = CarbonImmutable::parse($from); $day->toDateString() <= $to; $day = $day->addDay()) {
            $days[$day->toDateString()] = ['date' => $day->toDateString(), 'billed' => 0.0, 'collected' => 0.0];
        }

        $timezone = ClinicClock::timezone();
        $add = function (Builder $query, string $column, string $amount, string $into) use (&$days, $timezone): void {
            foreach ($query->select([$column.' as at', $amount.' as amount'])->cursor() as $row) {
                $day = CarbonImmutable::parse($row->at, 'UTC')->setTimezone($timezone)->toDateString();
                if (isset($days[$day])) {
                    $days[$day][$into] += (float) $row->amount;
                }
            }
        };

        $billed = DB::table('patient_billings')->where('clinic_id', $clinicId)->where('status', '!=', BillingStatus::Cancelled->value);
        $reach->apply($billed, 'branch_id', $branch);
        $this->onDays($billed, 'issued_at', $from, $to);
        $add($billed, 'issued_at', 'final_amount', 'billed');

        $payments = DB::table('patient_billing_payments')->where('clinic_id', $clinicId);
        $reach->apply($payments, 'branch_id', $branch);
        $this->onDays($payments, 'paid_at', $from, $to);
        $add($payments, 'paid_at', 'amount', 'collected');

        return array_values(array_map(fn (array $day) => [...$day, 'billed' => round($day['billed'], 2), 'collected' => round($day['collected'], 2)], $days));
    }

    /** The reach for a permission, for the signed-in member of the session's clinic. */
    public function reach(ClinicPermission $permission): BranchReach
    {
        $context = app(ClinicContext::class);

        return BranchReach::for($context->membership() ?? abort(403), auth()->user() ?? abort(403), $permission);
    }

    /** A record's day at the clinic, between two calendar days inclusive. */
    private function onDays(Builder $query, string $column, ?string $from, ?string $to): void
    {
        $start = fn (string $day) => CarbonImmutable::parse($day, ClinicClock::timezone())->startOfDay()->utc()->toDateTimeString();
        if ($from !== null) {
            $query->where($column, '>=', $start($from));
        }
        if ($to !== null) {
            $query->where($column, '<', $start(CarbonImmutable::parse($to)->addDay()->toDateString()));
        }
    }

    /**
     * A search across the bill number (and a receipt or refund number where
     * given) and the patient's name, UHID or mobile, by prefix: bounded,
     * index-friendly, within this clinic's patients only.
     */
    private function matching(Builder $query, string $q, string $numberColumn, string $patientColumn, int $clinicId, ?string $alsoNumber = null): void
    {
        if ($q === '') {
            return;
        }
        $prefix = addcslashes($q, '%_\\').'%';
        $query->where(function ($inner) use ($prefix, $numberColumn, $patientColumn, $clinicId, $alsoNumber) {
            $inner->where($numberColumn, 'like', $prefix)
                ->when($alsoNumber, fn ($q) => $q->orWhere($alsoNumber, 'like', $prefix))
                ->orWhereIn($patientColumn, DB::table('patients')->where('clinic_id', $clinicId)->where(fn ($p) => $p
                    ->where('first_name', 'like', $prefix)->orWhere('last_name', 'like', $prefix)->orWhere('name', 'like', $prefix)
                    ->orWhere('uhid', 'like', $prefix)->orWhere('phone', 'like', $prefix))->select('id'));
        });
    }
}
