<?php

namespace App\Http\Controllers\Company\Crm;

use App\HealthcareSpecialty;
use App\Http\Controllers\Controller;
use App\LeadPriority;
use App\LeadStatus;
use App\LostReason;
use App\Models\CrmSavedView;
use App\Models\Demo;
use App\Models\HealthcareSpecialtyMaster;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use App\Services\CRM\DealsQueryService;
use App\Services\CRM\DealStageService;
use App\Services\CRM\TrialLifecycleService;
use App\Services\LeadWorkspaceService;
use App\Support\CrmScope;
use App\Support\DealDomainPresenter;
use App\UserType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Deals — the one destination over the `leads` table.
 *
 * Leads and Sales Pipeline were two pages, two payload builders and two filter
 * engines over the same rows. This is the single workspace.
 *
 * Four operational queues over one query, and deliberately not a board.
 *
 * A twelve-lane board was tried here and thrown away. Twelve columns do not fit
 * on any screen, so the page became a horizontal scroll: reaching Negotiation
 * meant dragging past four lanes, nothing was comparable to anything you could
 * not see at the same time, and at a hundred thousand deals every lane was a
 * capped sample of its stage pretending to be the stage.
 *
 * What replaced it:
 *
 *   queues   the twelve statuses collapsed into the four a day is organised
 *            around — inflow, demos, trials, closing — with won and lost behind
 *            an archive toggle. See `QUEUES`.
 *   list     one full-width, paginated, high-density table of the queue.
 *
 * A row opens the deal's own page. An embedded detail pane was tried here and
 * rejected: half a screen is not enough room for a trial watchtower or a
 * quotation history, and the deal already has a page built to hold them.
 */
class DealController extends Controller
{
    /** Rows in one page of the feed. */
    private const PER_PAGE = 25;

    /**
     * Days in one stage before a row flags itself for attention.
     *
     * Deliberately shorter than `crm.stale_lead_days` (fourteen), which is the
     * canonical stalled definition the Stale view and every stalled count read.
     * This is the earlier, softer signal a row wants — "this has been sitting a
     * while" — and it is a separate number because it answers a separate
     * question. One badge doing both would have had a row calling a deal
     * stalled that the Stale view does not list.
     */
    private const STAGE_ATTENTION_DAYS = 4;

    public function __construct(protected DealsQueryService $deals) {}

    /**
     * The four queues the pipeline is actually worked in.
     *
     * Twelve statuses is the right vocabulary for a *record* and the wrong one
     * for a *workspace*: nobody's day is organised around the difference
     * between `quotation_sent` and `quotation_approved`, and rendering twelve
     * of anything side by side forces a horizontal scroll that stops being
     * usable somewhere around the third column. These are the four questions a
     * rep opens this page to answer — who is new, who am I demoing, who is
     * evaluating, who is about to sign — and each maps onto the statuses that
     * answer it.
     *
     * Won and Lost are deliberately not a fifth queue. They are not work; they
     * are the record of work, and they sit behind the archive toggle.
     *
     * @var array<string, array{label: string, hint: string, stages: list<string>}>
     */
    public const QUEUES = [
        'inflow' => [
            'label' => 'Inflow',
            'hint' => 'New enquiries, assigned and in first contact',
            'stages' => ['new', 'contacted'],
        ],
        'demos' => [
            'label' => 'Demos',
            'hint' => 'Booked demonstrations and their outcomes',
            'stages' => ['demo_scheduled', 'demo_completed'],
        ],
        'trials' => [
            'label' => 'Trials',
            'hint' => 'Clinics evaluating the product live',
            'stages' => ['trial_active'],
        ],
        'closing' => [
            'label' => 'Closing',
            'hint' => 'Quotation, negotiation and payment',
            'stages' => ['negotiation', 'quotation_sent', 'quotation_approved', 'payment_received'],
        ],
        'archive' => [
            'label' => 'Archive',
            'hint' => 'Won and lost',
            'stages' => ['won', 'lost'],
        ],
    ];

    /** The queue the page opens on. */
    private const DEFAULT_QUEUE = 'inflow';

    public function index(Request $request)
    {
        Gate::authorize('viewAny', Lead::class);

        $user = $request->user();
        $view = in_array($request->query('view'), DealsQueryService::VIEWS, true)
            ? $request->query('view')
            : 'all';

        $filters = $request->only([
            'search', 'stage', 'source', 'priority', 'organization_type',
            'city', 'state', 'assigned_user_id', 'sort', 'direction',
            'overdue_only', 'stalled_only', 'unassigned_only', 'hot_only',
            // Deep-link targets for the Overview's attention rows.
            'missing_value', 'value_min', 'mine_only',
            'created_from', 'created_to',
            // Team, ageing, commercial state and silence.
            'assigned_team_id', 'stage_age_min', 'trial', 'quotation', 'payment',
            'value_max', 'quiet_for_days',
            // What the practice does, and what kind of place it is.
            'specialty', 'facility_type',
        ]);

        /*
         * Which queue, and `all` as the explicit escape hatch.
         *
         * `all` exists for the cases where narrowing is wrong rather than
         * right: a search for a doctor's name has to reach every stage, an
         * export has to cover the book, and a saved view names its own cut. It
         * is never the default, because an unfiltered list of a hundred
         * thousand deals is not a workspace.
         */
        $queue = $this->resolveQueue($request->query('queue'));

        /*
         * A named view outranks the queue.
         *
         * "Lost", "Stale", "Trial running" and the rest are already a cut of
         * the whole book, chosen explicitly. Narrowing one of them to the
         * inflow queue as well means clicking Lost and being shown nothing —
         * the page contradicting the control you just pressed. The queue is
         * reported back as `all` so the tab strip says so, rather than
         * highlighting a queue that is not being applied.
         */
        if ($view !== 'all') {
            $queue = 'all';
        }

        /*
         * Computed once and shared, and deliberately *before* the queue narrows
         * anything: the tab strip's counts have to describe the queues you are
         * not currently looking at, or the tabs cannot be navigated by.
         */
        $stageBreakdown = $this->deals->stageBreakdown($user, $filters + ['view' => $view]);

        /*
         * The queue is expressed as a stage filter, so there is exactly one
         * mechanism narrowing the list and the tabs cannot mean something the
         * filter engine does not. An explicit `stage` from the advanced panel
         * wins — it is strictly narrower than the queue that contains it.
         */
        $listFilters = $filters + ['view' => $view];

        if ($queue !== 'all' && blank($filters['stage'] ?? null)) {
            $listFilters['stage'] = implode(',', self::QUEUES[$queue]['stages']);
        }

        $query = $this->deals->query($user, $listFilters)
            ->with([
                'assignedUser:id,name,email',
                'organization:id,name,city,state',
                /*
                 * The evaluation state, on the row.
                 *
                 * The feed is scanned, not read: is there a demo, can I join
                 * it, is the trial being used. Both are eager-loaded and
                 * bounded — the newest demo and the newest trial per deal — so
                 * the row gains two facts and the page gains two queries, not
                 * two per row.
                 */
                'branches',
                'demos' => fn ($q) => $q->latest('scheduled_at')->limit(1),
                'trials' => fn ($q) => $q->with('companyPlan:id,key,name')->latest('id')->limit(1),
                /* The billing cycle for the commercials column. */
                'selectedPlan:id,name,billing_period',
                /*
                 * The newest quotation is the active one — revising always
                 * supersedes whatever came before it, so the highest id never
                 * disagrees with `isActive()` about which version is current.
                 */
                'quotations' => fn ($q) => $q->latest('id')->limit(1),
            ])
            /*
             * One correlated subquery for the whole page, not one per row. The
             * list already eager-loads its relations; this keeps "last touched"
             * on the same budget rather than reopening the N+1 the performance
             * test exists to prevent.
             */
            ->withMax('activities', 'created_at');

        return Inertia::render('Company/Crm/Deals/Index', [
            'view' => $view,
            'queue' => $queue,
            'queues' => $this->queuePayload($stageBreakdown),
            'savedViews' => DealsQueryService::SAVED_VIEWS,
            /*
             * The built-in views come from code and mean the same for
             * everybody; these are the ones this person named for themselves.
             * Kept apart in the payload so the page can present them as what
             * they are rather than mixing them into one list.
             */
            'customViews' => CrmSavedView::query()
                ->where('user_id', $user?->id)
                ->orderBy('name')
                ->get(['id', 'name', 'filters'])
                ->map(fn (CrmSavedView $saved) => [
                    'id' => $saved->id,
                    'name' => $saved->name,
                    'filters' => $saved->filters,
                ])
                ->values()
                ->all(),
            'filters' => $filters,
            /*
             * The counts take the same filters as the list.
             *
             * Otherwise a search leaves the stage rail at zero while the view
             * chips still advertise the whole book — two figures on one screen
             * describing different things. Switching view keeps the search, so
             * a chip's number is exactly what clicking it produces.
             */
            'counts' => $this->deals->counts($user, $filters),
            'stageBreakdown' => $stageBreakdown,
            'deals' => $this->listPayload($query),
            'assignableUsers' => $this->assignableUsers($user),
            'assignableTeams' => $this->assignableTeams($user),
            'leadSources' => Lead::sources(),
            /*
             * The specialty filter's options, from the catalogue rather than a
             * distinct() over the leads — a filter that only offers what has
             * already been sold cannot be used to look for what has not.
             */
            'specialties' => HealthcareSpecialtyMaster::catalogue()
                ->map(fn (array $s) => ['value' => $s['key'], 'label' => $s['name'], 'category' => $s['category_label']])
                ->values()
                ->all(),
            /*
             * What the quick-create modal's intake is allowed to submit.
             *
             * `specialties` above is the catalogue's thirty-odd entries, built
             * for a filter that should offer everything ever sold. Intake asks
             * a rep a much smaller question on a first call, and `StoreLeadRequest`
             * only accepts `HealthcareSpecialty`'s eight cases — the same list
             * `Leads/Create.tsx` already renders. Handing the filter's list to
             * the modal instead would let a rep pick "Homeopathy" and have the
             * save fail on a validation rule the form never told them about.
             */
            'intakeSpecialties' => HealthcareSpecialty::options(),
            /*
             * Stages carry their tone, so the page does not.
             *
             * `Deals/Index.tsx` held a `STAGE_STEPS` constant with ten
             * hard-coded hex values duplicating `stageTone()`/`toneHex()` from
             * the design system — a second stage vocabulary that could drift
             * from `LeadStatus` and from the palette independently. The server
             * already owns the stage list; it now owns the ordering and the
             * tone name with it, and the page resolves the colour through the
             * design system as every other surface does.
             */
            'stages' => collect(LeadStatus::cases())
                ->values()
                ->map(fn (LeadStatus $s, int $i) => [
                    'value' => $s->value,
                    'label' => $s->label(),
                    'position' => $i,
                    'is_terminal' => in_array($s, [LeadStatus::Won, LeadStatus::Lost], true),
                ])
                ->all(),
            'priorities' => collect(LeadPriority::cases())
                ->map(fn (LeadPriority $p) => ['value' => $p->value, 'label' => ucfirst($p->value)])
                ->values()->all(),
            /*
             * Loss triage lives in the "Lost" view rather than on a page of its
             * own. A lost deal is a deal, the list that holds it is this one,
             * and a separate workspace meant the reason was recorded somewhere
             * other than where the loss was read.
             */
            'lostReasons' => collect(LostReason::cases())
                ->map(fn (LostReason $r) => ['value' => $r->value, 'label' => Str::headline($r->value)])
                ->values()->all(),
            'can' => [
                'bulk_assign' => CrmScope::isManager($user),
                'export' => Gate::allows('viewAny', Lead::class),
                /*
                 * Triage and win-back both write to the deal, so the control
                 * appears only for somebody the deal's own policy would let
                 * through. Checked once here rather than per row: every row on
                 * this page is inside the same CrmScope boundary.
                 */
                'triage' => Gate::allows('create', Lead::class),
            ],
        ]);
    }

    /**
     * Which queue was asked for, falling back to the one the page opens on.
     */
    protected function resolveQueue(mixed $queue): string
    {
        $queue = is_string($queue) ? $queue : '';

        if ($queue === 'all' || array_key_exists($queue, self::QUEUES)) {
            return $queue;
        }

        return self::DEFAULT_QUEUE;
    }

    /**
     * The tab strip: every queue with its true size under the current filters.
     *
     * Derived from the stage breakdown the page already computed rather than
     * counted again — five extra aggregate queries to render five numbers that
     * are already on the page would be five queries too many, and two sources
     * for one fact is how a tab and the list it opens start disagreeing.
     *
     * @param  list<array<string, mixed>>  $stageBreakdown
     * @return list<array<string, mixed>>
     */
    protected function queuePayload(array $stageBreakdown): array
    {
        $byStage = collect($stageBreakdown)->keyBy('value');

        return collect(self::QUEUES)
            ->map(fn (array $queue, string $key) => [
                'key' => $key,
                'label' => $queue['label'],
                'hint' => $queue['hint'],
                'is_archive' => $key === 'archive',
                'count' => (int) collect($queue['stages'])
                    ->sum(fn (string $stage) => (int) ($byStage->get($stage)['count'] ?? 0)),
                'value_total' => (float) collect($queue['stages'])
                    ->sum(fn (string $stage) => (float) ($byStage->get($stage)['total_value'] ?? 0)),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Builder<Lead>  $query
     * @return array<string, mixed>
     */
    protected function listPayload($query): array
    {
        $paginator = $query->paginate(self::PER_PAGE)->withQueryString();

        return [
            'data' => collect($paginator->items())->map(fn (Lead $lead) => $this->row($lead))->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                // CrmPagination renders Inertia's own link collection.
                'links' => $paginator->linkCollection()->toArray(),
            ],
        ];
    }

    /**
     * One row shape for every view, so no two views can describe the same
     * record differently.
     *
     * @return array<string, mixed>
     */
    protected function row(Lead $lead): array
    {
        $status = $this->statusValue($lead);

        return [
            'id' => $lead->id,
            'company_name' => $lead->company_name,
            'contact_name' => $lead->contact_name,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'status' => $status,
            'status_label' => $lead->status instanceof LeadStatus ? $lead->status->label() : $status,
            'priority' => $lead->priority instanceof \BackedEnum ? $lead->priority->value : (string) $lead->priority,
            'source' => $lead->source,
            'source_label' => Lead::sources()[$lead->source] ?? $lead->source,
            'expected_mrr' => (float) ($lead->expected_mrr ?? 0),
            'expected_arr' => (float) ($lead->expected_arr ?: ($lead->expected_mrr ? $lead->expected_mrr * 12 : 0)),
            'lead_score' => (int) ($lead->lead_score ?? 0),
            'probability' => (int) ($lead->probability_to_close ?? 0),
            /*
             * The cycle the ARR is actually billed on. A live quotation is a
             * concrete commercial fact and outranks the plan's own default —
             * a rep can quote a quarter against a plan the catalogue only
             * lists monthly pricing for. Falls back to the plan's projection
             * when nothing has been quoted yet, and says nothing rather than
             * assuming monthly when neither exists.
             */
            'billing_period' => $lead->quotations->first()?->billing_cycle?->value ?? $lead->selectedPlan?->billing_period,
            'billing_period_label' => $lead->quotations->first()
                ? $lead->quotations->first()->billing_cycle->shortLabel()
                : ($lead->selectedPlan ? Str::headline($lead->selectedPlan->billing_period) : null),
            // Days in the current stage — the stalling signal, and the only
            // freshness figure worth a column. `updated_at` stays sortable but
            // "someone edited this" is not a pipeline fact.
            'stage_age_days' => $lead->stage_age_days,
            'last_activity_at' => $lead->activities_max_created_at
                ? Carbon::parse($lead->activities_max_created_at)->diffForHumans()
                : null,
            'next_best_action' => $lead->next_best_action,
            'next_followup_at' => $lead->next_followup_at?->toISOString(),
            'next_followup_label' => $lead->next_followup_at?->format('d M, h:i A'),
            /*
             * "Today at 4:30 PM" rather than "16 Sep, 4:30 PM" — computed once
             * here so a row and the deal page it opens cannot describe the same
             * appointment differently.
             */
            'next_followup_relative_label' => $this->relativeSchedule($lead->next_followup_at),
            /*
             * The note behind the callback, trimmed to a snippet. The row is
             * scanned, not read — the full note is one click away on the deal.
             */
            'followup_notes_snippet' => $lead->followup_notes
                ? Str::limit($lead->followup_notes, 60)
                : null,
            'is_overdue' => $lead->next_followup_at ? $lead->next_followup_at->isPast() : false,
            'is_stalled' => $this->deals->isStalled($lead),
            /*
             * Two different facts, deliberately both on the row.
             *
             * `is_stalled` is THE stalled definition — `crm.stale_lead_days`,
             * currently fourteen — and it is what the Stale saved view and every
             * stalled count read. `needs_attention` is the softer, earlier
             * signal a card wants: this has been sitting a while. Collapsing
             * them into one badge would have meant the card calling a deal
             * "stalled" that the Stale view does not list, which is exactly the
             * kind of disagreement this module has been pulling apart.
             */
            'needs_attention' => $lead->stage_age_days > self::STAGE_ATTENTION_DAYS,
            /*
             * The suggested next stage, so the row can offer a one-click
             * advance without the page keeping its own copy of the ladder.
             * `DealStageService::NEXT_STAGE` is the one definition, and the
             * transition still goes through the endpoint that enforces each
             * stage's requirements — this only names the destination.
             */
            'next_stage' => DealStageService::NEXT_STAGE[$status] ?? null,
            'next_stage_label' => isset(DealStageService::NEXT_STAGE[$status])
                ? (LeadStatus::tryFrom(DealStageService::NEXT_STAGE[$status])?->label())
                : null,
            'is_qualified' => $lead->qualified_at !== null,
            'city' => $lead->city,
            'state' => $lead->state,
            'postal_code' => $lead->postal_code,
            'area' => $lead->area,
            /*
             * Specialties, plural and catalogued.
             *
             * A cardiology institute that also runs orthopedics and imaging is
             * three badges, not one — and the old single `healthcare_specialty`
             * enum had no case for most of this book. Resolved through
             * DealDomainPresenter so the list and the deal page cannot colour or
             * word them differently.
             */
            'specialties' => DealDomainPresenter::specialties($lead),
            /* Solo practice, polyclinic, hospital — and how big. */
            'facility' => DealDomainPresenter::facility($lead),
            // The evaluation, summarised for the card. Null when there is none.
            'demo' => $this->demoSummary($lead),
            'trial' => $this->trialSummary($lead),
            'lost_reason' => $lead->lost_reason instanceof \BackedEnum ? $lead->lost_reason->value : $lead->lost_reason,
            'lost_at' => $lead->lost_at?->format('d M Y'),
            /*
             * Who we lost to, so the Lost view answers the question the loss
             * report is built on without opening each deal.
             */
            'lost_competitor' => $lead->lost_competitor,
            'winback_scheduled_at' => $lead->winback_scheduled_at?->format('d M Y'),
            'assigned_user' => $lead->assignedUser ? [
                'id' => $lead->assignedUser->id,
                'name' => $lead->assignedUser->name,
                'initials' => collect(explode(' ', $lead->assignedUser->name))
                    ->map(fn ($n) => $n[0] ?? '')->take(2)->join(''),
            ] : null,
            'updated_at' => $lead->updated_at?->diffForHumans(),
            'url' => route('company.crm.leads.show', $lead, absolute: false),
        ];
    }

    /**
     * A date read the way a rep reads it: "Today at 4:30 PM", "Tomorrow at
     * 4:30 PM", or the date for anything further out.
     *
     * Computed once, here, so the pipeline table and the deal page it opens
     * cannot describe the same appointment or the same follow-up differently.
     */
    protected function relativeSchedule(?Carbon $at): ?string
    {
        if (! $at) {
            return null;
        }

        $time = $at->format('h:i A');

        return match (true) {
            $at->isToday() => "Today at {$time}",
            $at->isTomorrow() => "Tomorrow at {$time}",
            default => $at->format('d M, h:i A'),
        };
    }

    /**
     * The deal's most recent demo, reduced to what a card can act on.
     *
     * A link the rep can click and a time they can read. Everything else about
     * a demo belongs on the deal, which is one click away.
     *
     * @return array<string, mixed>|null
     */
    protected function demoSummary(Lead $lead): ?array
    {
        $demo = $lead->demos->first();

        if (! $demo) {
            return null;
        }

        return [
            'id' => $demo->id,
            'scheduled_at' => $demo->scheduled_at?->toISOString(),
            'scheduled_label' => $demo->scheduled_at?->format('d M, h:i A'),
            'relative_label' => $this->relativeSchedule($demo->scheduled_at),
            'is_today' => $demo->scheduled_at?->isToday() ?? false,
            'is_upcoming' => $demo->scheduled_at?->isFuture() ?? false,
            'status' => $demo->status instanceof \BackedEnum ? $demo->status->value : (string) $demo->status,
            'platform' => $demo->meeting_platform,
            'platform_label' => Demo::PLATFORMS[$demo->meeting_platform] ?? null,
            'is_field_visit' => $demo->meeting_platform === Demo::PLATFORM_IN_PERSON,
            'meeting_link' => $demo->meeting_link,
            'location' => $demo->location,
            /*
             * A booked demo with no way to join it is a booking nobody can
             * attend, and it is invisible until the morning of. Flagged on the
             * card and counted on the Overview's bottleneck banner.
             */
            'missing_join_detail' => $demo->joiningInstruction() === null,
        ];
    }

    /**
     * The deal's current trial, reduced to a package and three counters.
     *
     * The package comes from `company_plans` through the relation — never a
     * string on the trial — so the tier a card shows is the tier the catalogue
     * defines.
     *
     * @return array<string, mixed>|null
     */
    protected function trialSummary(Lead $lead): ?array
    {
        $trial = $lead->trials->first();

        if (! $trial) {
            return null;
        }

        $telemetry = $trial->usage_telemetry ?: null;

        return [
            'id' => $trial->id,
            'status' => $trial->status instanceof \BackedEnum ? $trial->status->value : (string) $trial->status,
            'package' => $trial->companyPlan ? [
                'id' => $trial->companyPlan->id,
                'key' => $trial->companyPlan->key,
                'name' => $trial->companyPlan->name,
            ] : null,
            'end_date' => $trial->end_date?->toDateString(),
            'days_remaining' => $trial->end_date ? (int) now()->startOfDay()->diffInDays($trial->end_date, false) : null,
            /*
             * "Day 12 of 14", so the table says how far in the evaluation is
             * rather than only how much is left. `duration_days` is the trial's
             * own recorded length; when it is missing (a legacy row) the span
             * between the recorded start and end stands in for it.
             */
            'day_number' => $trial->start_date
                ? max(1, (int) $trial->start_date->startOfDay()->diffInDays(now()->startOfDay()) + 1)
                : null,
            'total_days' => $trial->duration_days
                ?: ($trial->start_date && $trial->end_date
                    ? (int) $trial->start_date->diffInDays($trial->end_date) + 1
                    : null),
            /*
             * The same threshold `TrialLifecycleService` uses to mark a trial
             * `EndingSoon`, so the pipeline table and the trial's own lifecycle
             * cannot disagree about what "ending soon" means.
             */
            'is_ending_soon' => $trial->end_date
                ? now()->startOfDay()->diffInDays($trial->end_date, false) <= TrialLifecycleService::ENDING_SOON_DAYS
                : false,
            'extension_count' => (int) $trial->extension_count,
            /*
             * Null, never zeros. A tenant nobody has measured and a tenant that
             * did nothing are different facts, and the card says so.
             */
            'telemetry' => $telemetry ? [
                'patients' => (int) ($telemetry['patients'] ?? 0),
                'prescriptions' => (int) ($telemetry['prescriptions'] ?? 0),
                'invoices' => (int) ($telemetry['invoices'] ?? 0),
                'is_active_today' => (bool) ($telemetry['is_active_today'] ?? false),
                'is_inactive' => (bool) ($telemetry['is_inactive'] ?? false),
                'hours_since_login' => $telemetry['hours_since_login'] ?? null,
            ] : null,
        ];
    }

    /**
     * Teams a filter may name.
     *
     * Reads `teams`, because that is what `leads.assigned_team_id` points at —
     * offering the rows in `company_teams` instead would let someone filter
     * deals by a department id no deal has ever carried.
     *
     * That table is currently empty, so the filter offers nothing until sales
     * teams exist. That is the honest answer rather than a populated control
     * that matches no rows.
     *
     * @return list<array<string, mixed>>
     */
    protected function assignableTeams(?User $user): array
    {
        $organizationId = $user?->resolveOrganizationId();

        return Team::query()
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Team $team) => ['id' => $team->id, 'name' => $team->name])
            ->values()
            ->all();
    }

    protected function statusValue(Lead $lead): string
    {
        return $lead->status instanceof LeadStatus ? $lead->status->value : (string) $lead->status;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function assignableUsers(?User $user): array
    {
        $ids = CrmScope::visibleUserIds($user);

        return User::query()
            ->where('user_type', '!=', UserType::ClinicUser->value)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->select('id', 'name')
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->values()
            ->all();
    }

    /**
     * Save the cut currently on screen under a name.
     *
     * Saving over a name replaces that view rather than creating a second one
     * with the same label — a person refining "Mumbai enterprise" expects to
     * refine it, not to accumulate three of them.
     */
    public function storeSavedView(Request $request)
    {
        Gate::authorize('viewAny', Lead::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'filters' => ['nullable', 'array'],
        ]);

        $filters = CrmSavedView::sanitise($validated['filters'] ?? []);

        if ($filters === []) {
            throw ValidationException::withMessages([
                'filters' => 'There is nothing to save — apply a filter first.',
            ]);
        }

        CrmSavedView::updateOrCreate(
            ['user_id' => $request->user()->id, 'name' => trim($validated['name'])],
            [
                'organization_id' => $request->user()->resolveOrganizationId(),
                'filters' => $filters,
            ]
        );

        return back()->with('success', 'View saved.');
    }

    /**
     * Delete one of your own saved views.
     *
     * Scoped to the owner rather than authorised by policy: a saved view is
     * personal, so there is nobody else it could belong to.
     */
    public function destroySavedView(Request $request, CrmSavedView $savedView)
    {
        abort_unless($savedView->user_id === $request->user()->id, 403);

        $savedView->delete();

        return back()->with('success', 'View removed.');
    }

    /**
     * Warn about a possible duplicate before the deal is created.
     *
     * The detection itself has existed and been tested since the lead work —
     * normalised phone, case-insensitive email, company name, all scoped to
     * the tenant. What was missing was somewhere to ask it *before* saving:
     * the only path went through creation, so the product could tell you a
     * deal was a duplicate exactly once it had made one.
     *
     * Read-only and deliberately quiet — a possible match is not an error, so
     * it answers 200 with whatever it found and lets the form decide.
     */
    public function checkDuplicate(Request $request, LeadWorkspaceService $workspace)
    {
        Gate::authorize('create', Lead::class);

        $validated = $request->validate([
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'company_name' => 'nullable|string|max:255',
        ]);

        $duplicate = $workspace->findDuplicateLead(
            $request->user()?->resolveOrganizationId(),
            $validated['phone'] ?? null,
            $validated['email'] ?? null,
            null,
            $validated['company_name'] ?? null
        );

        if (! $duplicate) {
            return response()->json(['duplicate' => null]);
        }

        return response()->json([
            'duplicate' => [
                'id' => $duplicate->id,
                'company_name' => $duplicate->company_name,
                'contact_name' => $duplicate->contact_name,
                'stage_label' => $duplicate->status instanceof LeadStatus
                    ? $duplicate->status->label()
                    : (string) $duplicate->status,
                'owner' => $duplicate->assignedUser?->name,
                'created_at' => $duplicate->created_at?->format('d M Y'),
                'url' => route('company.crm.leads.show', $duplicate, absolute: false),
            ],
        ]);
    }
}
