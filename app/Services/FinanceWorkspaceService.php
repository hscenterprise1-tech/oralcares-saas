<?php

namespace App\Services;

use App\ExpenseStatus;
use App\Models\CommercialInvoice;
use App\Models\CommercialPayment;
use App\Models\CompanyExpense;
use App\Models\MrrSnapshot;
use App\Models\PayrollPeriod;
use App\PayrollPeriodStatus;
use App\Services\Finance\MrrService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The vendor's own books.
 *
 * **Finance is HQ-global by design.** Every query here is deliberately
 * unscoped: this reports the company's whole position across all tenants, which
 * is what a finance desk at head office needs. It is not a per-customer view,
 * and `CrmScope` has no business in it. Per-tenant money is reached through
 * Account 360, which scopes on its own.
 *
 * Two defects shaped this rewrite:
 *
 *   - Every list built its search as `where(A)->orWhereHas(B)` and then
 *     appended `->where('status', …)`. Ungrouped, SQL's AND-binds-tighter rule
 *     made that `A OR (B AND status)`, so searching silently discarded the
 *     filter beside it. All four are grouped now.
 *   - "This month" was `whereMonth('paid_at', now()->month)` with no year, so
 *     it summed every September that had ever happened.
 *
 * It reports; it does not post. There is no double-entry ledger, no tax
 * treatment and no bank reconciliation in this system, and none is invented
 * here.
 */
class FinanceWorkspaceService
{
    public function __construct(protected MrrService $mrr) {}

    /**
     * The whole position on one page: what came in, what went out, what is owed.
     *
     * @return array<string, mixed>
     */
    public function getOverviewPayload(): array
    {
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $collected = (float) CommercialPayment::whereBetween('paid_at', [$monthStart, $monthEnd])->sum('amount');
        $expensesPaid = (float) CompanyExpense::where('status', ExpenseStatus::Paid->value)
            ->whereBetween('paid_at', [$monthStart, $monthEnd])
            ->sum('amount');
        $payrollPaid = (float) PayrollPeriod::where('status', PayrollPeriodStatus::Paid->value)
            ->whereBetween('paid_at', [$monthStart, $monthEnd])
            ->sum('total_net');

        $outgoing = $expensesPaid + $payrollPaid;

        return [
            'recurring' => $this->recurringPayload(),
            'movement' => [
                'period' => $monthStart->format('M Y'),
                'money_in' => round($collected, 2),
                /*
                 * Money out is expenses actually paid plus payroll runs
                 * actually paid. Approved-but-unpaid is a commitment, not a
                 * movement, and is reported separately below.
                 */
                'money_out' => round($outgoing, 2),
                'money_out_expenses' => round($expensesPaid, 2),
                'money_out_payroll' => round($payrollPaid, 2),
                'net_movement' => round($collected - $outgoing, 2),
            ],
            'commitments' => [
                'expenses_awaiting_approval' => (float) CompanyExpense::awaitingApproval()->sum('amount'),
                'expenses_approved_unpaid' => (float) CompanyExpense::where('status', ExpenseStatus::Approved->value)->sum('amount'),
                'payroll_approved_unpaid' => (float) PayrollPeriod::whereIn('status', [
                    PayrollPeriodStatus::Approved->value,
                    PayrollPeriodStatus::Locked->value,
                ])->sum('total_net'),
            ],
            'receivables' => $this->ageingPayload(),
            'lifetime' => [
                'collected' => (float) CommercialPayment::sum('amount'),
                'invoiced' => (float) CommercialInvoice::sum('final_amount'),
                'expenses' => (float) CompanyExpense::committed()->sum('amount'),
            ],
            /*
             * Everything below is a different presentation of the same figures
             * above — no new rule, no new source. Money out stays "expenses
             * paid plus payroll paid", MRR stays "whatever was snapshotted",
             * and a month with no snapshot stays null.
             */
            'trend' => $this->movementTrend(),
            'expense_breakdown' => $this->expenseBreakdown(),
            'payroll' => $this->payrollRunsPayload(),
            'collections' => $this->recentCollections(),
            'invoices' => $this->invoicePositionPayload(),
        ];
    }

    /**
     * Six months of cash in, cash out and what was left.
     *
     * `mrr` rides along from the monthly snapshots and stays null where none
     * was taken, so the chart can break its line rather than draw through a
     * month nobody recorded.
     *
     * @return list<array<string, mixed>>
     */
    protected function movementTrend(int $months = 6): array
    {
        $snapshots = MrrSnapshot::query()
            ->orderByDesc('period')
            ->limit($months)
            ->get()
            ->keyBy('period');

        $rows = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $start = $month->copy()->startOfMonth();
            $end = $month->copy()->endOfMonth();
            $period = $month->format('Y-m');

            $collected = (float) CommercialPayment::whereBetween('paid_at', [$start, $end])->sum('amount');
            $expenses = (float) CompanyExpense::where('status', ExpenseStatus::Paid->value)
                ->whereBetween('paid_at', [$start, $end])
                ->sum('amount');
            $payroll = (float) PayrollPeriod::where('status', PayrollPeriodStatus::Paid->value)
                ->whereBetween('paid_at', [$start, $end])
                ->sum('total_net');

            $rows[] = [
                'period' => $period,
                'month' => $month->format('M'),
                'label' => $month->format('M Y'),
                'collected' => round($collected, 2),
                'expenses_paid' => round($expenses, 2),
                'payroll_paid' => round($payroll, 2),
                'money_out' => round($expenses + $payroll, 2),
                'net' => round($collected - $expenses - $payroll, 2),
                'mrr' => isset($snapshots[$period]) ? (float) $snapshots[$period]->mrr : null,
            ];
        }

        return $rows;
    }

    /**
     * Spend per category, with what has actually left the account kept separate
     * from what has only been agreed.
     *
     * @return list<array<string, mixed>>
     */
    protected function expenseBreakdown(): array
    {
        $rows = CompanyExpense::query()
            ->committed()
            ->select('category', 'status', DB::raw('SUM(amount) as total'))
            ->groupBy('category', 'status')
            ->get();

        $byCategory = [];

        foreach ($rows as $row) {
            $category = (string) $row->category;

            $byCategory[$category] ??= ['category' => $category, 'paid' => 0.0, 'approved_unpaid' => 0.0];

            $key = $row->status === ExpenseStatus::Paid ? 'paid' : 'approved_unpaid';
            $byCategory[$category][$key] += (float) $row->total;
        }

        $result = array_values($byCategory);

        foreach ($result as &$row) {
            $row['total'] = round($row['paid'] + $row['approved_unpaid'], 2);
            $row['paid'] = round($row['paid'], 2);
            $row['approved_unpaid'] = round($row['approved_unpaid'], 2);
        }

        usort($result, fn (array $a, array $b) => $b['total'] <=> $a['total']);

        return $result;
    }

    /**
     * The most recent money to arrive.
     *
     * @return list<array<string, mixed>>
     */
    protected function recentCollections(int $limit = 6): array
    {
        return CommercialPayment::query()
            ->with(['clinic:id,name', 'commercialInvoice:id,invoice_number,status'])
            ->latest('paid_at')
            ->limit($limit)
            ->get()
            ->map(fn (CommercialPayment $payment) => [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'method' => $payment->payment_method,
                'reference' => $payment->transaction_reference,
                'paid_at' => $payment->paid_at?->toDateString(),
                'clinic' => $payment->clinic?->name,
                /*
                 * Null where the money was raised against a commercial document
                 * rather than an invoice. Shown as such rather than back-linked
                 * to an invoice that never existed.
                 */
                'invoice_number' => $payment->commercialInvoice?->invoice_number,
            ])
            ->values()
            ->all();
    }

    /**
     * Invoiced against collected, counted independently.
     *
     * They legitimately disagree: some money arrived against a commercial
     * document rather than an invoice. Reconciling them by hiding one would be
     * the dishonest fix.
     *
     * @return array<string, mixed>
     */
    protected function invoicePositionPayload(): array
    {
        return [
            'invoiced' => (float) CommercialInvoice::sum('final_amount'),
            'paid' => (float) CommercialInvoice::where('status', 'paid')->sum('final_amount'),
            'paid_count' => CommercialInvoice::where('status', 'paid')->count(),
            'open_count' => CommercialInvoice::whereNotIn('status', ['paid', 'cancelled', 'void'])->count(),
            'collected' => (float) CommercialPayment::sum('amount'),
            'unlinked_collected' => (float) CommercialPayment::whereNull('commercial_invoice_id')->sum('amount'),
        ];
    }

    /**
     * Recurring revenue and its history.
     *
     * MRR is read from the value snapshotted on each subscription, never
     * recomputed from today's plan price — a price change must not rewrite what
     * an earlier month earned. Where history is missing, this says so instead of
     * drawing a line through invented points.
     *
     * @return array<string, mixed>
     */
    protected function recurringPayload(): array
    {
        $position = $this->mrr->currentPosition();
        $history = $this->mrr->history(6);

        return [
            'mrr' => $position['mrr'],
            'arr' => round($position['mrr'] * 12, 2),
            'arpu' => $position['arpu'],
            'active_subscriptions' => $position['active_subscriptions'],
            'active_clinics' => $position['active_clinics'],
            /*
             * How many active subscriptions carry no recorded MRR. Reported
             * rather than counted as zero: the two that predate the column are
             * unknown, not free.
             */
            'unrecorded_subscriptions' => $position['unrecorded'],
            'growth_rate' => $this->mrr->growthRate(),
            'history' => $history->map(fn (MrrSnapshot $s) => [
                'period' => $s->period,
                'label' => Carbon::createFromFormat('Y-m', $s->period)->format('M Y'),
                'mrr' => (float) $s->mrr,
                'active_subscriptions' => $s->active_subscriptions,
                'complete' => $s->isComplete(),
            ])->values(),
            /*
             * Two snapshots are the minimum for a growth figure to mean
             * anything. Below that the page says the history is insufficient
             * rather than printing a hard-coded zero beside the MRR, which is
             * what it used to do.
             */
            'has_history' => $history->count() >= 2,
        ];
    }

    /**
     * What customers owe, by how late it is.
     *
     * Buckets are measured from `due_date` on invoices that are not paid.
     * Invoices with no due date are counted separately rather than dropped —
     * money that is owed with no date attached is still owed.
     *
     * @return array<string, mixed>
     */
    protected function ageingPayload(): array
    {
        $today = now()->startOfDay();

        $open = CommercialInvoice::query()
            ->whereNotIn('status', ['paid', 'cancelled', 'void'])
            ->get(['id', 'final_amount', 'due_date', 'status']);

        $buckets = ['current' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, 'over_90' => 0.0, 'undated' => 0.0];

        foreach ($open as $invoice) {
            $amount = (float) $invoice->final_amount;

            if (! $invoice->due_date) {
                $buckets['undated'] += $amount;

                continue;
            }

            $daysLate = (int) $today->diffInDays(Carbon::parse($invoice->due_date)->startOfDay(), false);

            $bucket = match (true) {
                $daysLate >= 0 => 'current',
                $daysLate >= -30 => '1_30',
                $daysLate >= -60 => '31_60',
                $daysLate >= -90 => '61_90',
                default => 'over_90',
            };

            $buckets[$bucket] += $amount;
        }

        return [
            'total_outstanding' => round(array_sum($buckets), 2),
            'invoice_count' => $open->count(),
            'buckets' => array_map(fn (float $v) => round($v, 2), $buckets),
        ];
    }

    /**
     * Get payload for Revenue Workspace.
     */
    public function getRevenuePayload(): array
    {
        $recurring = $this->recurringPayload();

        /*
         * Billing is what was actually collected in each of the last six
         * months, year included. It stands beside MRR rather than replacing it:
         * one is the contracted run rate, the other is cash.
         */
        $billing = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $period = $month->format('Y-m');

            $billing[$period] = (float) CommercialPayment::whereBetween('paid_at', [
                $month->copy()->startOfMonth(),
                $month->copy()->endOfMonth(),
            ])->sum('amount');
        }

        $snapshots = collect($recurring['history'])->keyBy('period');

        $trend = [];

        foreach ($billing as $period => $collected) {
            $snapshot = $snapshots->get($period);

            $trend[] = [
                'period' => $period,
                'month' => Carbon::createFromFormat('Y-m', $period)->format('M Y'),
                /*
                 * Null, not zero, for a month with no snapshot. A gap in the
                 * series is information; a zero is a claim that the business
                 * earned nothing that month.
                 */
                'mrr' => $snapshot['mrr'] ?? null,
                'billing' => round($collected, 2),
            ];
        }

        return [
            'kpi' => $recurring,
            'monthly_trend' => $trend,
        ];
    }

    /**
     * Get payload for Commercial Invoices Workspace.
     */
    public function getInvoicesPayload(array $filters = []): array
    {
        $invoices = CommercialInvoice::query()
            ->with(['clinic:id,name'])
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $this->searchInvoices($q, $search))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return [
            'invoices' => $invoices,
            'filters' => $filters,
            'kpi' => [
                'total_invoiced' => (float) CommercialInvoice::sum('final_amount'),
                'paid_amount' => (float) CommercialInvoice::where('status', 'paid')->sum('final_amount'),
                'overdue_amount' => (float) CommercialInvoice::where('status', 'overdue')->sum('final_amount'),
                'draft_count' => CommercialInvoice::where('status', 'draft')->count(),
            ],
            'ageing' => $this->ageingPayload(),
        ];
    }

    /**
     * Get payload for Commercial Payments Workspace.
     */
    public function getPaymentsPayload(array $filters = []): array
    {
        $payments = CommercialPayment::query()
            ->with(['clinic:id,name', 'commercialInvoice:id,invoice_number'])
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $this->searchPayments($q, $search))
            ->when($filters['payment_method'] ?? null, fn (Builder $q, string $method) => $q->where('payment_method', $method))
            ->when($filters['month'] ?? null, fn (Builder $q, string $month) => $this->scopeToMonth($q, 'paid_at', $month))
            ->latest('paid_at')
            ->paginate(15)
            ->withQueryString();

        return [
            'payments' => $payments,
            'filters' => $filters,
            'kpi' => [
                'total_collected' => (float) CommercialPayment::sum('amount'),
                /*
                 * Year included. This was `whereMonth('paid_at', now()->month)`,
                 * which summed every September that had ever happened.
                 */
                'this_month' => (float) CommercialPayment::whereBetween('paid_at', [
                    now()->startOfMonth(), now()->endOfMonth(),
                ])->sum('amount'),
                'transactions_count' => CommercialPayment::count(),
            ],
        ];
    }

    /**
     * Get payload for Company Expenses Workspace.
     */
    public function getExpensesPayload(array $filters = []): array
    {
        $expenses = CompanyExpense::query()
            ->with(['submitter:id,name,email', 'approver:id,name', 'payer:id,name'])
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $this->searchExpenses($q, $search))
            ->when($filters['category'] ?? null, fn (Builder $q, string $category) => $q->where('category', $category))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->latest('expense_date')
            ->paginate(15)
            ->withQueryString();

        return [
            'expenses' => $expenses,
            'filters' => $filters,
            'kpi' => [
                /*
                 * Committed, not merely approved. This said
                 * `where('status', 'approved')`, which silently dropped every
                 * expense the moment it was paid — money does not stop having
                 * been spent when it leaves the account.
                 */
                'total_expenses' => (float) CompanyExpense::committed()->sum('amount'),
                'pending_approval' => (float) CompanyExpense::awaitingApproval()->sum('amount'),
                'approved_unpaid' => (float) CompanyExpense::where('status', ExpenseStatus::Approved->value)->sum('amount'),
                'paid' => (float) CompanyExpense::where('status', ExpenseStatus::Paid->value)->sum('amount'),
                'by_category' => CompanyExpense::committed()
                    ->select('category', DB::raw('SUM(amount) as total'))
                    ->groupBy('category')
                    ->pluck('total', 'category'),
            ],
        ];
    }

    /*
     * `getPayrollPayload()` is gone.
     *
     * Its only caller was `FinanceController::payrollIndex()`, which rendered a
     * page that no longer exists behind a route that no longer exists. It
     * paginated `company_payrolls` — the legacy rows — and a payload builder
     * with no page to build for is dead weight that still has to be read and
     * kept compiling.
     *
     * `payrollRunsPayload()` below is the part that survived, because it reads
     * the live `company_payroll_periods` and the Finance overview still shows
     * what payroll paid out.
     */

    /**
     * The payroll seam: what the current system has paid, read only.
     *
     * Finance reads payroll rather than payroll writing to Finance. No expense
     * row is created from a run — whether a paid run should post to
     * `company_expenses` is a decision nobody has made, and creating one
     * silently would double-count against this same figure.
     *
     * @return array<string, mixed>
     */
    protected function payrollRunsPayload(): array
    {
        $paid = PayrollPeriod::query()
            ->where('status', PayrollPeriodStatus::Paid->value)
            ->orderByDesc('period_start')
            ->limit(6)
            ->get(['id', 'code', 'label', 'period_start', 'period_end', 'total_gross', 'total_deductions', 'total_net', 'entry_count', 'paid_at']);

        return [
            'paid_lifetime' => (float) PayrollPeriod::where('status', PayrollPeriodStatus::Paid->value)->sum('total_net'),
            'awaiting_payment' => (float) PayrollPeriod::whereIn('status', [
                PayrollPeriodStatus::Approved->value,
                PayrollPeriodStatus::Locked->value,
            ])->sum('total_net'),
            'recent' => $paid->map(fn (PayrollPeriod $period) => [
                'id' => $period->id,
                'code' => $period->code,
                'label' => $period->label,
                'period_start' => $period->period_start?->toDateString(),
                'period_end' => $period->period_end?->toDateString(),
                'total_net' => (float) $period->total_net,
                'entry_count' => (int) $period->entry_count,
                'paid_at' => $period->paid_at?->format('d M Y'),
            ])->values(),
        ];
    }

    /* ─────────────────────────── grouped searches ───────────────────────── */

    /**
     * Every search below is wrapped in its own `where(function …)`.
     *
     * Left ungrouped, an `orWhere` escapes the filters beside it: SQL binds AND
     * tighter than OR, so `A OR B AND status = x` reads as `A OR (B AND status)`
     * and a search silently ignores the status filter next to it.
     *
     * @param  Builder<CommercialInvoice>  $query
     */
    protected function searchInvoices(Builder $query, string $search): void
    {
        $query->where(function (Builder $inner) use ($search) {
            $inner->where('invoice_number', 'like', "%{$search}%")
                ->orWhereHas('clinic', fn ($q) => $q->where('name', 'like', "%{$search}%"));
        });
    }

    /** @param  Builder<CommercialPayment>  $query */
    protected function searchPayments(Builder $query, string $search): void
    {
        $query->where(function (Builder $inner) use ($search) {
            $inner->where('transaction_reference', 'like', "%{$search}%")
                ->orWhereHas('clinic', fn ($q) => $q->where('name', 'like', "%{$search}%"));
        });
    }

    /** @param  Builder<CompanyExpense>  $query */
    protected function searchExpenses(Builder $query, string $search): void
    {
        $query->where(function (Builder $inner) use ($search) {
            $inner->where('description', 'like', "%{$search}%")
                ->orWhere('expense_number', 'like', "%{$search}%");
        });
    }

    /**
     * Restrict a timestamp column to one `YYYY-MM`, year included.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    protected function scopeToMonth(Builder $query, string $column, string $month): void
    {
        try {
            $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        } catch (\Throwable) {
            /* An unparseable month filters nothing rather than throwing. */
            return;
        }

        $query->whereBetween($column, [$start, $start->copy()->endOfMonth()]);
    }
}
