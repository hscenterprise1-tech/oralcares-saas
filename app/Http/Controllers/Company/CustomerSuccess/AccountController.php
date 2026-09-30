<?php

namespace App\Http\Controllers\Company\CustomerSuccess;

use App\ActivityType;
use App\CustomerChurnRisk;
use App\Http\Controllers\Controller;
use App\Mail\PasswordSetupMailable;
use App\Models\CommercialDocument;
use App\Models\CommercialInvoice;
use App\Models\CompanyPlan;
use App\Models\CompanyQuotation;
use App\Models\CompanySupportTicket;
use App\Models\CrmDocument;
use App\Models\Lead;
use App\Models\OnboardingProgress;
use App\Models\OnboardingTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\CommercialAccountHealthService;
use App\Services\CommercialEntitlementService;
use App\Services\CommercialExpansionService;
use App\Services\CommercialSubscriptionService;
use App\Services\CustomerSuccess\AccountSnapshotService;
use App\Services\LeadWorkspaceService;
use App\Services\Lifecycle\AccountLifecycleService;
use App\SubscriptionStatus;
use App\Support\CrmScope;
use App\UserType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountController extends Controller
{
    public function __construct(
        protected CommercialAccountHealthService $healthService,
        protected CommercialExpansionService $expansionService,
        protected CommercialEntitlementService $entitlementService,
        protected CommercialSubscriptionService $subscriptionService,
        protected LeadWorkspaceService $leadService
    ) {}

    /**
     * The clinic administrator this account's invite belongs to.
     *
     * Both call sites used to fall back to `User::where('email', $org->email)`
     * with no tenant constraint. Two accounts sharing a contact address — a
     * group buying several clinics, which is the normal case — resolved to
     * whichever row came first, and `resendOnboardingInvite()` then mailed a
     * password-setup link for one customer to a user at another.
     *
     * The fallback stays, because an account whose admin was created before the
     * user carried a `user_type` still needs to be reachable; it is simply
     * bounded to the account that is asking.
     */
    protected function primaryAdminFor(Organization $organization): ?User
    {
        return User::query()
            ->where('organization_id', $organization->id)
            ->where('user_type', UserType::ClinicUser)
            ->first()
            ?? User::query()
                ->where('organization_id', $organization->id)
                ->where('email', $organization->email)
                ->first();
    }

    /**
     * Customer Accounts Directory Workspace.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Organization::class);

        $user = $request->user();

        // 1. Base scoped query strictly excluding provider organizations
        /*
         * Trashed accounts are excluded by the join, not by a WHERE clause.
         *
         * `Organization` is soft-deleted, so every query against it carries
         * `deleted_at IS NULL`. That one extra predicate was enough to make
         * MySQL stop driving from `customer_success_snapshots` and sort the
         * whole book instead — 0.5 ms against 2,025 ms for the same twenty-five
         * rows, measured at 120,000 accounts.
         *
         * `withTrashed()` drops the predicate and the INNER JOIN takes over the
         * job: a trashed account has no projection row, because
         * `OrganizationObserver` removes it on delete and puts it back on
         * restore. `CustomerSuccessInvariantTest` pins that both ways — without
         * it, deleted customers would reappear in somebody's book in silence.
         */
        $baseScoped = CrmScope::customerAccounts($user)->withTrashed();

        /*
         * 2. Every headline figure, in one pass over the book.
         *
         * These were six COUNT queries, each re-running the scope and its join.
         * Six scans of `organizations` on every page load is survivable at
         * eight accounts and is not the shape this page has to hold at a
         * hundred and twenty thousand — the counts all read the same rows, so
         * they are conditional sums over one scan instead.
         *
         * `LOWER()` on churn risk rather than an IN list carrying both cases:
         * seeders write `High` and `high`, and an unrecognised third spelling
         * should not silently drop an at-risk account out of the count.
         */
        $metrics = function () use ($baseScoped) {
            $aggregate = (clone $baseScoped)
                ->join('customer_success_snapshots as css', 'css.organization_id', '=', 'organizations.id')
                ->selectRaw(<<<'SQL'
                    COUNT(*) AS total_accounts,
                    SUM(CASE WHEN css.health_score >= 80 THEN 1 ELSE 0 END) AS healthy_count,
                    SUM(CASE WHEN LOWER(COALESCE(css.churn_risk, '')) IN ('high', 'critical')
                               OR css.health_score < 50 THEN 1 ELSE 0 END) AS at_risk_count,
                    SUM(CASE WHEN css.days_to_expiry BETWEEN 0 AND 30 THEN 1 ELSE 0 END) AS renewing_30_count,
                    SUM(CASE WHEN css.stage = 'onboarding'
                               OR (css.onboarding_status IS NOT NULL AND css.onboarding_status <> 'completed') THEN 1 ELSE 0 END) AS onboarding_count,
                    SUM(CASE WHEN css.expansion_opportunity = 1 THEN 1 ELSE 0 END) AS expansion_count
                SQL)
                ->first();

            return [
                'total_accounts' => (int) ($aggregate->total_accounts ?? 0),
                'healthy_count' => (int) ($aggregate->healthy_count ?? 0),
                'at_risk_count' => (int) ($aggregate->at_risk_count ?? 0),
                'renewing_30_count' => (int) ($aggregate->renewing_30_count ?? 0),
                'onboarding_count' => (int) ($aggregate->onboarding_count ?? 0),
                'expansion_count' => (int) ($aggregate->expansion_count ?? 0),
            ];
        };

        ['view' => $view, 'sort' => $sort, 'direction' => $direction] = $this->viewState($request);

        $query = $this->filteredAccountsQuery($request, $baseScoped, [
            'assignedUser',
            'snapshot.plan',
            'snapshot.activeSubscription',
        ]);

        // Bounded per_page
        $perPage = max(10, min(100, (int) $request->input('per_page', 25)));

        /*
         * Paged forwards, not counted.
         *
         * `paginate()` runs a COUNT over the whole filtered set to work out how
         * many pages there are. At 120,000 accounts that was 440 ms of the
         * page's 453 — ninety-seven per cent of the cost, spent so a reader
         * could see a page number out of four thousand eight hundred that they
         * were never going to click. Nothing else on the request came close:
         * the list query itself is 1.5 ms.
         *
         * `simplePaginate` fetches one row more than a page and reports whether
         * it found it. The whole-book total still appears, from the deferred
         * metrics band, which was already counting it.
         */
        $paginator = $query->simplePaginate($perPage)->withQueryString();

        // Transform collection using snapshot data with zero per-row service calculations
        $transformedAccounts = $paginator->getCollection()->map(function (Organization $org) {
            $snapshot = $org->snapshot;

            $mrrRaw = $snapshot?->mrr !== null ? (float) $snapshot->mrr : null;
            $arrRaw = $mrrRaw !== null ? round($mrrRaw * 12, 2) : 0.0;

            return [
                'id' => $org->id,
                'name' => $org->name,
                'legal_name' => $org->legal_name,
                'contact_person' => $org->contact_person ?? $org->owner_name ?? '—',
                'email' => $org->email,
                'mobile' => $org->mobile,
                'city' => $org->city,
                'state' => $org->state,
                'clinic_type' => $org->clinic_type?->value ?? (string) $org->clinic_type,
                'assigned_user' => $org->assignedUser ? [
                    'id' => $org->assignedUser->id,
                    'name' => $org->assignedUser->name,
                    'email' => $org->assignedUser->email,
                ] : null,
                'health_score' => $snapshot?->health_score ?? (int) ($org->health_score ?? 100),
                'health_dimensions' => $snapshot?->health_dimensions,
                'churn_risk' => $snapshot?->churn_risk?->value ?? (string) ($snapshot?->churn_risk ?? $org->churn_risk?->value ?? $org->churn_risk ?? 'low'),
                'renewal_probability' => $snapshot?->renewal_probability ?? (int) ($org->renewal_probability ?? 75),
                'stage' => $snapshot?->stage ?? 'not_provisioned',
                'has_subscription' => (bool) ($snapshot?->has_subscription ?? false),
                'active_subscription_id' => $snapshot?->active_subscription_id,
                'plan_name' => $snapshot?->plan?->name ?? 'Standard Plan',
                'mrr' => $mrrRaw !== null ? '₹'.number_format($mrrRaw).' / mo' : '—',
                'mrr_raw' => $mrrRaw,
                'arr' => $mrrRaw !== null ? '₹'.number_format($arrRaw) : '—',
                'arr_raw' => $arrRaw,
                'renewal_date' => $snapshot?->ends_at ? $snapshot->ends_at->format('d M Y') : '—',
                'days_to_expiry' => $snapshot?->days_to_expiry,
                'purchased_seats' => $snapshot?->purchased_seats ?? 0,
                'used_seats' => $snapshot?->used_seats ?? 0,
                'seat_utilization_pct' => $snapshot?->seat_utilization_pct,
                'purchased_branches' => $snapshot?->purchased_branches ?? 0,
                'active_branches' => $snapshot?->active_branches ?? 0,
                'branch_utilization_pct' => $snapshot?->branch_utilization_pct,
                'expansion_opportunity' => (bool) ($snapshot?->expansion_opportunity ?? $org->expansion_opportunity),
                'onboarding_status' => $snapshot?->onboarding_status ?? 'completed',
                'onboarding_current_step' => $snapshot?->onboarding_current_step ?? 'go_live',
                'last_activity_at' => $snapshot?->last_activity_at ? $snapshot->last_activity_at->format('d M Y, H:i') : null,
                'created_at' => $org->created_at?->toISOString(),
            ];
        });

        return Inertia::render('Company/CustomerSuccess/Accounts/Index', [
            'accounts' => $transformedAccounts,
            /*
             * Two urls rather than a list of page numbers. There is no `total`
             * or `last_page` here because working either out means counting the
             * whole filtered set, which is the cost this page stopped paying.
             */
            'paginator' => [
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'prev_url' => $paginator->previousPageUrl(),
                'next_url' => $paginator->nextPageUrl(),
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
            ],
            /*
             * The six headline counts, after the first paint.
             *
             * They are one scan of the whole book — 770 ms at 120,000 accounts,
             * measured — and they say the same thing on page 1 as on page 400.
             * Nobody opens this page to read them, and holding the table behind
             * them made every navigation wait for a figure that had not moved.
             */
            'metrics' => Inertia::defer(fn () => $metrics(), 'metrics'),
            'filters' => [
                'search' => $request->input('search', ''),
                'view' => $view,
                'stage' => $request->input('stage', ''),
                'sort' => $sort,
                'direction' => $direction,
                'per_page' => $perPage,
            ],
            'can' => [
                'viewAccount' => Gate::allows('viewAny', Organization::class),
                'manageAccount' => Gate::allows('cs.accounts.manage') || Gate::allows('manage-organizations'),
                'export' => Gate::allows('viewAny', Organization::class),
            ],
            /*
             * Who a selection may be reassigned to.
             *
             * Company-portal staff only, and only sent to a reader who may
             * actually perform the reassignment — offering names to somebody
             * the endpoint would refuse is how a page teaches its user that the
             * product is broken. Unlike the clinic-user list this is bounded by
             * headcount, not by customer count.
             */
            'assignableOwners' => Gate::allows('cs.accounts.manage')
                ? User::query()
                    ->where('user_type', '!=', UserType::ClinicUser)
                    ->orderBy('name')
                    ->limit(200)
                    ->get(['id', 'name'])
                    ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
                    ->values()
                : [],
        ]);
    }

    /**
     * The account book as the reader currently has it filtered, searched and sorted.
     *
     * Shared by the list and the export on purpose. An export built from its own
     * copy of these rules is an export that quietly stops matching the screen it
     * was taken from â and the whole point of exporting a worklist is that it is
     * the worklist.
     *
     * @param  Builder<Organization>  $baseScoped
     * @param  list<string>  $with
     * @return Builder<Organization>
     */
    /**
     * The current worklist, as a CSV.
     *
     * Customer Success had no export at all while CRM has had one since Phase 3
     * — and a renewals or at-risk list nobody can take off the screen is a list
     * that gets retyped into a spreadsheet by hand.
     *
     * Streamed and chunked rather than assembled: a hundred and twenty thousand
     * accounts will not fit in a response buffer, and the point of this module
     * is that it holds at that number.
     *
     * The filters are the reader's own, taken from the same builder the list
     * uses. The *order* is not: chunking over a join needs a stable key, and a
     * sort on a nullable snapshot column does not provide one, so rows come out
     * by account id. A spreadsheet re-sorts in one click; a chunked export that
     * silently skips or repeats rows is unfixable after the fact.
     */
    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('viewAny', Organization::class);

        $query = $this->filteredAccountsQuery(
            $request,
            CrmScope::customerAccounts($request->user()),
            ['assignedUser:id,name,email', 'snapshot.plan:id,name'],
        )
            ->reorder('organizations.id')
            ->addSelect([
                'css.health_score as css_health_score',
                'css.churn_risk as css_churn_risk',
                'css.renewal_probability as css_renewal_probability',
                'css.stage as css_stage',
                'css.mrr as css_mrr',
                'css.ends_at as css_ends_at',
                'css.days_to_expiry as css_days_to_expiry',
                'css.purchased_seats as css_purchased_seats',
                'css.used_seats as css_used_seats',
                'css.seat_utilization_pct as css_seat_utilization_pct',
                'css.onboarding_status as css_onboarding_status',
            ]);

        $filename = 'customer_success_accounts_'.now()->format('Y_m_d_His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Account ID', 'Account', 'Contact Person', 'Email', 'Mobile', 'City', 'State',
                'Account Manager', 'Stage', 'Health Score', 'Churn Risk', 'Renewal Probability %',
                'Plan', 'MRR (INR)', 'ARR (INR)', 'Renewal Date', 'Days To Expiry',
                'Purchased Seats', 'Used Seats', 'Seat Utilisation %', 'Onboarding Status', 'Created At',
            ]);

            $query->chunk(500, function ($accounts) use ($handle) {
                foreach ($accounts as $account) {
                    /*
                     * Unrecorded MRR stays blank rather than becoming zero. A
                     * spreadsheet will happily average a column of fabricated
                     * zeroes and report a number nobody ever recorded.
                     */
                    $mrr = $account->css_mrr !== null ? (float) $account->css_mrr : null;

                    fputcsv($handle, [
                        $account->id,
                        $account->name,
                        $account->contact_person ?? $account->owner_name ?? '',
                        $account->email ?? '',
                        $account->mobile ?? '',
                        $account->city ?? '',
                        $account->state ?? '',
                        $account->assignedUser?->name ?? 'Unassigned',
                        $account->css_stage ?? 'not_provisioned',
                        $account->css_health_score ?? $account->health_score ?? '',
                        $account->css_churn_risk ?? ($account->churn_risk?->value ?? $account->churn_risk ?? ''),
                        $account->css_renewal_probability ?? $account->renewal_probability ?? '',
                        $account->snapshot?->plan?->name ?? '',
                        $mrr !== null ? number_format($mrr, 2, '.', '') : '',
                        $mrr !== null ? number_format($mrr * 12, 2, '.', '') : '',
                        $account->css_ends_at ? Carbon::parse($account->css_ends_at)->format('Y-m-d') : '',
                        $account->css_days_to_expiry ?? '',
                        $account->css_purchased_seats ?? '',
                        $account->css_used_seats ?? '',
                        $account->css_seat_utilization_pct ?? '',
                        $account->css_onboarding_status ?? '',
                        $account->created_at?->format('Y-m-d') ?? '',
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Reassign or re-risk a set of accounts in one action.
     *
     * A CS lead who inherits a territory otherwise opens a few hundred accounts
     * one at a time. Both fields here already exist as single-account writes on
     * Account 360; this is the same write applied to a selection, not a new
     * authority.
     *
     * Two rules make it safe. The ids are re-resolved through `CrmScope` rather
     * than trusted from the request, so a posted id outside the reader's scope
     * is silently not there rather than quietly updated. And the whole thing is
     * one transaction: a bulk action that half-applies leaves a territory split
     * between two owners with nothing recording where the split fell.
     */
    public function bulkUpdate(Request $request)
    {
        Gate::authorize('cs.accounts.manage');

        /* Same case-folding as the single-account write — one vocabulary. */
        if ($request->filled('churn_risk')) {
            $request->merge(['churn_risk' => ucfirst(strtolower((string) $request->input('churn_risk')))]);
        }

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'churn_risk' => ['nullable', Rule::enum(CustomerChurnRisk::class)],
        ]);

        if (! $request->filled('assigned_user_id') && ! $request->filled('churn_risk')) {
            return back()->withErrors(['bulk' => 'Choose an account manager or a churn risk to apply.']);
        }

        $accounts = CrmScope::customerAccounts($request->user())
            ->whereIn('organizations.id', $validated['ids'])
            ->get();

        if ($accounts->isEmpty()) {
            return back()->withErrors(['bulk' => 'None of the selected accounts are yours to change.']);
        }

        $changes = [];

        if ($request->filled('assigned_user_id')) {
            $changes['assigned_user_id'] = (int) $validated['assigned_user_id'];
        }

        if ($request->filled('churn_risk')) {
            $changes['churn_risk'] = CustomerChurnRisk::from($validated['churn_risk']);
            /*
             * Stamped, exactly as the single-account write does. Without this
             * the projection refresh a few lines below derives the risk again
             * and silently undoes the bulk change the reader just made.
             */
            $changes['churn_risk_overridden_at'] = now();
            $changes['metrics_overridden_by'] = $request->user()?->id;
        }

        $actor = $request->user();

        $note = collect($changes)->map(
            fn ($value, $key) => $key === 'assigned_user_id'
                ? 'account manager reassigned'
                : 'churn risk set to '.($value instanceof CustomerChurnRisk ? $value->value : $value)
        )->join(', ');

        DB::transaction(function () use ($accounts, $changes, $actor, $note) {
            foreach ($accounts as $account) {
                $account->update($changes);

                $account->activities()->create([
                    'user_id' => $actor?->id,
                    'type' => ActivityType::RecordUpdated,
                    'description' => 'Bulk update: '.$note.'.',
                ]);
            }
        });

        /*
         * Outside the transaction. The projection is derived state — rebuilding
         * it is not part of the write being committed, and holding a
         * transaction open across five hundred recomputations is how a bulk
         * action becomes a lock-wait timeout.
         */
        $snapshots = app(AccountSnapshotService::class);

        foreach ($accounts as $account) {
            $snapshots->refreshIfCustomer($account);
        }

        return back()->with('success', $accounts->count().' account'.($accounts->count() === 1 ? '' : 's').' updated.');
    }

    /**
     * The view, sort column and direction this request is asking for, normalised.
     *
     * One reader, because three callers need the same answer: the query that
     * filters, the payload that tells the page which segment is active, and the
     * export that has to reproduce both.
     *
     * @return array{view: string, sort: string, direction: string}
     */
    protected function viewState(Request $request): array
    {
        return [
            'view' => (string) $request->input('view', $request->input('filter', 'all')),
            'sort' => (string) $request->input('sort', 'name'),
            'direction' => strtolower((string) $request->input('direction', 'asc')) === 'desc' ? 'desc' : 'asc',
        ];
    }

    protected function filteredAccountsQuery(Request $request, $baseScoped, array $with = [])
    {
        /*
         * Joined rather than eager-loaded, because every filter and every sort
         * below reads a snapshot column. Doing it in SQL is what keeps this
         * page one query at a hundred and twenty thousand accounts.
         *
         * Inner, and the word matters more than anything else on this page. A
         * LEFT JOIN forces the planner to drive from `organizations`, so an
         * order on any projected column has to materialise the whole book and
         * filesort it; an INNER JOIN lets it read the snapshot's own index in
         * order and stop after a page. Measured at 120,000 accounts:
         *
         *     LEFT  JOIN … ORDER BY css.days_to_expiry LIMIT 25   2,892 ms
         *     INNER JOIN … ORDER BY css.days_to_expiry LIMIT 25       0.5 ms
         *
         * Safe because the projection is complete by construction — backfilled
         * for accounts that already existed, written by `OrganizationObserver`
         * for every new one, and reconciled nightly.
         */
        $query = (clone $baseScoped)
            ->join('customer_success_snapshots as css', 'css.organization_id', '=', 'organizations.id')
            ->select('organizations.*')
            ->with($with);

        // Search across meaningful identity fields with prefix index matching
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $cleanSearch = ltrim($search, '#');

            $query->where(function ($q) use ($search, $cleanSearch) {
                $q->where('organizations.name', 'like', "{$search}%")
                    ->orWhere('organizations.contact_person', 'like', "{$search}%")
                    ->orWhere('organizations.email', 'like', "{$search}%")
                    ->orWhere('organizations.city', 'like', "{$search}%");

                if (is_numeric($cleanSearch)) {
                    $q->orWhere('organizations.id', (int) $cleanSearch);
                }
            });
        }

        /*
         * One lifecycle stage, exactly as the projection recorded it.
         *
         * The Overview's stage ladder is a set of counts, and a count nobody can
         * open is a dead end — clicking "9 lapsed" used to land on all accounts
         * with the filter silently dropped. Separate from `view` on purpose:
         * `view` is a work queue ("at risk", "renewing soon"), while this is
         * simply where an account sits, and the two combine.
         */
        if ($request->filled('stage')) {
            $query->where('css.stage', (string) $request->input('stage'));
        }

        // Customer Success work view filtering
        ['view' => $view, 'sort' => $sort, 'direction' => $direction] = $this->viewState($request);
        match ($view) {
            'at_risk' => $query->where(function ($q) {
                $q->whereIn('css.churn_risk', ['High', 'Critical', 'high', 'critical'])
                    ->orWhere('css.health_score', '<', 50);
            }),
            'renewing_30' => $query->whereNotNull('css.days_to_expiry')
                ->where('css.days_to_expiry', '>=', 0)
                ->where('css.days_to_expiry', '<=', 30),
            'renewing_60' => $query->whereNotNull('css.days_to_expiry')
                ->where('css.days_to_expiry', '>=', 0)
                ->where('css.days_to_expiry', '<=', 60),
            'renewing_90' => $query->whereNotNull('css.days_to_expiry')
                ->where('css.days_to_expiry', '>=', 0)
                ->where('css.days_to_expiry', '<=', 90),
            'onboarding' => $query->where(function ($q) {
                $q->where('css.stage', 'setup')
                    ->orWhere(function ($sq) {
                        $sq->whereNotNull('css.onboarding_status')
                            ->where('css.onboarding_status', '!=', 'completed');
                    });
            }),
            'over_capacity' => $query->where(function ($q) {
                $q->where('css.seat_utilization_pct', '>=', 100)
                    ->orWhere('css.branch_utilization_pct', '>=', 100);
            }),
            'lapsed' => $query->where(function ($q) {
                $q->where('css.stage', 'lapsed')
                    ->orWhere(function ($sq) {
                        $sq->whereNotNull('css.days_to_expiry')
                            ->where('css.days_to_expiry', '<', 0);
                    });
            }),
            default => null,
        };

        /*
         * Sorting, on the bare column every time.
         *
         * These used to wrap the projected columns — `COALESCE(css.health_score,
         * organizations.health_score, 100)`, `CASE WHEN css.days_to_expiry IS
         * NULL …` — to cover accounts that had no snapshot yet. An expression
         * cannot be read from an index, so each of those turned a twenty-five
         * row page into a full sort of the book. Measured at 120,000 accounts:
         *
         *     ORDER BY COALESCE(css.health_score, …)   3,277 ms
         *     ORDER BY css.health_score                    0.7 ms
         *     ORDER BY CASE WHEN css.days_to_expiry …    193 ms
         *     ORDER BY css.days_to_expiry                  0.8 ms
         *
         * The fallbacks are gone because what they guarded against is gone: the
         * projection now has a row for every customer account, so the snapshot
         * is the answer rather than a maybe. MySQL's own null ordering stands —
         * nulls first ascending, last descending — which reads correctly for
         * every one of these: soonest expiry first, worst health first.
         */
        $sortColumn = match ($sort) {
            'health_score' => 'css.health_score',
            'churn_risk' => 'css.churn_risk',
            'renewal_probability' => 'css.renewal_probability',
            'days_to_expiry' => 'css.days_to_expiry',
            'seat_utilization_pct' => 'css.seat_utilization_pct',
            'stage' => 'css.stage',
            'created_at' => 'organizations.created_at',
            'last_activity_at' => 'css.last_activity_at',
            default => 'organizations.name',
        };

        /*
         * A second column nobody asked for, because a page boundary needs one.
         *
         * Thousands of accounts share a health score. Without a tiebreaker the
         * rows either side of a page break are whatever the planner happened to
         * emit, so one account can appear on two pages and another on none.
         *
         * It has to be the primary key of the table the sort column lives on.
         * InnoDB appends a table's primary key to every secondary index, so
         * `ORDER BY css.days_to_expiry, css.id` is read straight off the index
         * while `ORDER BY css.days_to_expiry, organizations.id` spans two
         * tables and falls back to a sort of the whole book — 0.6 ms against
         * 2,008 ms at 120,000 accounts, for the same twenty-five rows.
         */
        $tiebreaker = str_starts_with($sortColumn, 'css.') ? 'css.id' : 'organizations.id';

        return $query
            ->orderBy($sortColumn, $direction)
            ->orderBy($tiebreaker, 'asc');
    }

    /**
     * Unified Account 360 Detail Surface.
     *
     * URL-driven tab workspace with lightweight eager payload and Inertia deferred
     * props for scale-safety at 120,000+ accounts.
     */
    public function show(Request $request, Organization $organization): Response
    {
        Gate::authorize('view', $organization);

        if (! CrmScope::customerAccounts($request->user())->where('organizations.id', $organization->id)->exists()) {
            abort(404);
        }

        /*
         * Five destinations, not twelve.
         *
         * The page had a tab for every table it could reach — health, usage,
         * renewals, lifecycle, commercial, support, timeline — which is a
         * filing cabinet, not an account. Twelve tabs means twelve places to
         * look before you know anything, and the first one opened on two empty
         * cards.
         *
         * They are grouped by the question being asked rather than by the table
         * being read:
         *
         *   Overview   what is wrong with this customer and what do I do next
         *   Contract   what they pay for, what they use, and the four verbs
         *              that change it
         *   Setup      getting them live: onboarding, clinics, branches
         *   People     who to speak to
         *   Records    the paper: invoices, quotations, support, files
         */
        $validTabs = [
            'overview' => 'Overview',
            'contract' => 'Contract',
            'setup' => 'Setup',
            'people' => 'People',
            'records' => 'Records',
        ];

        /*
         * Every old tab still resolves, so a bookmark, a deep link from another
         * module and the redirect from the retired CRM onboarding route all
         * land on the tab that absorbed them rather than silently on Overview.
         */
        $absorbed = [
            'health' => 'overview',
            'timeline' => 'overview',
            'subscription' => 'contract',
            'usage' => 'contract',
            'renewal' => 'contract',
            'lifecycle' => 'contract',
            'onboarding' => 'setup',
            'clinics' => 'setup',
            'contacts' => 'people',
            'commercial' => 'records',
            'documents' => 'records',
            'support' => 'records',
        ];

        $requestedTab = (string) $request->input('tab', 'overview');
        $requestedTab = $absorbed[$requestedTab] ?? $requestedTab;
        $activeTab = array_key_exists($requestedTab, $validTabs) ? $requestedTab : 'overview';

        // Eagerly load only what is needed for the header card and eager context
        $organization->loadMissing(['assignedUser', 'snapshot']);

        $stage = $organization->snapshot?->stage ?? app(AccountLifecycleService::class)->resolveStage($organization);

        // Active subscription summary using recorded MRR/ARR (never recalculating seats * 1500)
        $activeSubModel = $organization->subscriptions()
            ->with('plan')
            ->where('status', SubscriptionStatus::Active->value)
            ->latest('id')
            ->first()
            ?? $organization->subscriptions()->with('plan')->latest('id')->first();

        $activeSubscription = null;
        if ($activeSubModel) {
            $mrrRaw = $activeSubModel->mrr !== null ? (float) $activeSubModel->mrr : null;
            $arrRaw = $mrrRaw !== null ? round($mrrRaw * 12, 2) : 0.0;
            $statusVal = $activeSubModel->status instanceof \BackedEnum ? $activeSubModel->status->value : (string) $activeSubModel->status;

            $activeSubscription = [
                'id' => $activeSubModel->id,
                'planName' => $activeSubModel->plan?->name ?? $activeSubModel->plan_name ?? 'Standard Plan',
                'status' => ucwords(str_replace('_', ' ', $statusVal)),
                'mrr' => $mrrRaw !== null ? '₹'.number_format($mrrRaw).' / mo' : '—',
                'mrr_raw' => $mrrRaw,
                'arr' => $mrrRaw !== null ? '₹'.number_format($arrRaw) : '—',
                'arr_raw' => $arrRaw,
                'startsAt' => $activeSubModel->starts_at?->format('d M Y'),
                'endsAt' => $activeSubModel->ends_at?->format('d M Y'),
                'renewalDate' => $activeSubModel->ends_at ? $activeSubModel->ends_at->format('d M Y') : '—',
                'daysToRenewal' => $activeSubModel->ends_at ? (int) now()->diffInDays($activeSubModel->ends_at, false) : null,
                'billingPeriod' => $activeSubModel->plan?->billing_period === 'yearly' ? 'Annual Pre-Paid' : 'Monthly',
            ];
        }

        $account = [
            'id' => $organization->id,
            'name' => $organization->name,
            'legal_name' => $organization->legal_name,
            'type' => $organization->type?->value ?? (string) $organization->type,
            'clinic_type' => $organization->clinic_type?->value ?? (string) $organization->clinic_type,
            'organization_scale' => $organization->organization_scale?->value ?? (string) $organization->organization_scale,
            'owner_name' => $organization->owner_name,
            'contact_person' => $organization->contact_person,
            'email' => $organization->email,
            'mobile' => $organization->mobile,
            'gst' => $organization->gst,
            'website' => $organization->website,
            'address' => $organization->address,
            'city' => $organization->city,
            'state' => $organization->state,
            'country' => $organization->country,
            'timezone' => $organization->timezone,
            'health_score' => $organization->snapshot?->health_score ?? (int) ($organization->health_score ?? 100),
            'renewal_probability' => $organization->snapshot?->renewal_probability ?? (int) ($organization->renewal_probability ?? 80),
            'churn_risk' => $organization->snapshot?->churn_risk?->value ?? (string) ($organization->snapshot?->churn_risk ?? $organization->churn_risk?->value ?? $organization->churn_risk ?? 'Low'),
            'expansion_opportunity' => (bool) ($organization->snapshot?->expansion_opportunity ?? $organization->expansion_opportunity),
            'stage' => $stage,
            'created_at' => $organization->created_at?->toISOString(),
            'assigned_user' => $organization->assignedUser ? [
                'id' => $organization->assignedUser->id,
                'name' => $organization->assignedUser->name,
                'email' => $organization->assignedUser->email,
            ] : null,
        ];

        return Inertia::render('Company/CustomerSuccess/Accounts/Show', [
            'account' => $account,
            'activeSubscription' => $activeSubscription,
            'activeTab' => $activeTab,
            'validTabs' => $validTabs,
            'can' => [
                'update' => $request->user() !== null && Gate::forUser($request->user())->allows('update', $organization),
            ],

            // Deferred sections (loaded independently and bounded)
            /*
             * The score, and why the risk beside it reads the way it does.
             *
             * A churn risk with no reasoning is a colour: a rep opening an
             * account to find "High" with nothing behind it has to reconstruct
             * the case themselves. The nightly pass already records the signals
             * it counted, so the page can show the working.
             */
            /*
             * What is wrong with this customer, and what to do about it.
             *
             * The page used to open on two cards that said "Nothing recorded
             * yet". An account page's first screen has to answer the question
             * its reader actually arrived with — is this one in trouble, what
             * is it worth, and what happens next — before it offers them twelve
             * tables to go and find out.
             *
             * Every item is derived from a record that already exists. Nothing
             * here is a new score or a new status; it is the same signals the
             * nightly projection counted, said as sentences with somewhere to
             * click.
             */
            'attention' => Inertia::defer(function () use ($organization) {
                $snapshot = $organization->snapshot;
                $items = [];

                if ($snapshot?->days_to_expiry !== null) {
                    $days = (int) $snapshot->days_to_expiry;

                    if ($days < 0) {
                        $items[] = [
                            'tone' => 'bad',
                            'title' => 'The contract has expired',
                            'detail' => abs($days).' days ago. They are outside their paid term.',
                            'action' => 'Renew',
                            'tab' => 'contract',
                        ];
                    } elseif ($days <= 30) {
                        $items[] = [
                            'tone' => 'warn',
                            'title' => "Renews in {$days} days",
                            'detail' => 'Confirm they are staying before the date passes.',
                            'action' => 'Renew',
                            'tab' => 'contract',
                        ];
                    }
                }

                if ($snapshot && $snapshot->purchased_seats > 0 && $snapshot->used_seats > $snapshot->purchased_seats) {
                    $items[] = [
                        'tone' => 'bad',
                        'title' => 'Using more seats than they bought',
                        'detail' => "{$snapshot->used_seats} in use against {$snapshot->purchased_seats} purchased.",
                        'action' => 'Adjust entitlements',
                        'tab' => 'contract',
                    ];
                } elseif ($snapshot?->seat_utilization_pct !== null && $snapshot->seat_utilization_pct >= 90) {
                    $items[] = [
                        'tone' => 'warn',
                        'title' => 'Nearly out of seats',
                        'detail' => "{$snapshot->seat_utilization_pct}% of what they bought is in use.",
                        'action' => 'Adjust entitlements',
                        'tab' => 'contract',
                    ];
                }

                if ($snapshot && $snapshot->onboarding_status !== 'completed') {
                    $items[] = [
                        'tone' => 'info',
                        'title' => 'Still being set up',
                        'detail' => $snapshot->onboarding_current_step
                            ? 'Waiting on: '.str_replace('_', ' ', (string) $snapshot->onboarding_current_step)
                            : 'Onboarding has not finished.',
                        'action' => 'Open setup',
                        'tab' => 'setup',
                    ];
                }

                /*
                 * The churn signals, each as its own line. A risk badge with no
                 * reasoning is a colour; these are what the nightly pass
                 * actually counted.
                 */
                foreach ($snapshot?->churn_reasons ?? [] as $reason) {
                    $items[] = [
                        'tone' => 'warn',
                        'title' => $reason,
                        'detail' => null,
                        'action' => null,
                        'tab' => null,
                    ];
                }

                if ($organization->assigned_user_id === null) {
                    $items[] = [
                        'tone' => 'warn',
                        'title' => 'Nobody owns this account',
                        'detail' => 'It will not appear in anybody\'s book until it has an owner.',
                        'action' => null,
                        'tab' => null,
                    ];
                }

                return $items;
            }, 'overview'),

            'healthData' => Inertia::defer(function () use ($organization) {
                $snapshot = $organization->snapshot;

                return $this->healthService->calculateHealthScore($organization) + [
                    'churn_reasons' => $snapshot?->churn_reasons ?? [],
                    'churn_overridden' => $organization->churn_risk_overridden_at !== null,
                    'health_overridden' => $organization->health_score_overridden_at !== null,
                    'overridden_by' => $organization->metricsOverriddenBy?->name,
                ];
            }, 'overview'),

            /*
             * The onboarding record *and* the sequence it is being measured
             * against.
             *
             * The steps used to be an array of labels inside the React page, so
             * the server could not name them, nothing could test them, and every
             * customer — a single dental clinic and a multi-speciality hospital
             * alike — was handed the same five. They now come from the template
             * this account resolved to when its onboarding started.
             */
            'onboarding' => Inertia::defer(function () use ($organization) {
                $progress = $organization->onboardingProgress;

                $template = $progress?->template
                    ?? OnboardingTemplate::forClinicType($organization->clinic_type);

                $steps = ($template?->steps ?? collect())
                    ->map(fn ($step) => [
                        'key' => $step->step_key,
                        'label' => $step->label,
                        'description' => $step->description,
                        'is_required' => (bool) $step->is_required,
                    ])
                    ->values();

                return [
                    'template_name' => $template?->name,
                    'steps' => $steps,
                    'status' => $progress?->status ?? 'pending',
                    'current_step' => $progress?->current_step ?? $steps->first()['key'] ?? null,
                    'completed_steps' => $progress?->completed_steps ?? [],
                    'required_steps_complete' => $progress?->hasCompletedRequiredSteps() ?? false,
                ];
            }, 'setup'),

            'primaryAdmin' => Inertia::defer(function () use ($organization) {
                $primaryAdmin = $this->primaryAdminFor($organization);

                return $primaryAdmin ? [
                    'id' => $primaryAdmin->id,
                    'name' => $primaryAdmin->name,
                    'email' => $primaryAdmin->email,
                    'user_type' => $primaryAdmin->user_type?->value ?? (string) $primaryAdmin->user_type,
                    'email_verified_at' => $primaryAdmin->email_verified_at?->toISOString(),
                ] : null;
            }, 'people'),

            'subscriptions' => Inertia::defer(function () use ($organization) {
                return $organization->subscriptions()->with(['plan', 'organization.assignedUser'])->get()->map(function ($sub) {
                    $dto = $this->subscriptionService->transform($sub);
                    if ($sub->mrr !== null) {
                        $mrrRaw = (float) $sub->mrr;
                        $arrRaw = round($mrrRaw * 12, 2);
                        $dto['mrr'] = '₹'.number_format($mrrRaw).' / mo';
                        $dto['arr'] = '₹'.number_format($arrRaw);
                        $dto['mrrRaw'] = $mrrRaw;
                        $dto['arrRaw'] = $arrRaw;
                    }

                    return $dto;
                })->values();
            }, 'contract'),

            'entitlements' => Inertia::defer(fn () => $this->entitlementService->forOrganization($organization), 'contract'),

            /*
             * The plan catalogue, so the contract can be changed here.
             *
             * Upgrading, adding seats, renewing and cancelling used to mean
             * opening a CRM *deal* against a customer who had already bought.
             * They are contract verbs, and they belong on the tab that shows
             * the contract.
             */
            'plans' => Inertia::defer(fn () => CompanyPlan::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (CompanyPlan $plan) => ['id' => $plan->id, 'name' => $plan->name])
                ->values(), 'contract'),

            'expansionData' => Inertia::defer(fn () => $this->expansionService->calculateExpansionRadar($organization), 'contract'),

            'contacts' => Inertia::defer(fn () => $organization->contacts()->get()->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'email' => $c->email,
                'phone' => $c->phone,
                'designation' => $c->designation,
                'role' => $c->role?->value ?? (string) $c->role,
                'is_primary' => (bool) $c->is_primary,
                'decision_power' => $c->decision_power?->value ?? (string) $c->decision_power,
            ])->values(), 'people'),

            'clinics' => Inertia::defer(fn () => $organization->clinics()->with('branches')->get()->map(fn ($clinic) => [
                'id' => $clinic->id,
                'name' => $clinic->name,
                'slug' => $clinic->slug,
                'status' => $clinic->status?->value ?? (string) $clinic->status,
                'branches' => $clinic->branches->map(fn ($b) => [
                    'id' => $b->id,
                    'name' => $b->name,
                    'status' => $b->status?->value ?? (string) $b->status,
                    'is_main' => (bool) $b->is_main,
                    'address' => $b->address,
                    'city' => $b->city,
                    'state' => $b->state,
                    'phone' => $b->phone,
                    'email' => $b->email,
                    'chairs' => $b->chairs ?? 1,
                ])->values(),
            ])->values(), 'setup'),

            'branches' => Inertia::defer(fn () => $organization->branches()->with('clinic')->get()->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'clinic_name' => $b->clinic?->name ?? 'Main Clinic',
                'is_main' => (bool) $b->is_main,
                'address' => $b->address,
                'city' => $b->city,
                'state' => $b->state,
                'phone' => $b->phone,
                'email' => $b->email,
            ])->values(), 'setup'),

            'eligibleOwners' => Inertia::defer(fn () => User::query()
                ->where('organization_id', $organization->id)
                ->where('user_type', UserType::ClinicUser)
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
                ->values(), 'setup'),

            'commercialDocuments' => Inertia::defer(fn () => CommercialDocument::query()
                ->whereHas('lead', fn ($q) => $q->where('customer_organization_id', $organization->id))
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (CommercialDocument $d) => [
                    'id' => $d->id,
                    'document_number' => $d->document_number,
                    'type' => $d->type,
                    'status' => $d->status,
                    'total_amount' => (float) $d->grand_total,
                    'created_at' => $d->created_at?->format('d M Y'),
                ])
                ->values(), 'records'),

            'quotations' => Inertia::defer(fn () => CompanyQuotation::query()
                ->whereHas('lead', fn ($q) => $q->where('customer_organization_id', $organization->id))
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (CompanyQuotation $q) => [
                    'id' => $q->id,
                    'quotation_number' => $q->quotation_number,
                    'status' => $q->status instanceof \BackedEnum ? $q->status->value : (string) $q->status,
                    'total_amount' => (float) $q->grand_total,
                    'created_at' => $q->created_at?->format('d M Y'),
                ])
                ->values(), 'records'),

            'invoices' => Inertia::defer(fn () => CommercialInvoice::query()
                ->whereIn('company_quotation_id', CompanyQuotation::query()
                    ->whereHas('lead', fn ($q) => $q->where('customer_organization_id', $organization->id))
                    ->select('id'))
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (CommercialInvoice $i) => [
                    'id' => $i->id,
                    'invoice_number' => $i->invoice_number,
                    'status' => $i->status,
                    'total_amount' => (float) $i->final_amount,
                    'issued_at' => $i->issued_at?->format('d M Y'),
                    'paid_at' => $i->paid_at?->format('d M Y'),
                ])
                ->values(), 'records'),

            'attachedFiles' => Inertia::defer(function () use ($organization) {
                $leadIds = $organization->wonFromLeads()->pluck('id')->all();

                return CrmDocument::with('uploader')
                    ->where(function ($q) use ($organization, $leadIds) {
                        $q->where('documentable_type', 'organization')
                            ->where('documentable_id', $organization->id)
                            ->orWhere(function ($inner) use ($leadIds) {
                                $inner->where('documentable_type', 'lead')
                                    ->whereIn('documentable_id', $leadIds);
                            });
                    })
                    ->latest()
                    ->limit(50)
                    ->get();
            }, 'records'),

            'activities' => Inertia::defer(fn () => $organization->activities()->with('user')->latest('id')->limit(50)->get()->map(fn ($a) => [
                'id' => $a->id,
                'type' => $a->type?->value ?? (string) $a->type,
                'description' => $a->description,
                'created_at' => $a->created_at?->toISOString(),
                'user' => $a->user ? [
                    'id' => $a->user->id,
                    'name' => $a->user->name,
                ] : null,
            ])->values(), 'overview'),

            'tasks' => Inertia::defer(fn () => $organization->tasks()->with('assignedUser')->latest('id')->limit(50)->get()->map(fn ($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'description' => $t->description,
                'status' => $t->status?->value ?? (string) $t->status,
                'priority' => $t->priority?->value ?? (string) $t->priority,
                'due_at' => $t->due_at?->toISOString(),
            ])->values(), 'overview'),

            /*
             * This customer's support tickets.
             *
             * Customer Success has no case record of its own and does not need
             * one: `company_support_tickets` already carries `organization_id`
             * and the Support module already owns the workflow, the SLA targets
             * and the ticket timeline. Duplicating that here would be a second
             * place a customer's problems live, disagreeing with the first.
             *
             * Read-only. Raising and working a ticket stays in Support; this is
             * the account's view of it.
             */
            'supportTickets' => Inertia::defer(fn () => CompanySupportTicket::query()
                ->where('organization_id', $organization->id)
                ->with('assignee:id,employee_code')
                ->latest('id')
                ->limit(25)
                ->get()
                ->map(fn (CompanySupportTicket $ticket) => [
                    'id' => $ticket->id,
                    'reference' => $ticket->reference,
                    'subject' => $ticket->subject,
                    'status' => $ticket->status instanceof \BackedEnum ? $ticket->status->value : (string) $ticket->status,
                    'priority' => $ticket->priority instanceof \BackedEnum ? $ticket->priority->value : (string) $ticket->priority,
                    'category' => $ticket->category,
                    'opened_at' => $ticket->created_at?->format('d M Y'),
                    'resolved_at' => $ticket->resolved_at?->format('d M Y'),
                    /* Past its promised response or resolution time. */
                    /* The model already knows; this page does not re-derive it. */
                    'breaching_sla' => $ticket->breachedResolution() === true,
                ])
                ->values(), 'records'),

            'license' => Inertia::defer(function () use ($organization) {
                $license = $organization->license()->with('enabledModules')->first();

                return $license ? [
                    'id' => $license->id,
                    'license_key' => $license->license_key,
                    'license_type' => $license->license_type,
                    'max_users' => $license->max_users,
                    'max_doctors' => $license->max_doctors,
                    'max_branches' => $license->max_branches,
                    'is_active' => (bool) $license->is_active,
                    'expires_at' => $license->expires_at?->toISOString(),
                    'enabled_modules' => $license->enabledModules->pluck('module_name'),
                ] : null;
            }, 'contract'),
        ]);
    }

    /**
     * Update customer health metrics.
     */
    public function update(Request $request, Organization $organization)
    {
        Gate::authorize('update', $organization);

        /*
         * `CustomerChurnRisk` is capitalised ("Low", "Medium", "High") while
         * every caller — this module's own form, the seeders, the CRM payload —
         * sends lowercase. The previous hand-rolled check folded the case
         * before matching; an enum rule on its own quietly rejected the whole
         * request and redirected back, which looked like a save that did
         * nothing.
         */
        if ($request->filled('churn_risk')) {
            $request->merge(['churn_risk' => ucfirst(strtolower((string) $request->input('churn_risk')))]);
        }

        $validated = $request->validate([
            'health_score' => 'nullable|integer|min:0|max:100',
            'renewal_probability' => 'nullable|integer|min:0|max:100',
            'churn_risk' => ['nullable', Rule::enum(CustomerChurnRisk::class)],
            'expansion_opportunity' => 'nullable|boolean',
            'notes' => 'nullable|string',
            /*
             * Hand the derived values back. An override is a decision, and a
             * decision a rep can make they must also be able to unmake —
             * otherwise the first person to touch an account freezes its health
             * and churn forever.
             */
            'clear_health_override' => 'nullable|boolean',
            'clear_churn_override' => 'nullable|boolean',
        ]);

        $actor = $request->user();
        $now = now();
        $notes = [];

        $attributes = array_intersect_key($validated, array_flip([
            'renewal_probability',
            'expansion_opportunity',
        ]));

        /*
         * Setting either metric by hand pins it.
         *
         * The nightly projection derives health from five dimensions and churn
         * from the account's own signals. A person overriding one is saying
         * they know something the signals do not, so the stamp goes on and the
         * job leaves that field alone until it is cleared.
         */
        if ($request->boolean('clear_health_override')) {
            $attributes['health_score_overridden_at'] = null;
            $notes[] = 'health score returned to the computed value';
        } elseif ($request->filled('health_score')) {
            $attributes['health_score'] = (int) $validated['health_score'];
            $attributes['health_score_overridden_at'] = $now;
            $notes[] = "health score pinned to {$validated['health_score']}";
        }

        if ($request->boolean('clear_churn_override')) {
            $attributes['churn_risk_overridden_at'] = null;
            $notes[] = 'churn risk returned to the derived value';
        } elseif ($request->filled('churn_risk')) {
            $risk = CustomerChurnRisk::from($validated['churn_risk']);
            $attributes['churn_risk'] = $risk;
            $attributes['churn_risk_overridden_at'] = $now;
            $notes[] = "churn risk pinned to {$risk->value}";
        }

        if ($notes !== []) {
            $attributes['metrics_overridden_by'] = $actor?->id;
        }

        $organization->update($attributes);

        /*
         * Rebuilt immediately so the list the reader returns to agrees with
         * what they just set, rather than waiting for tonight's pass.
         */
        app(AccountSnapshotService::class)->refreshIfCustomer($organization);

        if ($notes !== [] || $request->hasAny(['renewal_probability', 'expansion_opportunity'])) {
            $organization->activities()->create([
                'user_id' => $actor?->id,
                'type' => ActivityType::Note,
                'description' => 'Account health metrics updated'
                    .($notes !== [] ? ': '.implode(', ', $notes) : '.')
                    .($request->notes ? "\nNotes: ".$request->notes : ''),
            ]);
        }

        return back()->with('success', 'Account metrics updated.');
    }

    /**
     * Mutate an account's onboarding record under a lock.
     *
     * Both writers read `completed_steps`, appended to it in PHP and wrote the
     * whole array back. Two reps advancing two steps on the same account lost
     * one of them, silently — and with no unique key on
     * `onboarding_progress.organization_id`, the `?? create()` on either path
     * could also mint a second progress row for one account under the same
     * race.
     *
     * The account row is the mutex. Locking it serialises the lookup, the
     * create and the update together, which is what makes the read-modify-write
     * safe; locking the progress row could not, because the row may not exist
     * yet at the moment two requests both look for it.
     *
     * @template TReturn
     *
     * @param  callable(OnboardingProgress): TReturn  $mutate
     * @return TReturn
     */
    protected function withLockedOnboarding(Organization $organization, callable $mutate): mixed
    {
        return DB::transaction(function () use ($organization, $mutate) {
            Organization::query()->whereKey($organization->getKey())->lockForUpdate()->first();

            $onboarding = OnboardingProgress::query()
                ->where('organization_id', $organization->id)
                ->orderBy('id')
                ->first()
                ?? $this->startOnboarding($organization);

            $result = $mutate($onboarding);

            /* The caller may read it back off the relation afterwards. */
            $organization->unsetRelation('onboardingProgress');

            return $result;
        });
    }

    /**
     * Begin an account's onboarding against the sequence its kind of business
     * actually follows.
     *
     * The template is resolved once, here, and held on the row. Resolving it on
     * every read would mean editing a template silently rewrote the checklist of
     * every account already part-way through one.
     */
    protected function startOnboarding(Organization $organization): OnboardingProgress
    {
        $template = OnboardingTemplate::forClinicType($organization->clinic_type);
        $first = $template?->steps->first();

        return OnboardingProgress::create([
            'organization_id' => $organization->id,
            'onboarding_template_id' => $template?->id,
            'current_step' => $first?->step_key ?? 'password_setup',
            'status' => 'pending',
            'completed_steps' => [],
        ]);
    }

    /**
     * Advance customer onboarding step.
     */
    public function completeOnboardingStep(Request $request, Organization $organization)
    {
        Gate::authorize('update', $organization);

        $validated = $request->validate([
            'step' => 'required|string',
            'next_step' => 'nullable|string',
        ]);

        $this->withLockedOnboarding($organization, function (OnboardingProgress $onboarding) use ($validated) {
            $completed = $onboarding->completed_steps ?? [];
            if (! in_array($validated['step'], $completed, true)) {
                $completed[] = $validated['step'];
            }

            $onboarding->update([
                'completed_steps' => $completed,
                'current_step' => $validated['next_step'] ?? $onboarding->current_step,
                'status' => empty($validated['next_step']) ? 'completed' : 'in_progress',
            ]);
        });

        app(AccountSnapshotService::class)->refreshIfCustomer($organization);

        $organization->activities()->create([
            'user_id' => $request->user()?->id,
            'type' => ActivityType::SystemUpdate,
            'description' => "Onboarding step '{$validated['step']}' marked complete by ".($request->user()?->name ?? 'CS Rep'),
        ]);

        return back()->with('success', "Onboarding step '{$validated['step']}' completed.");
    }

    /**
     * Resend welcome password setup invite email to primary clinic admin.
     */
    public function resendOnboardingInvite(Request $request, Organization $organization)
    {
        Gate::authorize('update', $organization);

        $adminUser = $this->primaryAdminFor($organization);

        if (! $adminUser) {
            return back()->withErrors(['admin' => 'No primary clinic admin user record found for this account.']);
        }

        Mail::to($adminUser->email)->send(new PasswordSetupMailable($adminUser));

        $organization->activities()->create([
            'user_id' => $request->user()?->id,
            'type' => ActivityType::SystemUpdate,
            'description' => "Primary Admin Welcome Invite resent to {$adminUser->email}",
        ]);

        return back()->with('success', "Welcome email invitation resent to {$adminUser->email}.");
    }

    /**
     * Mark customer onboarding Go-Live.
     */
    public function markGoLive(Request $request, Organization $organization)
    {
        Gate::authorize('update', $organization);

        $this->withLockedOnboarding($organization, function (OnboardingProgress $onboarding) {
            /*
             * Every step of *this account's* template, not a hard-coded five.
             * A hospital's sequence has seven; marking it live against the
             * dental list left two of them permanently unfinished.
             */
            $steps = $onboarding->steps()->pluck('step_key')->all();

            /*
             * A template must never be the difference between go-live working
             * and silently doing nothing. If none resolved — a fresh database,
             * or a clinic type added before its template was — the account is
             * still marked live against the one key every sequence ends with.
             */
            if ($steps === []) {
                $steps = ['go_live'];
            }

            $last = end($steps);

            $onboarding->update([
                'status' => 'completed',
                'current_step' => $last,
                'completed_steps' => array_values(array_unique(array_merge(
                    $onboarding->completed_steps ?? [],
                    $steps,
                ))),
            ]);
        });

        app(AccountSnapshotService::class)->refreshIfCustomer($organization);

        $organization->activities()->create([
            'user_id' => $request->user()?->id,
            'type' => ActivityType::SystemUpdate,
            'description' => 'Customer onboarding completed. Customer is officially LIVE!',
        ]);

        return back()->with('success', "Customer {$organization->name} onboarding marked GO-LIVE!");
    }
}
