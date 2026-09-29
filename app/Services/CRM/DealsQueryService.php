<?php

namespace App\Services\CRM;

use App\LeadPriority;
use App\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use App\Support\CrmScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one Deals query.
 *
 * Leads and Pipeline were two payload builders over one table, with two filter
 * engines, two stage vocabularies and three definitions of "stalled".
 *
 * Every Deals view — Inbox, All, Lost — and the stage rail read from here, so a
 * predicate has exactly one meaning wherever it is applied.
 */
class DealsQueryService
{
    /** @var list<string> */
    public const VIEWS = ['inbox', 'all', 'mine', 'unassigned', 'at_risk', 'stale', 'trial_active', 'payment_pending', 'lost'];

    /**
     * The saved views, defined once so the rail, the counts and the query can
     * never disagree about what a view means.
     *
     * `inbox` stays in VIEWS and out of this list: it is pinned by
     * Phase3DealsConsolidationTest and still reachable by URL, but it is not a
     * view anybody picks — "unqualified" is a filter, not a destination.
     *
     * @var list<array{key:string, label:string, hint:string}>
     */
    public const SAVED_VIEWS = [
        ['key' => 'all', 'label' => 'All deals', 'hint' => 'Everything you can see'],
        ['key' => 'mine', 'label' => 'Mine', 'hint' => 'Assigned to you'],
        ['key' => 'unassigned', 'label' => 'Unassigned', 'hint' => 'Open, with no owner'],
        ['key' => 'at_risk', 'label' => 'At risk', 'hint' => 'Overdue follow-up'],
        ['key' => 'stale', 'label' => 'Stale', 'hint' => 'No stage change for weeks'],
        ['key' => 'trial_active', 'label' => 'Trial running', 'hint' => 'A trial is live'],
        ['key' => 'payment_pending', 'label' => 'Payment pending', 'hint' => 'Approved, not yet paid'],
        ['key' => 'lost', 'label' => 'Lost', 'hint' => 'Closed without a sale'],
    ];

    /**
     * Statuses that are still live pipeline.
     *
     * @var list<string>
     */
    public const OPEN_STATUSES = [
        /* `assigned` is retired — see `LeadStatus::RETIRED`. */
        'new', 'contacted', 'demo_scheduled', 'demo_completed',
        'trial_active', 'negotiation', 'quotation_sent', 'quotation_approved',
        'payment_received',
    ];

    /**
     * Stages at which a deal has not been qualified yet.
     *
     * Mirrors DealStageService::UNQUALIFIED — qualification is that service's
     * concept, this only filters on it.
     *
     * @var list<string>
     */
    public const UNQUALIFIED_STATUSES = DealStageService::UNQUALIFIED;

    /**
     * The scoped, filtered Deals query for a view.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Lead>
     */
    public function query(?User $user, array $filters = []): Builder
    {
        // Starts from CrmScope, the Phase 1 authority — so tenant and rep
        // boundaries are identical on every view by construction.
        $query = CrmScope::leads($user);

        $view = $filters['view'] ?? 'all';

        /*
         * "Mine" is the one view that needs to know who is asking, and
         * applyView is deliberately user-blind so a view can never mean two
         * things. It is expressed as the filter that already exists instead.
         */
        if ($view === 'mine') {
            $filters['mine_only'] = true;
        }

        $this->applyView($query, $view);
        $this->applyFilters($query, $filters, $user);
        $this->applySort($query, $filters);

        return $query;
    }

    /**
     * Which slice of the lifecycle a view shows.
     *
     * @param  Builder<Lead>  $query
     */
    protected function applyView(Builder $query, string $view): void
    {
        match ($view) {
            // Deals still awaiting a decision about whether they are real.
            'inbox' => $query
                ->whereIn('status', self::UNQUALIFIED_STATUSES)
                ->whereNull('qualified_at'),

            'lost' => $query->where('status', LeadStatus::Lost->value),

            'unassigned' => $query->whereNull('assigned_user_id')->whereIn('status', self::OPEN_STATUSES),

            'at_risk' => $query->whereIn('status', self::OPEN_STATUSES)
                ->whereNotNull('next_followup_at')
                ->where('next_followup_at', '<', now()),

            'stale' => $query->staleFor((int) config('crm.stale_lead_days', 14)),

            'trial_active' => $query->whereHas('trials', fn (Builder $t) => $t->where('status', 'active')),

            /*
             * Approved and awaiting the money. Deliberately not "stage equals
             * payment_received" — that stage means the payment has arrived, so
             * the deals still waiting for it are the approved ones without a
             * settled payment against them.
             */
            'payment_pending' => $query
                ->whereIn('status', [LeadStatus::QuotationApproved->value, LeadStatus::PaymentReceived->value])
                ->whereDoesntHave('payments', fn (Builder $p) => $p->where('status', 'successful')),

            default => $query,
        };
    }

    /**
     * @param  Builder<Lead>  $query
     * @param  array<string, mixed>  $filters
     */
    protected function applyFilters(Builder $query, array $filters, ?User $user = null): void
    {
        if (filled($filters['search'] ?? null)) {
            $search = trim((string) $filters['search']);

            // Grouped so an `orWhere` cannot escape the tenant predicate.
            $query->where(function (Builder $q) use ($search) {
                $q->where('company_name', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhereHas('assignedUser', fn (Builder $uq) => $uq->where('name', 'like', "%{$search}%"));
            });
        }

        /*
         * One chip, one or more stages.
         *
         * The rail groups the twelve statuses into the eleven steps people
         * actually name — "Quotation" covers both sent and approved, "Demo"
         * covers scheduled and completed — so the filter accepts a comma-
         * separated list. A single value still behaves exactly as before, and
         * the advanced filter can still name one exact status.
         */
        if ($this->isSet($filters, 'stage')) {
            $stages = array_values(array_filter(
                array_map('trim', explode(',', (string) $filters['stage']))
            ));

            count($stages) > 1
                ? $query->whereIn('status', $stages)
                : $query->where('status', $stages[0] ?? $filters['stage']);
        }

        if ($this->isSet($filters, 'source')) {
            $query->where('source', $filters['source']);
        }

        if ($this->isSet($filters, 'priority')) {
            $query->where('priority', $filters['priority']);
        }

        if ($this->isSet($filters, 'organization_type')) {
            $query->where('organization_type', $filters['organization_type']);
        }

        /*
         * What the practice does, matched inside the JSON array.
         *
         * `healthcare_specialties` holds every specialty a practice runs, so a
         * cardiology institute that also does imaging must answer to both
         * filters. `whereJsonContains` is the only correct predicate here — an
         * equality check would match single-specialty practices only, which is
         * most of the book and none of the interesting deals.
         */
        if ($this->isSet($filters, 'specialty')) {
            $query->whereJsonContains('healthcare_specialties', $filters['specialty']);
        }

        if ($this->isSet($filters, 'facility_type')) {
            $query->where('facility_type', $filters['facility_type']);
        }

        if ($this->isSet($filters, 'city')) {
            $query->where('city', $filters['city']);
        }

        if ($this->isSet($filters, 'state')) {
            $query->where('state', $filters['state']);
        }

        if ($this->isSet($filters, 'assigned_user_id')) {
            $filters['assigned_user_id'] === 'unassigned'
                ? $query->whereNull('assigned_user_id')
                : $query->where('assigned_user_id', (int) $filters['assigned_user_id']);
        }

        if ($this->truthy($filters, 'unassigned_only')) {
            $query->whereNull('assigned_user_id')->whereIn('status', self::OPEN_STATUSES);
        }

        /*
         * "My deals" is a cut of the same dataset, not a separate view — which
         * is why it is a chip beside the other quick filters rather than a tab.
         * It is also the one filter a rep uses every day, so it does not belong
         * behind the Filters button.
         */
        if ($this->truthy($filters, 'mine_only') && $user) {
            $query->where('assigned_user_id', $user->id);
        }

        if ($this->truthy($filters, 'overdue_only')) {
            $query->whereNotNull('next_followup_at')->where('next_followup_at', '<', now());
        }

        if ($this->truthy($filters, 'hot_only')) {
            $query->where('priority', LeadPriority::High->value);
        }

        if ($this->truthy($filters, 'stalled_only')) {
            $query->staleFor($this->stalledThresholdDays());
        }

        /*
         * Both of these back an Overview attention row. Every figure on that
         * page opens the rows it counted, so the definitions here must match
         * CrmMetricsService::attention() exactly — a count that does not agree
         * with the list it opens is worse than no count at all.
         */
        if ($this->truthy($filters, 'missing_value')) {
            $query->whereIn('status', self::OPEN_STATUSES)
                ->where(function (Builder $inner) {
                    $inner->whereNull('expected_mrr')->orWhere('expected_mrr', '<=', 0);
                })
                ->whereNull('expected_arr');
        }

        /*
         * A floor, not a range. Only 9 of 14 deals carry a value at all, so a
         * two-ended slider would spend most of its width on an empty domain;
         * "worth at least X" is the question a rep actually asks. Deals with no
         * value are excluded rather than treated as zero — `missing_value` is
         * the filter for finding those.
         */
        if (filled($filters['value_min'] ?? null)) {
            $floor = (float) $filters['value_min'];
            $query->whereRaw('COALESCE(NULLIF(expected_arr, 0), expected_mrr * 12) >= CAST(? AS DECIMAL(15,2))', [$floor]);
        }

        /*
         * There is deliberately no `no_next_action` filter to match it.
         * LeadObserver writes `next_best_action` through NextBestActionService
         * on every save, so an open deal always carries one — the filter could
         * only ever return an empty list, and the Overview row that would have
         * linked to it could only ever read zero.
         */

        /*
         * Team, which the schema has always carried and no filter ever used.
         * `leads.assigned_team_id` is populated by round-robin routing, so a
         * manager could see a rep's book but never a team's.
         */
        if ($this->isSet($filters, 'assigned_team_id')) {
            $query->where('assigned_team_id', (int) $filters['assigned_team_id']);
        }

        /*
         * Ageing, in days sitting in the current stage.
         *
         * `stalled_only` answers one fixed threshold; this answers "older than
         * N", which is the question a manager actually asks when the pipeline
         * review is weekly rather than fortnightly.
         */
        if (filled($filters['stage_age_min'] ?? null)) {
            $days = max(0, (int) $filters['stage_age_min']);
            $query->whereNotNull('stage_entered_at')
                ->where('stage_entered_at', '<=', now()->subDays($days));
        }

        /*
         * Whether the deal has reached each piece of commercial machinery.
         *
         * Existence, not stage: a deal can sit in Negotiation with a live trial
         * and an unsent quotation, and the stage column says none of that.
         */
        if ($this->isSet($filters, 'trial')) {
            $filters['trial'] === 'none'
                ? $query->whereDoesntHave('trials')
                : $query->whereHas('trials', fn (Builder $t) => $filters['trial'] === 'active'
                    ? $t->where('status', 'active')
                    : $t);
        }

        if ($this->isSet($filters, 'quotation')) {
            $filters['quotation'] === 'none'
                ? $query->whereDoesntHave('quotations')
                : $query->whereHas('quotations', fn (Builder $q) => $filters['quotation'] === 'approved'
                    ? $q->whereIn('status', ['approved', 'converted'])
                    : $q);
        }

        if ($this->isSet($filters, 'payment')) {
            $filters['payment'] === 'received'
                ? $query->whereHas('payments', fn (Builder $p) => $p->where('status', 'successful'))
                : $query->whereDoesntHave('payments', fn (Builder $p) => $p->where('status', 'successful'));
        }

        /* An upper bound, so "everything under a lakh" is expressible. */
        if (filled($filters['value_max'] ?? null)) {
            $query->whereRaw('COALESCE(NULLIF(expected_arr, 0), expected_mrr * 12) <= CAST(? AS DECIMAL(15,2))', [(float) $filters['value_max']]);
        }

        /* Silence, measured from the timeline rather than from `updated_at`. */
        if (filled($filters['quiet_for_days'] ?? null)) {
            $days = max(0, (int) $filters['quiet_for_days']);
            $cutoff = now()->subDays($days);

            $query->where(function (Builder $outer) use ($cutoff) {
                $outer->whereDoesntHave('activities')
                    ->orWhereDoesntHave('activities', fn (Builder $a) => $a->where('created_at', '>', $cutoff));
            });
        }

        if (filled($filters['created_from'] ?? null)) {
            $query->whereDate('created_at', '>=', $filters['created_from']);
        }

        if (filled($filters['created_to'] ?? null)) {
            $query->whereDate('created_at', '<=', $filters['created_to']);
        }
    }

    /**
     * @param  Builder<Lead>  $query
     * @param  array<string, mixed>  $filters
     */
    protected function applySort(Builder $query, array $filters): void
    {
        /*
         * `probability_to_close` and `stage_entered_at` were missing, which
         * removed the two orders a person actually works a pipeline by: show me
         * the likeliest, and show me the stuck. Both columns are populated on
         * every row, so neither sort can produce a half-empty list.
         */
        $allowed = [
            'company_name', 'contact_name', 'status', 'priority',
            'expected_mrr', 'expected_arr', 'lead_score',
            'probability_to_close', 'stage_entered_at',
            'next_followup_at', 'created_at', 'updated_at',
        ];

        $column = in_array($filters['sort'] ?? null, $allowed, true) ? $filters['sort'] : 'updated_at';
        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($column, $direction);
    }

    /**
     * THE stalled definition.
     *
     * A deal is stalled when it is still open and has sat in its current stage
     * longer than the configured threshold. That is `Lead::scopeStaleFor()` —
     * the predicate CrmMetricsService and Loss triage already use.
     *
     * An earlier version added `OR next_followup_at IS NULL`, which reported 12
     * of 12 live deals stalled. Since Phase 1 that column is derived from pending
     * follow-up tasks, so its absence means "no follow-up booked", not "stuck".
     * Booking a follow-up is a next action, not a measure of momentum.
     */
    public function isStalled(Lead $lead): bool
    {
        /*
         * Answered from the row, not with a query.
         *
         * This used to be `Lead::whereKey(...)->staleFor(...)->exists()`, called
         * once per row by the list mapper — twenty-five `SELECT EXISTS` on every
         * page of Deals, 25 of the page's 44 queries, and flat in nothing. The
         * predicate reads three attributes that are already loaded, so
         * `Lead::isStaleAfter()` gives the same answer for free. The scope
         * remains THE definition; that method is its in-memory twin.
         */
        return $lead->isStaleAfter($this->stalledThresholdDays());
    }

    public function stalledThresholdDays(): int
    {
        return (int) config('crm.stale_lead_days', 14);
    }

    /**
     * Headline counts for the workspace, from the same scoped query the list
     * uses — so a figure and the list beneath it cannot disagree.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function counts(?User $user, array $filters = []): array
    {
        /*
         * One count per saved view, taken from the same query that view
         * renders, so a chip and the list it opens can never disagree.
         *
         * Passing the caller's filters through is deliberate: the counts
         * describe what is currently being searched, not the whole book, so
         * narrowing the search narrows every chip with it.
         *
         * Sent as a single UNION ALL rather than nine round trips. The nine
         * builders are unchanged and still come from `query()` — this changes
         * only how many times the database is asked, not what it is asked. That
         * matters: re-expressing each view as a `SUM(CASE WHEN ...)` would have
         * been fewer queries too, and would have created a second definition of
         * every view for the counts to drift away from.
         */
        $keys = array_merge(
            array_column(self::SAVED_VIEWS, 'key'),
            ['inbox'],
        );

        $counts = $this->countViews($user, $keys, $filters);

        // Kept under their old names for the Overview's deep links.
        return $counts + [
            'overdue' => $counts['at_risk'] ?? 0,
            'stalled' => $counts['stale'] ?? 0,
        ];
    }

    /**
     * Count several views in one round trip.
     *
     * Each view's own builder is reduced to `SELECT '<key>', COUNT(*)` and the
     * lot are stitched together with UNION ALL. `reorder()` is required: a sort
     * is meaningless inside an aggregated union branch, and MySQL rejects it.
     *
     * @param  list<string>  $views
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    protected function countViews(?User $user, array $views, array $filters = []): array
    {
        if ($views === []) {
            return [];
        }

        $union = null;

        foreach ($views as $view) {
            $branch = $this->query($user, ['view' => $view] + $filters)
                ->reorder()
                ->toBase()
                ->selectRaw('? as view_key, count(*) as aggregate', [$view]);

            $union === null ? $union = $branch : $union->unionAll($branch);
        }

        $rows = $union->get()->pluck('aggregate', 'view_key');

        // Every requested view answers, even one the union returned nothing for
        // — a missing chip reads as a broken page, a zero reads as an empty one.
        return collect($views)
            ->mapWithKeys(fn (string $view) => [$view => (int) ($rows[$view] ?? 0)])
            ->all();
    }

    /**
     * Count and value per stage, for the stage rail.
     *
     * Every filter the user has applied is honoured *except* `stage` itself —
     * otherwise selecting a stage would collapse the rail to that one chip and
     * there would be no way back. So the rail always answers "of what I am
     * currently looking at, how is it spread across stages".
     *
     * Stages come from LeadStatus, never a hard-coded list, and stages with no
     * deals are returned with a zero count so the rail shows the whole pipeline
     * rather than only the populated parts.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function stageBreakdown(?User $user, array $filters = []): array
    {
        $rows = $this->query($user, array_merge($filters, ['stage' => null]))
            ->reorder()
            ->selectRaw('status, count(*) as deal_count, coalesce(sum(coalesce(nullif(expected_arr, 0), expected_mrr * 12)), 0) as total_value, coalesce(sum(coalesce(nullif(expected_arr, 0), expected_mrr * 12) * probability_to_close / 100), 0) as weighted_value')
            ->groupBy('status')
            ->get()
            ->keyBy(fn ($row) => $row->status instanceof LeadStatus ? $row->status->value : (string) $row->status);

        return collect(LeadStatus::cases())
            ->map(fn (LeadStatus $stage) => [
                'value' => $stage->value,
                'label' => $stage->label(),
                'count' => (int) ($rows->get($stage->value)->deal_count ?? 0),
                'total_value' => round((float) ($rows->get($stage->value)->total_value ?? 0), 2),
                'weighted_value' => round((float) ($rows->get($stage->value)->weighted_value ?? 0), 2),
                'is_open' => in_array($stage->value, self::OPEN_STATUSES, true),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function isSet(array $filters, string $key): bool
    {
        return filled($filters[$key] ?? null) && $filters[$key] !== 'all';
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function truthy(array $filters, string $key): bool
    {
        return filter_var($filters[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
