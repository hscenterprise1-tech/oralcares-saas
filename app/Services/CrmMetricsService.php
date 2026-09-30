<?php

namespace App\Services;

use App\DemoStatus;
use App\LeadStatus;
use App\Models\Activity;
use App\Models\CommercialInvoice;
use App\Models\CompanyQuotation;
use App\Models\Demo;
use App\Models\HealthcareSpecialtyMaster;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Task;
use App\Models\Trial;
use App\Models\User;
use App\QuotationStatus;
use App\Services\CRM\TrialHealthService;
use App\Services\CRM\TrialLifecycleService;
use App\Services\Provisioning\EntitlementResolver;
use App\Support\CrmScope;
use App\Support\DealDomainPresenter;
use App\TaskStatus;
use App\TrialStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only aggregation for CRM management reporting.
 *
 * Every figure here is a database aggregate over a CrmScope-constrained query,
 * so a manager's numbers cover their organization and a rep's cover their own
 * book — the same boundary the record lists use. Nothing is estimated or
 * synthesised: where the underlying data cannot answer a question, the metric
 * is null and the caller reports it as unavailable rather than zero, because
 * "no cycle-time history yet" and "a cycle time of zero" mean opposite things
 * to a sales manager.
 *
 * Aggregates are grouped queries, never per-row loops — the previous team
 * performance block issued roughly seven queries per rep.
 */
class CrmMetricsService
{
    /**
     * Stages that are still live pipeline.
     *
     * @var list<LeadStatus>
     */
    public const OPEN_STAGES = [
        LeadStatus::New,
        LeadStatus::Assigned,
        LeadStatus::Contacted,
        LeadStatus::DemoScheduled,
        LeadStatus::DemoCompleted,
        LeadStatus::TrialActive,
        LeadStatus::Negotiation,
        LeadStatus::QuotationSent,
        LeadStatus::QuotationApproved,
        LeadStatus::PaymentReceived,
    ];

    /**
     * Headline KPI strip.
     *
     * @return array<string, mixed>
     */
    /**
     * Request-scoped memos for the two aggregates both `kpis()` and
     * `attention()` read. The service is resolved once per request, so these
     * live exactly as long as the page they serve.
     *
     * @var array{scheduled:int, conducted:int, missing_link:int}|null
     */
    private ?array $demosTodayCache = null;

    /** @var array{active:int, healthy:int, at_risk:int, expiring_48h:int, unmeasured:int}|null */
    private ?array $trialHealthCache = null;

    public function __construct(
        protected TrialLifecycleService $lifecycle,
        protected EntitlementResolver $entitlements,
        protected TrialHealthService $trialHealth,
    ) {}

    public function kpis(?User $user, ?Carbon $periodStart = null, ?Carbon $periodEnd = null): array
    {
        $periodStart ??= now()->startOfMonth();
        $periodEnd ??= now()->endOfMonth();

        $openStageValues = $this->openStageValues();

        $pipelineTotals = (clone $this->leads($user))
            ->whereIn('status', $openStageValues)
            ->selectRaw('count(*) as lead_count, coalesce(sum(expected_mrr), 0) as mrr, coalesce(sum(expected_arr), 0) as arr')
            ->first();

        $wonThisPeriod = (clone $this->leads($user))
            ->where('status', LeadStatus::Won)
            ->whereBetween('updated_at', [$periodStart, $periodEnd])
            ->count();

        $lostThisPeriod = (clone $this->leads($user))
            ->where('status', LeadStatus::Lost)
            ->whereBetween('updated_at', [$periodStart, $periodEnd])
            ->count();

        $demosToday = $this->demosTodaySplit($user);
        $trialHealth = $this->liveTrialHealth($user);

        return [
            'new_leads' => (clone $this->leads($user))
                ->whereBetween('created_at', [$periodStart, $periodEnd])
                ->count(),
            'active_pipeline_count' => (int) ($pipelineTotals->lead_count ?? 0),
            /*
             * The headline reads "N of M deals valued". M is every open deal;
             * N is only those contributing to the sum. Deriving N from the
             * stage rows counted every deal in any stage that held value, which
             * overstated it — a deal with no number of its own was reported as
             * if it had one.
             */
            'active_pipeline_valued_count' => (clone $this->leads($user))
                ->whereIn('status', $openStageValues)
                ->where(function (Builder $q) {
                    $q->where('expected_mrr', '>', 0)
                        ->orWhere('expected_arr', '>', 0);
                })
                ->count(),
            'active_pipeline_value' => $this->expectedRevenue(
                (float) ($pipelineTotals->mrr ?? 0),
                (float) ($pipelineTotals->arr ?? 0)
            ),
            'weighted_pipeline_value' => $this->weightedPipelineValue($user),
            /*
             * Today's demos, split by whether they have happened yet.
             *
             * "4 demos today" is a vanity counter — it is the same number at
             * nine in the morning and at six in the evening. Conducted against
             * scheduled is the operational reading: it says how much of the day
             * is left to run.
             */
            'demos_today' => $demosToday['scheduled'],
            'demos_today_conducted' => $demosToday['conducted'],
            /*
             * Booked with no way to join. Invisible until the morning of, and
             * the single commonest reason a demo does not happen.
             */
            'demos_today_missing_link' => $demosToday['missing_link'],

            'active_trials' => $trialHealth['active'],
            /*
             * Healthy is three or more prescriptions printed, matching
             * TrialHealthService::HEALTHY_PRESCRIPTIONS — signing in proves
             * somebody opened the product, printing a prescription proves they
             * used it on a patient.
             */
            'trials_healthy' => $trialHealth['healthy'],
            'trials_at_risk' => $trialHealth['at_risk'],
            'trials_expiring_48h' => $trialHealth['expiring_48h'],
            'open_quotations' => (clone $this->quotations($user))
                ->whereIn('status', [QuotationStatus::Draft, QuotationStatus::Sent])
                ->count(),
            'won_this_period' => $wonThisPeriod,
            'lost_this_period' => $lostThisPeriod,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
        ];
    }

    /**
     * Today's demos, split into what has happened and what has not.
     *
     * One grouped query rather than three counts. The three figures are three
     * readings of the same rows, and issuing a query each would have them drift
     * apart the moment a demo is completed between them.
     *
     * `missing_link` counts a booked demo nobody can join — no meeting link on a
     * remote demo, no address on a site visit. It is the commonest reason a
     * demo silently does not happen, and it is invisible until the morning of.
     *
     * @return array{scheduled:int, conducted:int, missing_link:int}
     */
    protected function demosTodaySplit(?User $user): array
    {
        /*
         * Memoised for the request. `kpis()` and `attention()` both need this,
         * and they run on the same page — computing it twice would not only
         * double the queries, it would let the metric card and the bottleneck
         * badge report different numbers if a demo completed between them.
         */
        if (isset($this->demosTodayCache)) {
            return $this->demosTodayCache;
        }

        $rows = (clone $this->demos($user))
            ->whereDate('scheduled_at', now()->toDateString())
            ->get(['id', 'status', 'meeting_platform', 'meeting_link', 'location']);

        $done = [DemoStatus::Completed->value, DemoStatus::NoShow->value];

        return $this->demosTodayCache = [
            'scheduled' => $rows->count(),
            'conducted' => $rows->filter(
                fn (Demo $demo) => in_array($this->statusValue($demo->status), $done, true)
            )->count(),
            /*
             * Only the ones still ahead of us. A demo that already happened
             * without a link is history; one at four o'clock is a problem.
             */
            'missing_link' => $rows->filter(
                fn (Demo $demo) => ! in_array($this->statusValue($demo->status), $done, true)
                    && $demo->joiningInstruction() === null
            )->count(),
        ];
    }

    /**
     * Live trials, by whether anyone is actually using them.
     *
     * Delegates the actual bucketing to {@see TrialHealthService}, the same
     * definition the Trials watchtower uses, so the two surfaces cannot
     * disagree about the same tenant at the same moment. Each trial's phase is
     * resolved through {@see TrialLifecycleService} — never the raw `status`
     * column, which can still say Active days after a trial's end date has
     * passed and the nightly scheduler hasn't run yet — and its "expiring"
     * window comes from {@see EntitlementResolver}, not the raw `end_date`,
     * so a won deal's paid term is never counted as an expiring trial.
     *
     * @return array{active:int, healthy:int, at_risk:int, expiring_48h:int, unmeasured:int}
     */
    protected function liveTrialHealth(?User $user): array
    {
        // Memoised for the same reason as demosTodaySplit().
        if (isset($this->trialHealthCache)) {
            return $this->trialHealthCache;
        }

        $trials = (clone $this->trials($user))
            ->with('clinic.organization')
            ->get(['id', 'status', 'lost_at', 'end_date', 'usage_telemetry', 'clinic_id', 'provisioned_at', 'extension_count', 'grace_ends_at']);

        $rows = $trials->map(function (Trial $trial) {
            $window = $trial->clinic ? $this->entitlements->forClinic($trial->clinic) : null;

            return [
                'phase' => $this->lifecycle->resolveStatus($trial)->value,
                'lost' => $trial->lost_at,
                'telemetry' => $trial->usage_telemetry ?: null,
                'windowState' => $window['window']['state'] ?? 'none',
                'daysRemaining' => $window['window']['days_remaining'] ?? null,
            ];
        });

        return $this->trialHealthCache = $this->trialHealth->aggregateFromRows($rows);
    }

    /**
     * Open pipeline value, split by what the practice actually does.
     *
     * Attributed to each deal's *primary* specialty — the first in its array —
     * rather than to all of them. A multi-specialty hospital carrying four
     * specialties would otherwise contribute its whole ARR four times, and the
     * split would sum to several times the pipeline it is splitting. Deals with
     * no specialty recorded are grouped under "Unclassified" rather than
     * dropped, because a missing classification is a prompt rather than a
     * reason to lose the money from the total.
     *
     * One query, grouped in PHP over a payload of two columns — the alternative
     * is driver-specific JSON extraction in SQL, and MySQL and SQLite disagree
     * about its syntax.
     *
     * @return list<array<string, mixed>>
     */
    public function specialtyRevenue(?User $user, int $limit = 6): array
    {
        $rows = (clone $this->leads($user))
            ->whereIn('status', $this->openStageValues())
            ->get(['id', 'healthcare_specialties', 'expected_mrr', 'expected_arr']);

        if ($rows->isEmpty()) {
            return [];
        }

        $catalogue = HealthcareSpecialtyMaster::catalogue();

        $grouped = $rows
            ->map(function (Lead $lead) use ($catalogue) {
                $primary = collect($lead->healthcare_specialties ?? [])->filter()->first();
                $entry = $primary ? $catalogue->get($primary) : null;

                return [
                    'key' => $entry['category'] ?? ($primary ? 'other' : 'unclassified'),
                    'label' => $entry['category_label'] ?? ($primary ? 'Other' : 'Unclassified'),
                    'tone' => $entry['tone'] ?? 'neutral',
                    'value' => $this->leadExpectedRevenue($lead),
                ];
            })
            ->groupBy('key')
            ->map(fn (Collection $group, string $key) => [
                'key' => $key,
                'label' => $group->first()['label'],
                'tone' => $group->first()['tone'],
                'count' => $group->count(),
                'value' => round($group->sum('value'), 2),
            ])
            ->sortByDesc('value')
            ->values();

        $total = $grouped->sum('value');

        return $grouped
            ->take($limit)
            ->map(fn (array $row) => $row + [
                // Share of the open pipeline, so a long row reads without
                // dividing two numbers in your head.
                'share' => $total > 0 ? (int) round(($row['value'] / $total) * 100) : 0,
            ])
            ->values()
            ->all();
    }

    /**
     * Per-stage counts, value, weighted value and step conversion.
     *
     * @return array<string, mixed>
     */
    public function pipelineHealth(?User $user): array
    {
        $rows = (clone $this->leads($user))
            ->selectRaw('status, count(*) as lead_count, coalesce(sum(expected_mrr), 0) as mrr, coalesce(sum(expected_arr), 0) as arr, coalesce(sum(expected_arr * probability_to_close), 0) as weighted_arr, coalesce(sum(expected_mrr * probability_to_close), 0) as weighted_mrr')
            ->groupBy('status')
            ->get()
            ->keyBy(fn ($row) => $row->status instanceof LeadStatus ? $row->status->value : (string) $row->status);

        $averageDurations = $this->averageStageDurations($user);

        $stages = [];
        foreach (LeadStatus::cases() as $stage) {
            $row = $rows->get($stage->value);
            $weighted = $this->expectedRevenue(
                (float) ($row->weighted_mrr ?? 0),
                (float) ($row->weighted_arr ?? 0)
            ) / 100;

            $stages[] = [
                'key' => $stage->value,
                'label' => $stage->label(),
                'count' => (int) ($row->lead_count ?? 0),
                'value' => $this->expectedRevenue((float) ($row->mrr ?? 0), (float) ($row->arr ?? 0)),
                'weighted_value' => round($weighted, 2),
                'average_duration_days' => $averageDurations[$stage->value]['average_days'] ?? null,
                'sample_size' => $averageDurations[$stage->value]['sample_size'] ?? 0,
                'is_terminal' => in_array($stage, [LeadStatus::Won, LeadStatus::Lost], true),
            ];
        }

        $funnel = $this->funnel($user);

        return [
            'stages' => $stages,
            'funnel' => $funnel,
            'biggest_drop' => $this->biggestDrop($funnel),
        ];
    }

    /**
     * The stages a lead has been through, not the stage it is sitting in.
     *
     * Counting by current status understated every step: a deal lost after a
     * demo stopped counting toward Contacted and Demo, so the funnel narrowed
     * for reasons that had nothing to do with conversion. A stage counts if the
     * lead ever reached it — `stage_durations` banks a key each time a stage is
     * left, and the current status supplies the stage it has not left yet.
     *
     * Trial is a branch, not a gate. Deals go Demo → Quotation without one, so
     * Quotation is measured against Demo; measuring it against Trial produced
     * conversions above 100% and a funnel that widened as it descended.
     *
     * @return list<array{stage: string, count: int, conversion: ?float, of: ?string}>
     */
    public function funnel(?User $user): array
    {
        $rows = (clone $this->leads($user))
            ->get(['status', 'stage_durations']);

        /** Every stage this lead has ever stood in. */
        $reached = $rows->map(function (Lead $lead): array {
            $banked = $lead->stage_durations;

            if (is_string($banked)) {
                $banked = json_decode($banked, true);
            }

            $stages = is_array($banked) ? array_keys($banked) : [];
            $stages[] = $this->statusValue($lead->status);

            return array_flip($stages);
        });

        $countOf = fn (array $stages): int => $reached
            ->filter(function (array $seen) use ($stages): bool {
                foreach ($stages as $stage) {
                    if (isset($seen[$stage])) {
                        return true;
                    }
                }

                return false;
            })
            ->count();

        $total = $rows->count();

        $counts = [
            'Leads' => $total,
            'Contacted' => $countOf([LeadStatus::Contacted->value]),
            'Demo' => $countOf([LeadStatus::DemoScheduled->value, LeadStatus::DemoCompleted->value]),
            'Trial' => $countOf([LeadStatus::TrialActive->value]),
            'Quotation' => $countOf([LeadStatus::QuotationSent->value, LeadStatus::QuotationApproved->value]),
            'Won' => $countOf([LeadStatus::Won->value]),
        ];

        /** Which step each step is measured against. */
        $measuredAgainst = [
            'Leads' => null,
            'Contacted' => 'Leads',
            'Demo' => 'Contacted',
            'Trial' => 'Demo',
            'Quotation' => 'Demo',
            'Won' => 'Quotation',
        ];

        $out = [];

        foreach ($counts as $stage => $count) {
            $of = $measuredAgainst[$stage];

            if ($of === null) {
                /* An undefined ratio is not a zero one. */
                $out[] = [
                    'stage' => $stage,
                    'count' => $count,
                    'conversion' => $count > 0 ? 100.0 : null,
                    'of' => null,
                ];

                continue;
            }

            $denominator = $counts[$of];

            $out[] = [
                'stage' => $stage,
                'count' => $count,
                'conversion' => $denominator > 0 ? round(($count / $denominator) * 100, 1) : null,
                'of' => $of,
            ];
        }

        return $out;
    }

    /**
     * The funnel step losing the most, measured against its own denominator.
     *
     * Trial is excluded: it is an optional branch, and a pipeline that skips it
     * would otherwise always report Trial as the biggest loss when nothing has
     * been lost at all. On a tie the deeper step wins — the later a deal dies,
     * the more it cost to get there.
     *
     * Null when every denominator is empty. Printing 0% with nothing to divide
     * by would be a fabricated finding.
     *
     * @param  list<array{stage: string, count: int, conversion: ?float, of: ?string}>  $funnel
     * @return array{from: string, to: string, conversion: float, reached: int, of: int}|null
     */
    protected function biggestDrop(array $funnel): ?array
    {
        $byStage = collect($funnel)->keyBy('stage');
        $worst = null;

        foreach ($funnel as $row) {
            if ($row['of'] === null || $row['stage'] === 'Trial' || $row['conversion'] === null) {
                continue;
            }

            $candidate = [
                'from' => $row['of'],
                'to' => $row['stage'],
                'conversion' => $row['conversion'],
                'reached' => $row['count'],
                'of' => (int) $byStage[$row['of']]['count'],
            ];

            if ($worst === null || $candidate['conversion'] <= $worst['conversion']) {
                $worst = $candidate;
            }
        }

        return $worst;
    }

    /**
     * Weighted pipeline: expected revenue discounted by close probability.
     *
     * @return array<string, mixed>
     */
    public function forecast(?User $user): array
    {
        $openStageValues = $this->openStageValues();

        $totals = (clone $this->leads($user))
            ->whereIn('status', $openStageValues)
            ->selectRaw('coalesce(sum(expected_mrr), 0) as mrr, coalesce(sum(expected_arr), 0) as arr, coalesce(sum(expected_mrr * probability_to_close), 0) as weighted_mrr, coalesce(sum(expected_arr * probability_to_close), 0) as weighted_arr, count(*) as lead_count')
            ->first();

        $byStage = (clone $this->leads($user))
            ->whereIn('status', $openStageValues)
            ->selectRaw('status, count(*) as lead_count, coalesce(sum(expected_mrr), 0) as mrr, coalesce(sum(expected_arr), 0) as arr, coalesce(sum(expected_mrr * probability_to_close), 0) as weighted_mrr, coalesce(sum(expected_arr * probability_to_close), 0) as weighted_arr')
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => [
                'stage' => $row->status instanceof LeadStatus ? $row->status->value : (string) $row->status,
                'label' => $row->status instanceof LeadStatus ? $row->status->label() : (string) $row->status,
                'count' => (int) $row->lead_count,
                'expected_value' => $this->expectedRevenue((float) $row->mrr, (float) $row->arr),
                'weighted_value' => round($this->expectedRevenue((float) $row->weighted_mrr, (float) $row->weighted_arr) / 100, 2),
            ])
            ->values()
            ->all();

        $byRep = (clone $this->leads($user))
            ->whereIn('status', $openStageValues)
            ->whereNotNull('assigned_user_id')
            ->selectRaw('assigned_user_id, count(*) as lead_count, coalesce(sum(expected_mrr), 0) as mrr, coalesce(sum(expected_arr), 0) as arr, coalesce(sum(expected_mrr * probability_to_close), 0) as weighted_mrr, coalesce(sum(expected_arr * probability_to_close), 0) as weighted_arr')
            ->groupBy('assigned_user_id')
            ->get();

        $repNames = User::whereIn('id', $byRep->pluck('assigned_user_id'))->pluck('name', 'id');

        $byPeriod = (clone $this->leads($user))
            ->whereIn('status', $openStageValues)
            ->whereNotNull('expected_go_live_date')
            ->get(['expected_go_live_date', 'expected_mrr', 'expected_arr', 'probability_to_close'])
            ->groupBy(fn (Lead $lead) => $lead->expected_go_live_date?->format('Y-m'))
            ->map(fn (Collection $group, string $period) => [
                'period' => $period,
                'count' => $group->count(),
                'expected_value' => round($group->sum(fn (Lead $l) => $this->leadExpectedRevenue($l)), 2),
                'weighted_value' => round($group->sum(fn (Lead $l) => $this->leadWeightedValue($l)), 2),
            ])
            ->sortKeys()
            ->values()
            ->all();

        return [
            'open_deal_count' => (int) ($totals->lead_count ?? 0),
            'expected_pipeline' => $this->expectedRevenue((float) ($totals->mrr ?? 0), (float) ($totals->arr ?? 0)),
            'weighted_pipeline' => round($this->expectedRevenue(
                (float) ($totals->weighted_mrr ?? 0),
                (float) ($totals->weighted_arr ?? 0)
            ) / 100, 2),
            'by_stage' => $byStage,
            'by_rep' => $byRep->map(fn ($row) => [
                'user_id' => (int) $row->assigned_user_id,
                'name' => $repNames[$row->assigned_user_id] ?? 'Unknown',
                'count' => (int) $row->lead_count,
                'expected_value' => $this->expectedRevenue((float) $row->mrr, (float) $row->arr),
                'weighted_value' => round($this->expectedRevenue((float) $row->weighted_mrr, (float) $row->weighted_arr) / 100, 2),
            ])->sortByDesc('weighted_value')->values()->all(),
            'by_period' => $byPeriod,
        ];
    }

    /**
     * Work that has slipped or is due now.
     *
     * @return array<string, mixed>
     */
    public function attention(?User $user): array
    {
        $staleDays = (int) config('crm.stale_lead_days', 14);
        $openStageValues = $this->openStageValues();

        $overdueFollowups = (clone $this->tasks($user))
            ->where('status', TaskStatus::Pending)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now()->startOfDay())
            ->count();

        $todaysFollowups = (clone $this->tasks($user))
            ->where('status', TaskStatus::Pending)
            ->whereDate('due_at', now()->toDateString())
            ->count();

        $demosToday = (clone $this->demos($user))
            ->whereDate('scheduled_at', now()->toDateString())
            ->count();

        $staleLeads = (clone $this->leads($user))->staleFor($staleDays)->count();

        $unassigned = (clone $this->leads($user))
            ->whereNull('assigned_user_id')
            ->whereIn('status', $openStageValues)
            ->count();

        /*
         * Deals the forecast cannot see.
         *
         * Open, and carrying no number of their own — so they are absent from
         * every value on this page. The definition is mirrored by
         * DealsQueryService's `missing_value` filter deliberately: the count
         * and the list it opens must be the same query, and a test asserts
         * they agree.
         */
        $missingValue = (clone $this->leads($user))
            ->whereIn('status', $openStageValues)
            ->where(function (Builder $q) {
                $q->whereNull('expected_mrr')->orWhere('expected_mrr', '<=', 0);
            })
            ->whereNull('expected_arr')
            ->count();

        /*
         * What each row is holding up, in money.
         *
         * "1.8L is unowned" is a priority in a way that "2 deals are unowned"
         * is not, so a row carries the value of the rows it counted alongside
         * the count itself.
         */
        $valueOf = function (Builder $query): float {
            $row = $query
                ->selectRaw('coalesce(sum(expected_mrr), 0) as mrr, coalesce(sum(expected_arr), 0) as arr')
                ->first();

            return $this->expectedRevenue((float) ($row->mrr ?? 0), (float) ($row->arr ?? 0));
        };

        $unassignedValue = $valueOf((clone $this->leads($user))
            ->whereNull('assigned_user_id')
            ->whereIn('status', $openStageValues));

        $staleValue = $valueOf((clone $this->leads($user))->staleFor($staleDays));

        /*
         * Two bottlenecks the Overview banner names directly.
         *
         * Both reuse figures already computed for the KPI strip rather than
         * re-querying — a banner and a metric card disagreeing about how many
         * trials expire in 48 hours would be the same defect this module keeps
         * removing.
         */
        $operational = $this->demosTodaySplit($user);
        $liveTrials = $this->liveTrialHealth($user);

        $trialsEndingSoon = (clone $this->trials($user))
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->count();

        $quotationsExpiringSoon = (clone $this->quotations($user))
            ->where('status', QuotationStatus::Sent)
            ->whereNotNull('valid_until')
            ->whereBetween('valid_until', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->count();

        return [
            'overdue_followups' => $overdueFollowups,
            'todays_followups' => $todaysFollowups,
            'demos_today' => $demosToday,
            'stale_leads' => $staleLeads,
            'unassigned_leads' => $unassigned,
            'deals_missing_value' => $missingValue,
            'trials_ending_soon' => $trialsEndingSoon,
            'trials_expiring_48h' => $liveTrials['expiring_48h'],
            'demos_missing_link' => $operational['missing_link'],
            'quotations_expiring_soon' => $quotationsExpiringSoon,
            'items' => [
                $this->attentionItem('overdue_followups', 'Overdue Follow-ups', $overdueFollowups, 'high', route('company.crm.tasks.index'), 'Follow-up tasks past their due date.'),
                $this->attentionItem('unassigned_leads', 'Unassigned Leads', $unassigned, 'high', route('company.crm.deals.index', ['unassigned_only' => 1]), 'Open leads with no owner.', $unassignedValue),
                $this->attentionItem('stale_leads', 'Stale Leads', $staleLeads, 'medium', route('company.crm.deals.index', ['stalled_only' => 1]), "No stage movement in {$staleDays}+ days.", $staleValue),
                $this->attentionItem('deals_missing_value', 'Deals Without a Value', $missingValue, 'medium', route('company.crm.deals.index', ['missing_value' => 1]), 'Open deals carrying no figure, so absent from every total on this page.'),
                $this->attentionItem('demos_today', "Today's Demos", $demosToday, 'medium', route('company.crm.demos.index'), 'Demos scheduled for today.'),
                $this->attentionItem('trials_ending_soon', 'Trials Ending Soon', $trialsEndingSoon, 'medium', route('company.crm.trials.index'), 'Active trials ending within 7 days.'),
                $this->attentionItem('quotations_expiring_soon', 'Quotations Expiring', $quotationsExpiringSoon, 'medium', route('company.crm.quotations.index'), 'Sent quotations valid for 7 more days or fewer.'),
                /*
                 * A demo booked with no way to join it. High severity because
                 * it is silent — nobody finds out until the customer cannot get
                 * in — and because it is trivially fixable while there is still
                 * time.
                 */
                $this->attentionItem('demos_missing_link', 'Demos Without a Link', $operational['missing_link'], 'high', route('company.crm.my-workspace'), 'Booked for today with no meeting link or address.'),
                $this->attentionItem('trials_expiring_48h', 'Trials Expiring in 48h', $liveTrials['expiring_48h'], 'high', route('company.crm.trials.index'), 'Access ends within two days.'),
            ],
        ];
    }

    /**
     * Each rep's day, as three numbers somebody can act on.
     *
     * Not a smaller `repPerformance()`. That method is the cross-rep
     * leaderboard — deals, demos, quotes, win rate, weighted pipeline — and it
     * is still exactly right on Sales Teams, which is where comparing people
     * belongs. On the Overview it was a seven-column table inside a horizontal
     * scroller that answered "who is ahead", a question nobody acts on at
     * nine in the morning.
     *
     * This answers the one they do act on: who is behind, and on what. Name,
     * demos still to run today, follow-ups already late, and the pipeline
     * riding on them. Every figure links to the rows behind it filtered to that
     * rep, so a manager reading "5 overdue" is one click from the five.
     *
     * One flat query for the aggregation. The previous block issued eight
     * round-trips to build a table; the four figures here are correlated
     * subqueries in a single SELECT over the visible reps, so the cost is flat
     * in the size of the sales team rather than linear in it.
     *
     * Demos are correlated through the lead's owner rather than the demo's
     * presenter. Both are defensible readings, but only the lead carries
     * `organization_id` — correlating on `presenter_id` would have reached
     * outside the tenant boundary `CrmScope` establishes, and a scoping hole is
     * a worse trade than a demo attributed to the deal's owner rather than
     * whoever happened to run it.
     *
     * @return list<array<string, mixed>>
     */
    public function repTriage(?User $user): array
    {
        $repIds = $this->visibleRepIds($user);

        if ($repIds->isEmpty()) {
            return [];
        }

        $today = now()->toDateString();
        $conducted = [DemoStatus::Completed->value, DemoStatus::NoShow->value];
        $openStages = $this->openStageValues();

        /*
         * Correlated on `users.id`, so each subquery is evaluated per rep row
         * by the database rather than per rep by PHP. `whereColumn` against the
         * outer table is what makes this one statement instead of four.
         */
        $demosToday = fn () => Demo::query()
            ->join('leads', 'demos.lead_id', '=', 'leads.id')
            ->whereNull('leads.deleted_at')
            ->whereColumn('leads.assigned_user_id', 'users.id')
            ->whereDate('demos.scheduled_at', $today);

        $rows = User::query()
            ->whereIn('id', $repIds)
            ->select('id', 'name')
            ->selectSub($demosToday()->selectRaw('count(*)'), 'demos_today')
            ->selectSub(
                $demosToday()->whereIn('demos.status', $conducted)->selectRaw('count(*)'),
                'demos_conducted'
            )
            ->selectSub(
                Task::query()
                    ->whereColumn('tasks.assigned_user_id', 'users.id')
                    ->where('tasks.status', TaskStatus::Pending->value)
                    ->whereNotNull('tasks.due_at')
                    ->where('tasks.due_at', '<', now()->startOfDay())
                    ->selectRaw('count(*)'),
                'overdue_followups'
            )
            ->selectSub(
                Lead::query()
                    ->whereColumn('leads.assigned_user_id', 'users.id')
                    ->whereIn('leads.status', $openStages)
                    ->selectRaw('coalesce(sum(coalesce(nullif(leads.expected_arr, 0), leads.expected_mrr * 12)), 0)'),
                'pipeline_value'
            )
            ->orderBy('name')
            ->get();

        return $rows
            ->map(fn (User $rep) => [
                'user_id' => $rep->id,
                'name' => $rep->name,
                'initials' => collect(explode(' ', (string) $rep->name))
                    ->filter()
                    ->map(fn (string $part) => mb_substr($part, 0, 1))
                    ->take(2)
                    ->join(''),
                'demos_today' => (int) $rep->demos_today,
                'demos_conducted' => (int) $rep->demos_conducted,
                'overdue_followups' => (int) $rep->overdue_followups,
                'pipeline_value' => round((float) $rep->pipeline_value, 2),
            ])
            /*
             * Most overdue first, then most demos left to run. A triage list
             * sorted by name makes the reader scan all of it to find the one
             * person who needs help.
             */
            ->sortByDesc(fn (array $row) => [$row['overdue_followups'], $row['demos_today'] - $row['demos_conducted']])
            ->values()
            ->all();
    }

    /**
     * Reps this reader may see, inside their own CrmScope boundary.
     *
     * @return Collection<int, int>
     */
    protected function visibleRepIds(?User $user): Collection
    {
        $repIds = (clone $this->leads($user))
            ->whereNotNull('assigned_user_id')
            ->distinct()
            ->pluck('assigned_user_id')
            ->map(fn ($id) => (int) $id);

        $visibleIds = CrmScope::visibleUserIds($user);

        return $visibleIds === null ? $repIds : $repIds->intersect($visibleIds);
    }

    /**
     * Per-rep performance, built from grouped aggregates.
     *
     * @return list<array<string, mixed>>
     */
    public function repPerformance(?User $user): array
    {
        $repIds = (clone $this->leads($user))
            ->whereNotNull('assigned_user_id')
            ->distinct()
            ->pluck('assigned_user_id')
            ->map(fn ($id) => (int) $id);

        $visibleIds = CrmScope::visibleUserIds($user);
        if ($visibleIds !== null) {
            $repIds = $repIds->intersect($visibleIds);
        }

        if ($repIds->isEmpty()) {
            return [];
        }

        $names = User::whereIn('id', $repIds)->pluck('name', 'id');

        $leadStats = Lead::query()
            ->whereIn('assigned_user_id', $repIds)
            ->selectRaw('assigned_user_id, status, count(*) as lead_count, coalesce(sum(expected_mrr * probability_to_close), 0) as weighted_mrr, coalesce(sum(expected_arr * probability_to_close), 0) as weighted_arr')
            ->groupBy('assigned_user_id', 'status')
            ->get()
            ->groupBy('assigned_user_id');

        $demoStats = Demo::query()
            ->join('leads', 'demos.lead_id', '=', 'leads.id')
            ->whereIn('leads.assigned_user_id', $repIds)
            ->selectRaw('leads.assigned_user_id as rep_id, demos.status as demo_status, count(*) as demo_count')
            ->groupBy('leads.assigned_user_id', 'demos.status')
            ->get()
            ->groupBy('rep_id');

        $trialStats = Trial::query()
            ->join('leads', 'trials.lead_id', '=', 'leads.id')
            ->whereIn('leads.assigned_user_id', $repIds)
            ->selectRaw('leads.assigned_user_id as rep_id, trials.status as trial_status, count(*) as trial_count')
            ->groupBy('leads.assigned_user_id', 'trials.status')
            ->get()
            ->groupBy('rep_id');

        $quotationStats = CompanyQuotation::query()
            ->join('leads', 'company_quotations.lead_id', '=', 'leads.id')
            ->whereIn('leads.assigned_user_id', $repIds)
            ->whereNotNull('company_quotations.sent_at')
            ->selectRaw('leads.assigned_user_id as rep_id, count(*) as quotation_count')
            ->groupBy('leads.assigned_user_id')
            ->pluck('quotation_count', 'rep_id');

        $overdueStats = Task::query()
            ->whereIn('assigned_user_id', $repIds)
            ->where('status', TaskStatus::Pending)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now()->startOfDay())
            ->selectRaw('assigned_user_id, count(*) as overdue_count')
            ->groupBy('assigned_user_id')
            ->pluck('overdue_count', 'assigned_user_id');

        $cycleTimes = $this->cycleTimesByRep($repIds->all());

        $notStartedStages = [LeadStatus::New->value, LeadStatus::Assigned->value];

        return $repIds->map(function (int $repId) use ($names, $leadStats, $demoStats, $trialStats, $quotationStats, $overdueStats, $cycleTimes, $notStartedStages) {
            $leadRows = $leadStats->get($repId, collect());
            $countFor = fn (array $statuses) => (int) $leadRows
                ->filter(fn ($row) => in_array($this->statusValue($row->status), $statuses, true))
                ->sum('lead_count');

            $assigned = (int) $leadRows->sum('lead_count');
            $won = $countFor([LeadStatus::Won->value]);
            $lost = $countFor([LeadStatus::Lost->value]);
            $closed = $won + $lost;

            $demoRows = $demoStats->get($repId, collect());
            $trialRows = $trialStats->get($repId, collect());

            $weighted = $leadRows
                ->filter(fn ($row) => ! in_array($this->statusValue($row->status), [LeadStatus::Won->value, LeadStatus::Lost->value], true))
                ->reduce(fn (float $carry, $row) => $carry + $this->expectedRevenue((float) $row->weighted_mrr, (float) $row->weighted_arr) / 100, 0.0);

            $cycle = $cycleTimes[$repId] ?? null;

            return [
                'user_id' => $repId,
                'name' => $names[$repId] ?? 'Unknown',
                'leads_assigned' => $assigned,
                'leads_contacted' => $assigned - $countFor($notStartedStages),
                'demos_scheduled' => (int) $demoRows->sum('demo_count'),
                'demos_completed' => (int) $demoRows->filter(fn ($r) => $this->statusValue($r->demo_status) === 'completed')->sum('demo_count'),
                'trials_active' => (int) $trialRows->filter(fn ($r) => $this->statusValue($r->trial_status) === 'active')->sum('trial_count'),
                'quotations_sent' => (int) ($quotationStats[$repId] ?? 0),
                'wins' => $won,
                'losses' => $lost,
                'conversion_rate' => $closed > 0 ? round(($won / $closed) * 100, 1) : null,
                'average_cycle_time_days' => $cycle['average_days'] ?? null,
                'cycle_time_sample_size' => $cycle['sample_size'] ?? 0,
                'overdue_followups' => (int) ($overdueStats[$repId] ?? 0),
                'active_weighted_pipeline' => round($weighted, 2),
            ];
        })->sortByDesc('wins')->values()->all();
    }

    /**
     * Stage velocity: how long deals sit, and where they get stuck.
     *
     * @return array<string, mixed>
     */
    public function stageVelocity(?User $user): array
    {
        $averages = $this->averageStageDurations($user);

        $bottleneck = null;
        foreach ($averages as $stage => $data) {
            if ($data['average_days'] === null) {
                continue;
            }
            if ($bottleneck === null || $data['average_days'] > $bottleneck['average_days']) {
                $bottleneck = ['stage' => $stage, 'label' => LeadStatus::from($stage)->label()] + $data;
            }
        }

        $cycle = $this->cycleTimes($this->leads($user)->where('status', LeadStatus::Won));

        $oldestInStage = (clone $this->leads($user))
            ->whereIn('status', $this->openStageValues())
            ->whereNotNull('stage_entered_at')
            ->orderBy('stage_entered_at')
            ->limit(10)
            ->get(['id', 'company_name', 'status', 'stage_entered_at', 'assigned_user_id'])
            ->map(fn (Lead $lead) => [
                'id' => $lead->id,
                'company_name' => $lead->company_name,
                'stage' => $lead->status->value,
                'stage_label' => $lead->status->label(),
                'stage_age_days' => $lead->stage_age_days,
            ])
            ->values()
            ->all();

        return [
            'stage_averages' => collect($averages)
                ->map(fn (array $data, string $stage) => [
                    'stage' => $stage,
                    'label' => LeadStatus::from($stage)->label(),
                    'average_days' => $data['average_days'],
                    'sample_size' => $data['sample_size'],
                ])
                ->values()
                ->all(),
            'bottleneck_stage' => $bottleneck,
            'average_cycle_time_days' => $cycle['average_days'],
            'cycle_time_sample_size' => $cycle['sample_size'],
            'longest_in_stage' => $oldestInStage,
        ];
    }

    /**
     * Stale-lead presentation. Detection lives on Lead::scopeStaleFor; this
     * only reports. A stale lead is never advanced or closed automatically.
     *
     * @return array<string, mixed>
     */
    public function staleLeads(?User $user): array
    {
        $staleDays = (int) config('crm.stale_lead_days', 14);
        $base = fn () => (clone $this->leads($user))->staleFor($staleDays);

        $byOwnerRows = $base()
            ->selectRaw('assigned_user_id, count(*) as lead_count')
            ->groupBy('assigned_user_id')
            ->get();

        $ownerNames = User::whereIn('id', $byOwnerRows->pluck('assigned_user_id')->filter())->pluck('name', 'id');

        $byStage = $base()
            ->selectRaw('status, count(*) as lead_count')
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => [
                'stage' => $this->statusValue($row->status),
                'label' => LeadStatus::from($this->statusValue($row->status))->label(),
                'count' => (int) $row->lead_count,
            ])
            ->sortByDesc('count')
            ->values()
            ->all();

        /*
         * Ten rows, with their branches loaded in one go.
         *
         * The specialty and facility badges are what make a stalled row
         * actionable — "stuck at Negotiation" says nothing, "a 62-bed cardiology
         * hospital stuck at Negotiation" says who to call first. Eager-loaded
         * rather than resolved per row, because ten rows would otherwise be ten
         * queries on the page that exists to warn you about neglect.
         */
        $oldest = $base()
            ->with('branches')
            ->orderBy('stage_entered_at')
            ->limit(10)
            ->get()
            ->map(fn (Lead $lead) => [
                'id' => $lead->id,
                'company_name' => $lead->company_name,
                'contact_name' => $lead->contact_name,
                'stage' => $lead->status->value,
                'stage_label' => $lead->status->label(),
                'stage_age_days' => $lead->stage_age_days,
                'owner' => $lead->assigned_user_id ? ($ownerNames[$lead->assigned_user_id] ?? null) : null,
                'value' => $this->leadExpectedRevenue($lead),
                'specialties' => DealDomainPresenter::specialties($lead),
                'facility' => DealDomainPresenter::facility($lead),
            ])
            ->values()
            ->all();

        return [
            'threshold_days' => $staleDays,
            'total' => $base()->count(),
            'by_owner' => $byOwnerRows->map(fn ($row) => [
                'user_id' => $row->assigned_user_id ? (int) $row->assigned_user_id : null,
                'name' => $row->assigned_user_id ? ($ownerNames[$row->assigned_user_id] ?? 'Unknown') : 'Unassigned',
                'count' => (int) $row->lead_count,
            ])->sortByDesc('count')->values()->all(),
            'by_stage' => $byStage,
            'oldest' => $oldest,
        ];
    }

    /**
     * Win-back pipeline state. Execution lives in
     * LeadWorkspaceService::processDueWinBacks; this reports its outcomes.
     *
     * @return array<string, mixed>
     */
    public function winBack(?User $user): array
    {
        $today = now()->toDateString();

        $scheduled = (clone $this->leads($user))
            ->whereNotNull('winback_scheduled_at')
            ->whereDate('winback_scheduled_at', '>', $today)
            ->where('status', LeadStatus::Lost)
            ->count();

        $due = (clone $this->leads($user))
            ->whereNotNull('winback_scheduled_at')
            ->whereDate('winback_scheduled_at', '<=', $today)
            ->where('status', LeadStatus::Lost)
            ->count();

        // A win-back is "executed" once the idempotent job has logged its
        // activity against the lead — the same marker the job checks, so the
        // count here can never disagree with what the job actually did.
        /*
         * A subquery, not a plucked id list. This previously loaded every
         * win-back activity's subject id into PHP and passed it back as an IN
         * clause four times over; the empty-set guards below existed only
         * because an empty IN list is invalid SQL. A subquery has neither
         * problem, and an empty one correctly counts zero.
         */
        $executedLeadIds = fn () => Activity::query()
            ->whereIn('subject_type', $this->leadSubjectTypes())
            ->where('description', 'like', 'Win-back re-engagement due%')
            ->select('subject_id');

        $executed = (clone $this->leads($user))->whereIn('id', $executedLeadIds())->count();

        $reactivated = (clone $this->leads($user))
            ->whereIn('id', $executedLeadIds())
            ->whereNotIn('status', [LeadStatus::Lost])
            ->count();

        $lostAgain = (clone $this->leads($user))
            ->whereIn('id', $executedLeadIds())
            ->where('status', LeadStatus::Lost)
            ->count();

        // Scheduled but past due with no activity logged: the job has not yet
        // reached them. Reported separately so a stalled queue is visible
        // instead of being silently counted as executed.
        $pendingExecution = (clone $this->leads($user))
            ->whereNotNull('winback_scheduled_at')
            ->whereDate('winback_scheduled_at', '<=', $today)
            ->where('status', LeadStatus::Lost)
            ->whereNotIn('id', $executedLeadIds())
            ->count();

        return [
            'scheduled' => $scheduled,
            'due' => $due,
            'executed' => $executed,
            'reactivated' => $reactivated,
            'lost_again' => $lostAgain,
            'pending_execution' => $pendingExecution,
            'upcoming' => (clone $this->leads($user))
                ->whereNotNull('winback_scheduled_at')
                ->where('status', LeadStatus::Lost)
                ->orderBy('winback_scheduled_at')
                ->limit(10)
                ->get(['id', 'company_name', 'winback_scheduled_at', 'assigned_user_id'])
                ->map(fn (Lead $lead) => [
                    'id' => $lead->id,
                    'company_name' => $lead->company_name,
                    'scheduled_for' => $lead->winback_scheduled_at?->toDateString(),
                    'is_due' => $lead->winback_scheduled_at?->isPast() ?? false,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Recent CRM activity, scoped to what the user may see.
     *
     * @return list<array<string, mixed>>
     */
    public function recentActivity(?User $user, int $limit = 15): array
    {
        /*
         * A subquery replaces `->limit(500)->pluck('id')`. That limit was not a
         * safety rail, it was a correctness bug: a tenant with more than five
         * hundred leads got "recent activity" drawn from an arbitrary five
         * hundred of them, so a busy deal outside that slice never appeared.
         */
        return Activity::query()
            ->with('causer:id,name')
            ->whereIn('subject_type', $this->leadSubjectTypes())
            ->whereIn('subject_id', (clone $this->leads($user))->select('leads.id'))
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (Activity $activity) => [
                'id' => $activity->id,
                'lead_id' => (int) $activity->subject_id,
                'user_name' => $activity->causer?->name ?? 'System',
                'type' => $activity->type?->value ?? 'activity',
                'description' => $activity->description ?? 'Activity recorded',
                'occurred_at' => $activity->created_at?->toIso8601String(),
                'time' => $activity->created_at?->diffForHumans() ?? 'Just now',
            ])
            ->values()
            ->all();
    }

    /**
     * Average days spent in each stage, from the durations banked by LeadObserver.
     *
     * Leads created before stage tracking have no history, so they are excluded
     * from the average and reported through the sample size instead of being
     * counted as zero.
     *
     * @return array<string, array{average_days: float|null, sample_size: int}>
     */
    protected function averageStageDurations(?User $user): array
    {
        $totals = [];

        (clone $this->leads($user))
            ->whereNotNull('stage_durations')
            ->select(['id', 'stage_durations'])
            ->chunkById(500, function (Collection $leads) use (&$totals) {
                foreach ($leads as $lead) {
                    foreach ((array) $lead->stage_durations as $stage => $seconds) {
                        if (! is_numeric($seconds) || $seconds <= 0) {
                            continue;
                        }
                        $totals[$stage]['seconds'] = ($totals[$stage]['seconds'] ?? 0) + (int) $seconds;
                        $totals[$stage]['count'] = ($totals[$stage]['count'] ?? 0) + 1;
                    }
                }
            });

        $result = [];
        foreach (LeadStatus::cases() as $stage) {
            $data = $totals[$stage->value] ?? null;
            $result[$stage->value] = [
                'average_days' => $data ? round($data['seconds'] / $data['count'] / 86400, 1) : null,
                'sample_size' => $data['count'] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * Mean end-to-end cycle time in days for the given lead query.
     *
     * @param  Builder<Lead>  $query
     * @return array{average_days: float|null, sample_size: int}
     */
    protected function cycleTimes(Builder $query): array
    {
        $totalSeconds = 0;
        $sample = 0;

        (clone $query)
            ->whereNotNull('stage_durations')
            ->select(['id', 'stage_durations'])
            ->chunkById(500, function (Collection $leads) use (&$totalSeconds, &$sample) {
                foreach ($leads as $lead) {
                    $leadSeconds = collect((array) $lead->stage_durations)
                        ->filter(fn ($seconds) => is_numeric($seconds) && $seconds > 0)
                        ->sum();

                    if ($leadSeconds > 0) {
                        $totalSeconds += (int) $leadSeconds;
                        $sample++;
                    }
                }
            });

        return [
            'average_days' => $sample > 0 ? round($totalSeconds / $sample / 86400, 1) : null,
            'sample_size' => $sample,
        ];
    }

    /**
     * Per-rep cycle time over won deals.
     *
     * @param  list<int>  $repIds
     * @return array<int, array{average_days: float|null, sample_size: int}>
     */
    protected function cycleTimesByRep(array $repIds): array
    {
        $accumulator = [];

        Lead::query()
            ->whereIn('assigned_user_id', $repIds)
            ->where('status', LeadStatus::Won)
            ->whereNotNull('stage_durations')
            ->select(['id', 'assigned_user_id', 'stage_durations'])
            ->chunkById(500, function (Collection $leads) use (&$accumulator) {
                foreach ($leads as $lead) {
                    $seconds = collect((array) $lead->stage_durations)
                        ->filter(fn ($value) => is_numeric($value) && $value > 0)
                        ->sum();

                    if ($seconds <= 0) {
                        continue;
                    }

                    $repId = (int) $lead->assigned_user_id;
                    $accumulator[$repId]['seconds'] = ($accumulator[$repId]['seconds'] ?? 0) + (int) $seconds;
                    $accumulator[$repId]['count'] = ($accumulator[$repId]['count'] ?? 0) + 1;
                }
            });

        $result = [];
        foreach ($accumulator as $repId => $data) {
            $result[$repId] = [
                'average_days' => round($data['seconds'] / $data['count'] / 86400, 1),
                'sample_size' => $data['count'],
            ];
        }

        return $result;
    }

    /**
     * Expected revenue for a lead: annual value where known, otherwise the
     * monthly figure annualised, so mixed records stay comparable.
     */
    protected function expectedRevenue(float $mrr, float $arr): float
    {
        return round($arr > 0 ? $arr : $mrr * 12, 2);
    }

    protected function leadExpectedRevenue(Lead $lead): float
    {
        return $this->expectedRevenue((float) $lead->expected_mrr, (float) $lead->expected_arr);
    }

    protected function leadWeightedValue(Lead $lead): float
    {
        return round($this->leadExpectedRevenue($lead) * ((int) $lead->probability_to_close / 100), 2);
    }

    protected function weightedPipelineValue(?User $user): float
    {
        $row = (clone $this->leads($user))
            ->whereIn('status', $this->openStageValues())
            ->selectRaw('coalesce(sum(expected_mrr * probability_to_close), 0) as weighted_mrr, coalesce(sum(expected_arr * probability_to_close), 0) as weighted_arr')
            ->first();

        return round($this->expectedRevenue(
            (float) ($row->weighted_mrr ?? 0),
            (float) ($row->weighted_arr ?? 0)
        ) / 100, 2);
    }

    /**
     * @return list<string>
     */
    protected function openStageValues(): array
    {
        return array_map(fn (LeadStatus $stage) => $stage->value, self::OPEN_STAGES);
    }

    protected function statusValue(mixed $status): string
    {
        return is_object($status) && isset($status->value) ? $status->value : (string) $status;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Trials: what is running, what is drifting, and what actually converted.
     *
     * `running` and `undecided` split on the end date rather than the status,
     * because a trial left on `active` past its end date is not running — it is
     * a decision nobody has taken, and that is the more useful thing to see.
     *
     * The rate is computed only from trials that reached a decision. Including
     * live trials in the denominator reports a falling conversion rate every
     * time a new trial starts, which is the opposite of the truth.
     *
     * @return array<string, mixed>
     */
    public function trialHealth(?User $user, ?Carbon $periodStart = null, ?Carbon $periodEnd = null): array
    {
        $periodStart ??= now()->startOfMonth();
        $periodEnd ??= now()->endOfMonth();

        $live = (clone $this->trials($user))
            ->whereIn('status', [TrialStatus::Active->value, TrialStatus::Extended->value])
            ->get(['id', 'end_date']);

        $running = $live->filter(fn ($trial) => $trial->end_date !== null && ! $trial->end_date->isPast())->count();
        $undecided = $live->filter(fn ($trial) => $trial->end_date !== null && $trial->end_date->isPast())->count();

        $endingSoon = $live->filter(function ($trial) {
            if ($trial->end_date === null || $trial->end_date->isPast()) {
                return false;
            }

            return $trial->end_date->lessThanOrEqualTo(now()->addDays(7));
        })->count();

        /* A decision, either way. Cancelled is a withdrawal, not an outcome. */
        $converted = (clone $this->trials($user))
            ->where('status', TrialStatus::QuotationRequested->value)
            ->count();

        $lapsed = (clone $this->trials($user))
            ->where('status', TrialStatus::Expired->value)
            ->count();

        $decided = $converted + $lapsed;

        return [
            'running' => $running,
            'ending_soon' => $endingSoon,
            'undecided' => $undecided,
            'converted_this_period' => $converted,
            'lapsed_this_period' => $lapsed,
            'conversion_rate' => $decided > 0 ? round(($converted / $decided) * 100, 1) : null,
            'decided_sample_size' => $decided,
        ];
    }

    /**
     * The money side: what has been quoted, what is blocked, what has settled.
     *
     * The commercial flow is Quotation → Approval → Payment → Invoice → Won.
     * Settlement is what closes the chain, and the invoice is raised from it —
     * this used to report `agreements_accepted`, a document type nothing
     * creates any more, which could only ever be a number frozen in place.
     *
     * @return array<string, mixed>
     */
    public function commercialHealth(?User $user, ?Carbon $periodStart = null, ?Carbon $periodEnd = null): array
    {
        $periodStart ??= now()->startOfMonth();
        $periodEnd ??= now()->endOfMonth();

        $openStatuses = [QuotationStatus::Draft->value, QuotationStatus::Sent->value];

        $open = (clone $this->quotations($user))
            ->whereIn('status', $openStatuses)
            ->selectRaw('count(*) as quotation_count, coalesce(sum(grand_total), 0) as value')
            ->first();

        $drafts = (clone $this->quotations($user))->where('status', QuotationStatus::Draft)->count();

        $expiringSoon = (clone $this->quotations($user))
            ->where('status', QuotationStatus::Sent)
            ->whereNotNull('valid_until')
            ->whereBetween('valid_until', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->count();

        $approvedThisPeriod = (clone $this->quotations($user))
            ->where('status', QuotationStatus::Approved)
            ->whereBetween('approved_at', [$periodStart, $periodEnd])
            ->selectRaw('count(*) as quotation_count, coalesce(sum(grand_total), 0) as value')
            ->first();

        /*
         * Approval is a rule, not a column — the same service the detail page
         * and the list use, so one definition of "needs approval" serves all
         * three.
         */
        $approvals = app(QuotationApprovalService::class);
        $awaitingApproval = (clone $this->quotations($user))
            ->whereIn('status', $openStatuses)
            ->with('items')
            ->get()
            ->filter(fn (CompanyQuotation $quotation) => $approvals->requiresApproval($quotation) && ! $approvals->isApproved($quotation))
            ->count();

        $settled = CommercialInvoice::query()
            ->whereIn('company_quotation_id', (clone $this->quotations($user))->select('id'))
            ->whereBetween('issued_at', [$periodStart, $periodEnd])
            ->selectRaw('count(*) as invoice_count, coalesce(sum(final_amount), 0) as value')
            ->first();

        return [
            'open_count' => (int) ($open->quotation_count ?? 0),
            'open_value' => (float) ($open->value ?? 0),
            'drafts' => $drafts,
            'expiring_soon' => $expiringSoon,
            'awaiting_approval' => $awaitingApproval,
            'approval_threshold_percent' => $approvals->threshold(),
            'approved_count_this_period' => (int) ($approvedThisPeriod->quotation_count ?? 0),
            'approved_value_this_period' => (float) ($approvedThisPeriod->value ?? 0),
            /* Settlement closes the chain: quoted → approved → settled → invoiced. */
            'settled_count_this_period' => (int) ($settled->invoice_count ?? 0),
            'settled_value_this_period' => (float) ($settled->value ?? 0),
            'payments_received_this_period' => Payment::query()
                ->where('status', 'successful')
                ->whereIn('lead_id', (clone $this->leads($user))->select('id'))
                ->whereBetween('created_at', [$periodStart, $periodEnd])
                ->count(),
        ];
    }

    /**
     * Which way deals moved, and how far.
     *
     * Read from the structured payload a stage change writes, never from its
     * sentence. Rows written before that payload existed carry the detail only
     * in prose, and a sentence is not a contract — those are skipped rather
     * than parsed.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * Both spellings a lead activity may carry in `subject_type`.
     *
     * The morph map registers `lead`, and almost every writer uses
     * `getMorphClass()` — but `Auditable` and one seeder wrote the fully
     * qualified class name, so the column holds both. Reading only the alias
     * silently drops those rows, which is exactly what `pipelineMovement()` did
     * while its siblings accepted both and reported higher numbers.
     *
     * One definition here so no reader in this service can disagree with
     * another. It goes away when the column is normalised platform-wide.
     *
     * @return list<string>
     */
    protected function leadSubjectTypes(): array
    {
        return ['lead', Lead::class];
    }

    public function pipelineMovement(?User $user, ?Carbon $periodStart = null, ?Carbon $periodEnd = null, int $limit = 8): array
    {
        $periodStart ??= now()->startOfMonth();
        $periodEnd ??= now()->endOfMonth();

        $order = array_flip(array_map(
            fn (LeadStatus $stage) => $stage->value,
            array_merge(self::OPEN_STAGES, [LeadStatus::Won, LeadStatus::Lost])
        ));

        /*
         * The visible leads stay in SQL.
         *
         * This used to `pluck('company_name', 'id')` every lead the reader can
         * see and feed the keys back as an IN list — for a platform operator
         * that is the whole table in PHP memory and an IN clause the same size,
         * to produce eight rows. The subquery does the same filtering where the
         * data already is.
         */
        $activities = Activity::query()
            ->whereIn('subject_type', $this->leadSubjectTypes())
            ->whereIn('subject_id', (clone $this->leads($user))->select('leads.id'))
            ->where('type', 'system_update')
            ->latest('id')
            ->limit($limit * 4)
            ->get();

        if ($activities->isEmpty()) {
            return [];
        }

        // Names for the handful of leads that actually surfaced — bounded by
        // the limit above rather than by how many leads exist.
        $leads = Lead::query()
            ->whereIn('id', $activities->pluck('subject_id')->unique())
            ->pluck('company_name', 'id');

        return $activities
            ->map(function (Activity $activity) use ($leads, $order): ?array {
                $properties = $activity->properties;

                if (is_string($properties)) {
                    $properties = json_decode($properties, true);
                }

                $from = is_array($properties) ? ($properties['previous_stage'] ?? null) : null;
                $to = is_array($properties) ? ($properties['new_stage'] ?? null) : null;

                if (! is_string($from) || ! is_string($to)) {
                    return null;
                }

                $direction = match (true) {
                    $to === LeadStatus::Won->value => 'won',
                    $to === LeadStatus::Lost->value => 'lost',
                    ($order[$to] ?? 0) < ($order[$from] ?? 0) => 'backward',
                    default => 'forward',
                };

                return [
                    'id' => $activity->id,
                    'lead_id' => (int) $activity->subject_id,
                    'company_name' => $leads[$activity->subject_id] ?? null,
                    'from' => $from,
                    'to' => $to,
                    'from_label' => LeadStatus::tryFrom($from)?->label() ?? $from,
                    'to_label' => LeadStatus::tryFrom($to)?->label() ?? $to,
                    'direction' => $direction,
                    'at_label' => $activity->created_at?->diffForHumans(),
                ];
            })
            ->filter()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * The largest open deals, by what they are worth.
     *
     * @return list<array<string, mixed>>
     */
    public function topDeals(?User $user, int $limit = 4): array
    {
        return (clone $this->leads($user))
            ->whereIn('status', $this->openStageValues())
            // Branches with the page, not per row — same reason as staleLeads().
            ->with(['assignedUser:id,name', 'branches'])
            ->orderByRaw('COALESCE(NULLIF(expected_arr, 0), expected_mrr * 12) DESC')
            ->limit($limit)
            ->get()
            ->map(function (Lead $lead): array {
                $stage = $this->statusValue($lead->status);

                return [
                    'id' => $lead->id,
                    'company_name' => $lead->company_name,
                    'stage' => $stage,
                    'stage_label' => LeadStatus::tryFrom($stage)?->label() ?? $stage,
                    'value' => $this->leadExpectedRevenue($lead),
                    'probability' => (int) $lead->probability_to_close,
                    'next_action' => $lead->next_best_action,
                    'owner' => $lead->assignedUser?->name,
                    // Same badges as the Deals list and the deal page, from the
                    // same presenter, so nothing on screen can word them twice.
                    'specialties' => DealDomainPresenter::specialties($lead),
                    'facility' => DealDomainPresenter::facility($lead),
                ];
            })
            ->values()
            ->all();
    }

    protected function attentionItem(string $key, string $title, int $count, string $severity, string $link, string $description, float $value = 0.0): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'count' => $count,
            'value' => $value,
            'severity' => $count > 0 ? $severity : 'low',
            'link' => $link,
            'description' => $description,
        ];
    }

    /** @return Builder<Lead> */
    protected function leads(?User $user): Builder
    {
        return CrmScope::leads($user);
    }

    /** @return Builder<Demo> */
    protected function demos(?User $user): Builder
    {
        return CrmScope::demos($user);
    }

    /** @return Builder<Trial> */
    protected function trials(?User $user): Builder
    {
        return CrmScope::trials($user);
    }

    /** @return Builder<CompanyQuotation> */
    protected function quotations(?User $user): Builder
    {
        return CrmScope::quotations($user);
    }

    /** @return Builder<Task> */
    protected function tasks(?User $user): Builder
    {
        return CrmScope::tasks($user);
    }
}
