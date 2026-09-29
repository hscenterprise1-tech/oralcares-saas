<?php

namespace App\Services\Reporting;

use App\ExpenseStatus;
use App\LeaveRequestStatus;
use App\Models\CommercialInvoice;
use App\Models\CommercialPayment;
use App\Models\CompanyEmployee;
use App\Models\CompanyExpense;
use App\Models\CompanyLeaveRequest;
use App\Models\CompanySubscription;
use App\Models\CompanySupportTicket;
use App\Models\Lead;
use App\Models\Organization;
use App\Services\Finance\MrrService;
use App\SubscriptionStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Company-wide reporting.
 *
 * This owns no figures. Every number is read from the module that is
 * authoritative for it — MRR from `MrrService`, which reads the value
 * snapshotted on each subscription; cash from the commercial ledger; spend from
 * the expense workflow — so a report can never disagree with the page it came
 * from.
 *
 * Two things it will not do. It does not compute an accounting metric this
 * system cannot support: there is no double-entry ledger, no tax treatment and
 * no cost-of-revenue anywhere, so there is no margin, no burn and no runway
 * here. And it does not fill a gap with a zero — a figure with no data behind
 * it comes back null and the surface says so.
 *
 * HQ-global by design, like Finance: this is the vendor's own book across every
 * customer, not a per-tenant view. Per-tenant belongs to Account 360.
 */
class CompanyReportService
{
    public function __construct(protected MrrService $mrr) {}

    /**
     * Every domain, for one window.
     *
     * @return array<string, mixed>
     */
    public function build(Carbon $from, Carbon $to): array
    {
        return [
            'window' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'label' => $from->format('j M Y').' – '.$to->format('j M Y'),
                'days' => (int) $from->diffInDays($to) + 1,
            ],
            'revenue' => $this->revenue($from, $to),
            'pipeline' => $this->pipeline($from, $to),
            'customers' => $this->customers($from, $to),
            'spend' => $this->spend($from, $to),
            'people' => $this->people($from, $to),
            'support' => $this->support($from, $to),
        ];
    }

    /** @return array<string, mixed> */
    protected function revenue(Carbon $from, Carbon $to): array
    {
        $position = $this->mrr->currentPosition();

        return [
            'collected' => (float) CommercialPayment::whereBetween('paid_at', [$from, $to])->sum('amount'),
            'invoiced' => (float) CommercialInvoice::whereBetween('created_at', [$from, $to])->sum('final_amount'),
            'payment_count' => CommercialPayment::whereBetween('paid_at', [$from, $to])->count(),
            /*
             * MRR is a position, not a window figure — it is what the book is
             * worth now, and it comes from the values snapshotted on each
             * subscription rather than from today's price list.
             */
            'mrr_now' => $position['mrr'] > 0 ? $position['mrr'] : null,
            'arr_now' => $position['mrr'] > 0 ? round($position['mrr'] * 12, 2) : null,
            'mrr_unrecorded' => $position['unrecorded'],
            'growth_rate' => $this->mrr->growthRate(),
        ];
    }

    /** @return array<string, mixed> */
    protected function pipeline(Carbon $from, Carbon $to): array
    {
        $created = Lead::query()->whereBetween('created_at', [$from, $to]);

        return [
            'deals_created' => (clone $created)->count(),
            'deals_won' => Lead::query()->where('status', 'won')->whereBetween('updated_at', [$from, $to])->count(),
            'deals_lost' => Lead::query()->where('status', 'lost')->whereBetween('updated_at', [$from, $to])->count(),
            'open_deals' => Lead::query()->whereNotIn('status', ['won', 'lost'])->count(),
        ];
    }

    /** @return array<string, mixed> */
    protected function customers(Carbon $from, Carbon $to): array
    {
        $active = CompanySubscription::query()->where('status', SubscriptionStatus::Active->value);

        return [
            'accounts' => Organization::query()->count(),
            'active_subscriptions' => (clone $active)->count(),
            'new_subscriptions' => CompanySubscription::query()->whereBetween('starts_at', [$from, $to])->count(),
            'expiring_soon' => CompanySubscription::query()
                ->where('status', SubscriptionStatus::Active->value)
                ->whereBetween('ends_at', [now(), now()->addDays(60)])
                ->count(),
        ];
    }

    /** @return array<string, mixed> */
    protected function spend(Carbon $from, Carbon $to): array
    {
        return [
            'expenses_paid' => (float) CompanyExpense::query()
                ->where('status', ExpenseStatus::Paid->value)
                ->whereBetween('paid_at', [$from, $to])
                ->sum('amount'),
            'expenses_committed' => (float) CompanyExpense::query()->committed()->sum('amount'),
            'awaiting_approval' => (float) CompanyExpense::query()->awaitingApproval()->sum('amount'),
            'by_category' => CompanyExpense::query()
                ->committed()
                ->selectRaw('category, SUM(amount) as total')
                ->groupBy('category')
                ->pluck('total', 'category')
                ->map(fn ($total) => (float) $total),
        ];
    }

    /** @return array<string, mixed> */
    protected function people(Carbon $from, Carbon $to): array
    {
        return [
            'headcount' => CompanyEmployee::query()->count(),
            'active' => CompanyEmployee::query()->where('employment_status', 'active')->count(),
            'leave_requests' => CompanyLeaveRequest::query()->whereBetween('start_date', [$from, $to])->count(),
            'leave_days_approved' => (float) CompanyLeaveRequest::query()
                ->where('status', LeaveRequestStatus::Approved->value)
                ->whereBetween('start_date', [$from, $to])
                ->sum('total_days'),
            'leave_pending' => CompanyLeaveRequest::query()->pending()->count(),
        ];
    }

    /** @return array<string, mixed> */
    protected function support(Carbon $from, Carbon $to): array
    {
        $opened = CompanySupportTicket::query()->whereBetween('created_at', [$from, $to]);

        $resolved = CompanySupportTicket::query()
            ->whereNotNull('resolved_at')
            ->whereBetween('resolved_at', [$from, $to])
            ->get(['created_at', 'resolved_at']);

        return [
            'opened' => (clone $opened)->count(),
            'resolved' => $resolved->count(),
            'open_now' => CompanySupportTicket::query()->open()->count(),
            /*
             * Null rather than zero when nothing has been resolved — an average
             * over no tickets is not "immediately".
             */
            'median_resolution_hours' => $resolved->isEmpty() ? null : $this->medianHours($resolved),
            /*
             * Breaches only count where a target exists. With no SLA configured
             * this is null, not "none" — nobody has said what late means.
             */
            'sla_breaches' => $this->slaBreaches(),
        ];
    }

    /** @param  Collection<int, CompanySupportTicket>  $tickets */
    protected function medianHours($tickets): float
    {
        $hours = $tickets
            ->map(fn (CompanySupportTicket $ticket) => $ticket->created_at->diffInMinutes($ticket->resolved_at) / 60)
            ->sort()
            ->values();

        $count = $hours->count();
        $middle = (int) floor($count / 2);

        return round($count % 2 === 1 ? $hours[$middle] : ($hours[$middle - 1] + $hours[$middle]) / 2, 1);
    }

    protected function slaBreaches(): ?int
    {
        $withTargets = CompanySupportTicket::query()->whereNotNull('resolution_due_at')->get();

        if ($withTargets->isEmpty()) {
            return null;
        }

        return $withTargets->filter(fn (CompanySupportTicket $ticket) => $ticket->breachedResolution() === true)->count();
    }

    /**
     * The same report, flattened for a spreadsheet.
     *
     * @return list<array{domain: string, metric: string, value: string}>
     */
    public function toRows(array $report): array
    {
        $rows = [];

        foreach (['revenue', 'pipeline', 'customers', 'spend', 'people', 'support'] as $domain) {
            foreach ($report[$domain] as $metric => $value) {
                if (is_iterable($value)) {
                    foreach ($value as $key => $inner) {
                        $rows[] = ['domain' => $domain, 'metric' => "{$metric}.{$key}", 'value' => (string) $inner];
                    }

                    continue;
                }

                /* Null stays "not available" rather than becoming a zero. */
                $rows[] = [
                    'domain' => $domain,
                    'metric' => $metric,
                    'value' => $value === null ? 'not available' : (string) $value,
                ];
            }
        }

        return $rows;
    }
}
