<?php

declare(strict_types=1);

namespace App\Services\CustomerSuccess;

use App\Models\User;
use App\Support\CrmScope;

/**
 * The state of the customer book, in one pass.
 *
 * Customer Success had five lists and no way to see across them: whether the
 * month was in trouble, how much of the book was at risk, what was renewing.
 * A rep opening the module landed on 120,000 accounts sorted by name.
 *
 * Everything here reads `customer_success_snapshots`, which the nightly
 * projection already maintains, and aggregates it in SQL. Nothing is computed
 * per account — a page that walked the book to summarise it would be the one
 * thing this module cannot afford.
 */
class OverviewService
{
    /**
     * The headline figures: how big the book is and what it is worth.
     *
     * One query. `mrr` is summed only where it was recorded — an unrecorded
     * figure is unknown, not zero, and averaging a column of invented zeroes
     * is how a revenue number stops meaning anything.
     *
     * @return array<string, mixed>
     */
    public function kpis(?User $user): array
    {
        $row = $this->scopedSnapshots($user)
            ->selectRaw(<<<'SQL'
                COUNT(*) AS accounts,
                SUM(CASE WHEN css.has_subscription = 1 THEN 1 ELSE 0 END) AS paying,
                SUM(CASE WHEN css.mrr IS NOT NULL THEN css.mrr ELSE 0 END) AS mrr,
                SUM(CASE WHEN css.mrr IS NULL AND css.has_subscription = 1 THEN 1 ELSE 0 END) AS mrr_unrecorded,
                SUM(CASE WHEN css.days_to_expiry BETWEEN 0 AND 30 THEN 1 ELSE 0 END) AS renewing_30,
                SUM(CASE WHEN css.days_to_expiry < 0 THEN 1 ELSE 0 END) AS overdue,
                SUM(CASE WHEN LOWER(css.churn_risk) IN ('high', 'critical') THEN 1 ELSE 0 END) AS at_risk,
                SUM(CASE WHEN css.mrr IS NOT NULL AND LOWER(css.churn_risk) IN ('high', 'critical') THEN css.mrr ELSE 0 END) AS mrr_at_risk
            SQL)
            ->first();

        $mrr = (float) ($row->mrr ?? 0);

        return [
            'accounts' => (int) ($row->accounts ?? 0),
            'paying' => (int) ($row->paying ?? 0),
            'mrr' => $mrr,
            'arr' => round($mrr * 12, 2),
            /* Said out loud rather than hidden, so the figure above is honest. */
            'mrr_unrecorded' => (int) ($row->mrr_unrecorded ?? 0),
            'renewing_30' => (int) ($row->renewing_30 ?? 0),
            'overdue' => (int) ($row->overdue ?? 0),
            'at_risk' => (int) ($row->at_risk ?? 0),
            'mrr_at_risk' => (float) ($row->mrr_at_risk ?? 0),
        ];
    }

    /**
     * What needs somebody today, worst first.
     *
     * Each row is a count and a link into the list that holds them — the
     * Overview says how many and where, never repeats the list itself. Two
     * places rendering the same rows is how their counts start disagreeing.
     *
     * @return list<array<string, mixed>>
     */
    public function attention(?User $user): array
    {
        $row = $this->scopedSnapshots($user)
            ->selectRaw(<<<'SQL'
                SUM(CASE WHEN css.days_to_expiry < 0 THEN 1 ELSE 0 END) AS overdue,
                SUM(CASE WHEN css.days_to_expiry BETWEEN 0 AND 30 THEN 1 ELSE 0 END) AS renewing_30,
                SUM(CASE WHEN LOWER(css.churn_risk) IN ('high', 'critical') THEN 1 ELSE 0 END) AS at_risk,
                SUM(CASE WHEN css.purchased_seats > 0 AND css.used_seats > css.purchased_seats THEN 1 ELSE 0 END) AS over_capacity,
                SUM(CASE WHEN css.onboarding_status IS NOT NULL AND css.onboarding_status <> 'completed' THEN 1 ELSE 0 END) AS onboarding,
                SUM(CASE WHEN organizations.assigned_user_id IS NULL THEN 1 ELSE 0 END) AS unowned
            SQL)
            ->first();

        $items = [
            [
                'key' => 'overdue',
                'count' => (int) ($row->overdue ?? 0),
                'severity' => 'high',
                'label' => 'past their renewal date',
                'detail' => 'Outside the term they paid for.',
                'href' => route('company.customer-success.renewals.index', ['horizon' => 'overdue']),
            ],
            [
                'key' => 'at_risk',
                'count' => (int) ($row->at_risk ?? 0),
                'severity' => 'high',
                'label' => 'at risk of leaving',
                'detail' => 'Churn risk is high, derived from their own signals.',
                'href' => route('company.customer-success.accounts.index', ['view' => 'at_risk']),
            ],
            [
                'key' => 'over_capacity',
                'count' => (int) ($row->over_capacity ?? 0),
                'severity' => 'medium',
                'label' => 'using more than they bought',
                'detail' => 'An upgrade conversation, not a problem.',
                'href' => route('company.customer-success.usage.index'),
            ],
            [
                'key' => 'renewing_30',
                'count' => (int) ($row->renewing_30 ?? 0),
                'severity' => 'medium',
                'label' => 'renewing within 30 days',
                'detail' => 'Confirm they are staying before the date passes.',
                'href' => route('company.customer-success.renewals.index', ['horizon' => '30']),
            ],
            [
                'key' => 'onboarding',
                'count' => (int) ($row->onboarding ?? 0),
                'severity' => 'low',
                'label' => 'still being set up',
                'detail' => 'Paid for, not yet live. Nothing they can renew until this finishes.',
                'href' => route('company.customer-success.accounts.index', ['view' => 'onboarding']),
            ],
            [
                'key' => 'unowned',
                'count' => (int) ($row->unowned ?? 0),
                'severity' => 'low',
                'label' => 'have no owner',
                'detail' => 'They appear in nobody\'s book until somebody owns them.',
                'href' => route('company.customer-success.accounts.index'),
            ],
        ];

        /* Only what is actually happening. A row of zeroes is furniture. */
        return array_values(array_filter($items, fn (array $item) => $item['count'] > 0));
    }

    /**
     * The named accounts somebody should open next, worst first.
     *
     * Everything else on this page is an aggregate: it says how many, never
     * which. A rep cannot act on "four at risk" — they act on "Apex Dental,
     * because it lapsed nine days ago, renew it". So this is the one place the
     * overview names customers, and every row carries the reason it is here and
     * the single thing to do about it.
     *
     * Priority is decided in SQL and the list is capped, so the cost does not
     * move with the size of the book: past the renewal date first, then at
     * risk, then renewing soon, then over capacity, then stuck in setup, then
     * unowned. Value breaks the tie inside a band — a lapsed hospital outranks
     * a lapsed single chair.
     *
     * @return list<array<string, mixed>>
     */
    public function workQueue(?User $user, int $limit = 8): array
    {
        $priority = <<<'SQL'
            CASE
                WHEN css.days_to_expiry < 0 THEN 1
                WHEN LOWER(css.churn_risk) IN ('high', 'critical') THEN 2
                WHEN css.days_to_expiry BETWEEN 0 AND 30 THEN 3
                WHEN css.purchased_seats > 0 AND css.used_seats > css.purchased_seats THEN 4
                WHEN css.onboarding_status IS NOT NULL AND css.onboarding_status <> 'completed' THEN 5
                ELSE 6
            END
        SQL;

        $rows = $this->scopedSnapshots($user)
            ->leftJoin('users', 'users.id', '=', 'organizations.assigned_user_id')
            ->where(function ($query) {
                $query->where('css.days_to_expiry', '<', 0)
                    ->orWhereRaw("LOWER(css.churn_risk) IN ('high', 'critical')")
                    ->orWhereBetween('css.days_to_expiry', [0, 30])
                    ->orWhereRaw('css.purchased_seats > 0 AND css.used_seats > css.purchased_seats')
                    ->orWhere(function ($sub) {
                        $sub->whereNotNull('css.onboarding_status')
                            ->where('css.onboarding_status', '<>', 'completed');
                    })
                    ->orWhereNull('organizations.assigned_user_id');
            })
            /*
             * Churn arrives under an alias of its own. These rows come back as
             * Organization models, and a column the model casts would be handed
             * over as the cast value rather than as the string the database
             * holds — so `css.churn_risk` selected under its own name becomes an
             * enum that cannot be compared as text.
             */
            ->selectRaw($priority.' AS priority, LOWER(css.churn_risk) AS churn_key')
            ->addSelect([
                'organizations.id',
                'organizations.name',
                'users.name as owner_name',
                'css.mrr',
                'css.days_to_expiry',
                'css.health_score',
                'css.purchased_seats',
                'css.used_seats',
                'css.onboarding_status',
                'css.onboarding_current_step',
            ])
            ->orderByRaw($priority)
            ->orderByRaw('COALESCE(css.mrr, 0) DESC')
            ->limit($limit)
            ->get();

        return $rows->map(function ($row) {
            $days = $row->days_to_expiry !== null ? (int) $row->days_to_expiry : null;
            $account = route('company.customer-success.accounts.show', $row->id);

            /*
             * One reason and one action per row, chosen by the same ladder the
             * ordering used. A row offering three possible next steps is a row
             * nobody takes a step on.
             */
            [$reason, $action, $href, $tone] = match (true) {
                $days !== null && $days < 0 => [
                    abs($days).' days past the renewal date',
                    'Renew',
                    $account.'?tab=contract',
                    'bad',
                ],
                in_array((string) $row->churn_key, ['high', 'critical'], true) => [
                    'At risk — health '.((int) $row->health_score).'/100',
                    'Open account',
                    $account,
                    'bad',
                ],
                $days !== null && $days <= 30 => [
                    'Renews in '.$days.' days',
                    'Confirm renewal',
                    $account.'?tab=contract',
                    'warn',
                ],
                (int) $row->purchased_seats > 0 && (int) $row->used_seats > (int) $row->purchased_seats => [
                    'Using '.((int) $row->used_seats).' of '.((int) $row->purchased_seats).' seats',
                    'Add seats',
                    $account.'?tab=contract',
                    'warn',
                ],
                $row->onboarding_status !== null && $row->onboarding_status !== 'completed' => [
                    'Still in setup — '.str_replace('_', ' ', (string) ($row->onboarding_current_step ?? 'not started')),
                    'Continue setup',
                    $account.'?tab=setup',
                    'info',
                ],
                default => [
                    'Nobody owns this account',
                    'Assign an owner',
                    $account,
                    'neutral',
                ],
            };

            return [
                'id' => (int) $row->id,
                'name' => $row->name,
                'owner' => $row->owner_name,
                'mrr' => $row->mrr !== null ? (float) $row->mrr : null,
                'reason' => $reason,
                'action' => $action,
                'href' => $href,
                'tone' => $tone,
            ];
        })->values()->all();
    }

    /**
     * How the book is spread across health and stage.
     *
     * A single "at risk" count says how many are bad; it does not say whether
     * the book is mostly healthy with a bad tail or mostly mediocre. Two
     * distributions answer that in one glance, and both are one grouped scan.
     *
     * @return array<string, mixed>
     */
    public function distribution(?User $user): array
    {
        $health = $this->scopedSnapshots($user)
            ->selectRaw(<<<'SQL'
                SUM(CASE WHEN css.health_score >= 80 THEN 1 ELSE 0 END) AS healthy,
                SUM(CASE WHEN css.health_score >= 50 AND css.health_score < 80 THEN 1 ELSE 0 END) AS watch,
                SUM(CASE WHEN css.health_score < 50 THEN 1 ELSE 0 END) AS poor
            SQL)
            ->first();

        $stages = $this->scopedSnapshots($user)
            ->selectRaw('css.stage, COUNT(*) AS accounts')
            ->groupBy('css.stage')
            ->pluck('accounts', 'stage');

        return [
            'health' => [
                ['key' => 'healthy', 'label' => 'Healthy', 'count' => (int) ($health->healthy ?? 0)],
                ['key' => 'watch', 'label' => 'Worth watching', 'count' => (int) ($health->watch ?? 0)],
                ['key' => 'poor', 'label' => 'In trouble', 'count' => (int) ($health->poor ?? 0)],
            ],
            /*
             * Ordered by where an account sits in its life rather than by
             * count, so the shape of the book reads left to right.
             */
            'stages' => collect(['not_provisioned', 'onboarding', 'trialling', 'grace', 'live', 'lapsed'])
                ->map(fn (string $stage) => [
                    'key' => $stage,
                    'label' => ucfirst(str_replace('_', ' ', $stage)),
                    'count' => (int) ($stages[$stage] ?? 0),
                ])
                ->filter(fn (array $row) => $row['count'] > 0)
                ->values()
                ->all(),
        ];
    }

    /**
     * What is renewing, month by month, for the next half year.
     *
     * Grouped in SQL. The point of the shape is that a rep can see whether next
     * month is heavier than this one before it arrives.
     *
     * @return list<array<string, mixed>>
     */
    public function renewalHorizon(?User $user): array
    {
        $query = $this->scopedSnapshots($user);

        /*
         * Grouping by month needs the one function whose name differs between
         * the engines this runs on. Writing MySQL's spelling only meant the
         * whole deferred group threw on SQLite, so nothing under here could be
         * tested at all — the aggregates were verified by hand instead.
         */
        $month = $query->getConnection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', css.ends_at)"
            : "DATE_FORMAT(css.ends_at, '%Y-%m')";

        $rows = $query
            ->whereNotNull('css.ends_at')
            ->whereBetween('css.ends_at', [now()->startOfMonth(), now()->addMonths(6)->endOfMonth()])
            ->selectRaw("{$month} AS month, COUNT(*) AS accounts, SUM(COALESCE(css.mrr, 0)) AS mrr")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return $rows->map(fn ($row) => [
            'month' => $row->month,
            'label' => now()->parse($row->month.'-01')->format('M Y'),
            'accounts' => (int) $row->accounts,
            'mrr' => (float) $row->mrr,
        ])->values()->all();
    }

    /**
     * Customer accounts this reader may see, joined to their projection.
     *
     * Every figure on the Overview starts here, so the scope boundary is
     * applied once rather than remembered six times.
     */
    private function scopedSnapshots(?User $user)
    {
        return CrmScope::customerAccounts($user)
            ->join('customer_success_snapshots as css', 'css.organization_id', '=', 'organizations.id');
    }
}
