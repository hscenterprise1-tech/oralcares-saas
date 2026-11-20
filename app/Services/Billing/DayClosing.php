<?php

namespace App\Services\Billing;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\DailyCashClosing;
use App\Models\User;
use App\PaymentMethod;
use App\Services\Security\SecurityAudit;
use App\Support\ClinicClock;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Closing the day at a branch: the ledger's own figures for the day (money
 * received less money returned, by method) set beside the cash the desk
 * counted. It records the count and any difference with its reason; it moves
 * no money and never changes a bill or a payment.
 */
class DayClosing
{
    /** A day can be closed (late) for this many days back. */
    public const WINDOW_DAYS = 7;

    /**
     * The day's figures from the ledger, and its closing if there is one.
     *
     * @return array<string, mixed>
     */
    public function summary(Clinic $clinic, Branch $branch, ?string $date = null): array
    {
        $date ??= ClinicClock::now()->toDateString();
        [$from, $to] = $this->window($date);

        $received = DB::table('patient_billing_payments')->where('clinic_id', $clinic->id)->where('branch_id', $branch->id)->where('status', 'successful')
            ->where('paid_at', '>=', $from)->where('paid_at', '<', $to)
            ->selectRaw('payment_method, count(*) as count, coalesce(sum(amount), 0) as total')->groupBy('payment_method')->get()->keyBy('payment_method');
        // A refund returns money by the method it names, or else by the method the payment came in by.
        $returned = DB::table('patient_billing_refunds as r')->leftJoin('patient_billing_payments as p', 'p.id', '=', 'r.patient_billing_payment_id')
            ->where('r.clinic_id', $clinic->id)->where('r.branch_id', $branch->id)->where('r.created_at', '>=', $from)->where('r.created_at', '<', $to)
            ->selectRaw('coalesce(r.payment_method, p.payment_method) as method, coalesce(sum(r.amount), 0) as total')->groupBy(DB::raw('coalesce(r.payment_method, p.payment_method)'))->get()->keyBy('method');

        $methods = collect(PaymentMethod::cases())->map(function (PaymentMethod $method) use ($received, $returned) {
            $in = round((float) ($received[$method->value]->total ?? 0), 2);
            $out = round((float) ($returned[$method->value]->total ?? 0), 2);

            return ['method' => $method->value, 'label' => $method->label(), 'collected' => $in, 'returned' => $out, 'net' => round($in - $out, 2), 'count' => (int) ($received[$method->value]->count ?? 0)];
        })->filter(fn (array $row) => $row['collected'] > 0 || $row['returned'] > 0)->values()->all();

        $closing = DailyCashClosing::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->whereDate('business_date', $date)->with('closer:id,name')->first();
        $after = $closing
            ? DB::table('patient_billing_payments')->where('clinic_id', $clinic->id)->where('branch_id', $branch->id)->where('status', 'successful')
                ->where('paid_at', '>=', $from)->where('paid_at', '<', $to)->where('created_at', '>', $closing->closed_at)->count()
            : 0;

        return [
            'date' => $date,
            'methods' => $methods,
            'totals' => [
                'collected' => round(array_sum(array_column($methods, 'collected')), 2),
                'returned' => round(array_sum(array_column($methods, 'returned')), 2),
                'net' => round(array_sum(array_column($methods, 'net')), 2),
                'payments' => (int) array_sum(array_column($methods, 'count')),
            ],
            'expected_cash' => round((float) (collect($methods)->firstWhere('method', PaymentMethod::Cash->value)['net'] ?? 0), 2),
            'closing' => $closing ? $this->present($closing) : null,
            // Money recorded for that day after it was closed: the closing no longer covers it.
            'after_closing' => $after,
            'recent' => DailyCashClosing::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->with('closer:id,name')
                ->orderByDesc('business_date')->limit(7)->get()->map(fn (DailyCashClosing $row) => $this->present($row))->all(),
        ];
    }

    /**
     * Close the day: the counted cash against what the ledger expects, with the reason when they differ.
     *
     * @return array<string, mixed>
     */
    public function close(Clinic $clinic, Branch $branch, string $date, float $countedCash, ?string $notes, ?ClinicEmployee $by, ?User $user = null): array
    {
        $today = ClinicClock::now()->startOfDay();
        $day = CarbonImmutable::parse($date, ClinicClock::timezone())->startOfDay();
        if ($day->greaterThan($today)) {
            throw new InvalidArgumentException('A day that has not come cannot be closed.');
        }
        if ($day->lessThan($today->subDays(self::WINDOW_DAYS))) {
            throw new InvalidArgumentException('That day is too far back to close from here.');
        }
        $counted = (int) round($countedCash * 100);
        if ($counted < 0) {
            throw new InvalidArgumentException('The cash counted cannot be negative.');
        }

        return DB::transaction(function () use ($clinic, $branch, $date, $counted, $notes, $by) {
            // The branch row serialises two desks closing the same day.
            Branch::query()->withoutGlobalScopes()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
            if (DailyCashClosing::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->whereDate('business_date', $date)->exists()) {
                throw new DomainException('This day is already closed.');
            }
            $summary = $this->summary($clinic, $branch, $date);
            $expected = (int) round($summary['expected_cash'] * 100);
            $difference = $counted - $expected;
            $notes = trim((string) $notes);
            if ($difference !== 0 && $notes === '') {
                throw new InvalidArgumentException('Say why the cash counted does not match.');
            }

            try {
                $row = DailyCashClosing::query()->create([
                    'clinic_id' => $clinic->id, 'branch_id' => $branch->id, 'business_date' => $date, 'methods' => $summary['methods'],
                    'payments_count' => $summary['totals']['payments'], 'expected_cash' => $expected / 100, 'counted_cash' => $counted / 100,
                    'difference' => $difference / 100, 'notes' => $notes !== '' ? $notes : null, 'closed_by' => $by?->id, 'closed_at' => now(),
                ]);
            } catch (QueryException) {
                throw new DomainException('This day is already closed.');
            }
            app(SecurityAudit::class)->record('billing.day_closed', $row, ['branch_id' => $branch->id, 'date' => $date, 'difference' => $difference / 100]);

            return $this->present($row->load('closer:id,name'));
        });
    }

    /** @return array<string, mixed> */
    private function present(DailyCashClosing $row): array
    {
        return [
            'id' => $row->id, 'date' => $row->business_date->toDateString(), 'expected_cash' => (float) $row->expected_cash, 'counted_cash' => (float) $row->counted_cash,
            'difference' => (float) $row->difference, 'notes' => $row->notes, 'by' => $row->closer?->name, 'closed_at' => $row->closed_at?->toIso8601String(),
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} the clinic's day as UTC bounds */
    private function window(string $date): array
    {
        $start = CarbonImmutable::parse($date, ClinicClock::timezone())->startOfDay();

        return [$start->utc(), $start->addDay()->utc()];
    }
}
