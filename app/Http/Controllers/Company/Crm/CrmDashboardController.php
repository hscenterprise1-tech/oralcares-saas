<?php

namespace App\Http\Controllers\Company\Crm;

use App\Http\Controllers\Controller;
use App\Services\CrmMetricsService;
use App\Services\MyWorkspaceService;
use App\Support\CrmScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRM Overview — the management dashboard.
 *
 * Aggregation lives entirely in CrmMetricsService, over CrmScope-constrained
 * queries: a master admin sees the platform, a manager sees their organization,
 * and an individual contributor sees their own book. Membership of the company
 * portal is no longer sufficient to see every record.
 *
 * Team-wide sections are additionally gated on `crm.overview.team`, so a rep
 * who lands here gets their own numbers rather than a 403 — the surface is
 * shared, the data is not.
 */
class CrmDashboardController extends Controller
{
    public function __construct(
        protected CrmMetricsService $metrics,
        protected MyWorkspaceService $workspace,
    ) {}

    /**
     * Selectable reporting windows, keyed by the `period` query parameter.
     *
     * Whitelisted rather than parsed from arbitrary input so the dashboard can
     * never be pointed at an unbounded range.
     *
     * @var array<string, string>
     */
    protected const PERIODS = [
        'week' => 'This week',
        'month' => 'This month',
        'quarter' => 'This quarter',
    ];

    public function index(Request $request): Response
    {
        Gate::authorize('crm.dashboard.view');

        $user = $request->user();
        $isManager = CrmScope::isManager($user);
        $canViewTeam = Gate::allows('crm.overview.team');

        $period = $request->string('period')->toString();
        if (! array_key_exists($period, self::PERIODS)) {
            $period = 'month';
        }

        $periodStart = match ($period) {
            'week' => now()->startOfWeek(),
            'quarter' => now()->startOfQuarter(),
            default => now()->startOfMonth(),
        };

        return Inertia::render('Company/Crm/Dashboard', [
            'scope' => [
                'level' => CrmScope::level($user),
                'is_manager' => $isManager,
                'can_view_team_performance' => $canViewTeam,
            ],
            'period' => [
                'current' => $period,
                'options' => collect(self::PERIODS)
                    ->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])
                    ->values()
                    ->all(),
            ],
            /*
             * Six sections, and nothing that does not belong to one of them.
             *
             * What left, and where it went:
             *
             *   schedule()        → My Work owns the personal calendar
             *   repPerformance()  → still rendered on Sales Teams; Reports will
             *                       carry the cross-team view. Comparing reps is
             *                       analysis, not a ten-second glance.
             *   recentActivity()  → the deal's own timeline. A page-wide feed of
             *   pipelineMovement()   stage changes is an audit trail, and an
             *                       audit trail is something you consult, not
             *                       something you act on from a dashboard.
             *   winBack           → the Deals "Lost" view already lists them;
             *                       the Overview only needs the count, which
             *                       attention() carries.
             *
             * `staleLeads()` came back the other way: the count alone said a
             * deal was neglected without saying which, so it now renders as
             * rows carrying the specialty and facility badges that decide who
             * gets called first.
             *
             * All four service methods are retained — this is a change of what
             * the Overview asks for, not a removal of capability.
             */
            /*
             * What renders immediately: the state of the business, and the
             * reader's own queue. Both are small and both are the reason
             * somebody opened the page.
             */
            'kpis' => $this->metrics->kpis($user, $periodStart),
            'attention' => $this->metrics->attention($user),
            'personal' => $this->workspace->payload($user),

            /*
             * Everything below arrives after the first paint.
             *
             * These are the expensive aggregates — stage ladders, forecasts,
             * per-rep grouping — and none of them is what the reader is looking
             * at in the first second. Deferring them is also the first use of
             * a loading state anywhere in this module; the page must show one
             * rather than a blank panel.
             */
            'pipeline' => Inertia::defer(fn () => $this->metrics->pipelineHealth($user), 'pipeline'),
            'velocity' => Inertia::defer(fn () => $this->metrics->stageVelocity($user), 'pipeline'),
            'top_deals' => Inertia::defer(fn () => $this->metrics->topDeals($user, 4), 'pipeline'),
            'trials' => Inertia::defer(fn () => $this->metrics->trialHealth($user, $periodStart), 'stages'),
            /*
             * Where the open pipeline sits by discipline.
             *
             * Deferred with the other analysis: it answers "which part of
             * healthcare is buying", which is a planning question rather than
             * something anyone acts on in the first second.
             */
            'specialty_revenue' => Inertia::defer(fn () => $this->metrics->specialtyRevenue($user), 'stages'),
            'commercials' => Inertia::defer(fn () => $this->metrics->commercialHealth($user, $periodStart), 'stages'),

            /*
             * The forecast has existed and been tested since the reporting
             * work, and has never had a surface. It is the one number a
             * founder checks weekly, so it belongs here rather than nowhere.
             */
            'forecast' => Inertia::defer(fn () => $this->metrics->forecast($user), 'pipeline'),

            /*
             * Per-rep numbers are the only thing here that shows one person's
             * work to another, so this stays the only gated section.
             *
             * `repTriage()`, not `repPerformance()`. The leaderboard that stood
             * here — deals, demos, quotes, win rate — answered "who is ahead",
             * which nobody acts on from a dashboard, and it needed a horizontal
             * scroller to say it. Triage answers "who is behind, and on what".
             * The leaderboard is unchanged and still rendered on Sales Teams,
             * which is where comparing people belongs.
             */
            'rep_triage' => $canViewTeam
                ? Inertia::defer(fn () => $this->metrics->repTriage($user), 'people')
                : null,

            /*
             * Stalled deals, with the badges that make a stalled row
             * actionable. `pipelineMovement` used to sit here — a raw feed of
             * stage changes that nobody could act on from this page. Its proper
             * home is the deal's own timeline, which already carries it.
             */
            'stalled' => Inertia::defer(fn () => $this->metrics->staleLeads($user), 'people'),
        ]);
    }
}
