<?php

namespace App\Http\Controllers\Company\Lifecycle;

use App\Http\Controllers\Controller;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\Services\Lifecycle\AccountLifecycleService;
use App\SubscriptionStatus;
use App\Support\CrmScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The subscription book, and one account's life within it.
 *
 * Read-only. Every operational act on a subscription already lives somewhere —
 * renewals raise a deal through Customer Success, provisioning runs through the
 * engine, trials through their own lifecycle service — and adding a second way
 * to do any of them here would be the duplication this batch exists to avoid.
 *
 * Money comes from `MrrService`, entitlements from `CommercialEntitlementService`
 * and `EntitlementResolver`. Nothing here recomputes either.
 */
class SubscriptionController extends Controller
{
    public function __construct(protected AccountLifecycleService $lifecycle) {}

    public function index(Request $request): Response
    {
        Gate::authorize('view-subscriptions');

        $filters = $request->only([
            'search',
            'status',
            'plan_id',
            'window',
            'billing_frequency',
            'date_from',
            'date_to',
            'sort_by',
            'sort_order',
            'per_page',
        ]);
        $user = $request->user();

        $query = CrmScope::subscriptions($user)
            ->with([
                'organization:id,name,role,assigned_user_id',
                'organization.assignedUser:id,name,email',
                'organization.snapshot:id,organization_id,health_score,churn_risk,renewal_probability,purchased_seats,used_seats,seat_utilization_pct,purchased_branches,active_branches,branch_utilization_pct',
                'clinic:id,name',
                'plan:id,name,key,billing_period,price',
            ]);

        // Search: organization name, clinic name, plan name, or numeric ID
        $query->when($filters['search'] ?? null, function ($builder, $search) {
            $builder->where(function ($q) use ($search) {
                $q->whereHas('organization', fn ($oq) => $oq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('clinic', fn ($cq) => $cq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('plan', fn ($pq) => $pq->where('name', 'like', "%{$search}%"));

                if (is_numeric($search)) {
                    $q->orWhere('company_subscriptions.id', (int) $search);
                }
            });
        });

        // Exact status filter
        $query->when($filters['status'] ?? null, fn ($builder, $status) => $builder->where('status', $status));

        // Plan filter
        $query->when($filters['plan_id'] ?? null, fn ($builder, $id) => $builder->where('company_plan_id', $id));

        // Billing frequency filter (plan billing_period)
        $query->when($filters['billing_frequency'] ?? null, function ($builder, $period) {
            $builder->whereHas('plan', fn ($pq) => $pq->where('billing_period', $period));
        });

        // Time horizon / quick view windows
        $query->when($filters['window'] ?? null, function ($builder, $window) {
            match ($window) {
                'active' => $builder->where('status', SubscriptionStatus::Active->value),
                'expiring_30' => $builder->whereBetween('ends_at', [now(), now()->addDays(30)])->where('status', '!=', 'cancelled'),
                'expiring_60' => $builder->whereBetween('ends_at', [now(), now()->addDays(60)])->where('status', '!=', 'cancelled'),
                'expiring_90' => $builder->whereBetween('ends_at', [now(), now()->addDays(90)])->where('status', '!=', 'cancelled'),
                'expired', 'lapsed' => $builder->where(function ($q) {
                    $q->where(function ($sub) {
                        $sub->whereNotNull('ends_at')->where('ends_at', '<', now());
                    })->orWhereIn('status', ['expired', 'lapsed']);
                }),
                'cancelled' => $builder->where('status', 'cancelled'),
                default => null,
            };
        });

        // Date range filters
        $query->when($filters['date_from'] ?? null, fn ($b, $from) => $b->whereDate('ends_at', '>=', $from));
        $query->when($filters['date_to'] ?? null, fn ($b, $to) => $b->whereDate('ends_at', '<=', $to));

        // Sorting
        $sortBy = $filters['sort_by'] ?? 'ends_at';
        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        match ($sortBy) {
            'mrr' => $query->orderByRaw('mrr IS NULL')->orderBy('mrr', $sortOrder)->orderByDesc('id'),
            'account' => $query->orderBy(
                Organization::select('name')
                    ->whereColumn('organizations.id', 'company_subscriptions.organization_id')
                    ->limit(1),
                $sortOrder
            )->orderByDesc('id'),
            'plan' => $query->orderBy(
                CompanyPlan::select('name')
                    ->whereColumn('company_plans.id', 'company_subscriptions.company_plan_id')
                    ->limit(1),
                $sortOrder
            )->orderByDesc('id'),
            'status' => $query->orderBy('status', $sortOrder)->orderByDesc('id'),
            'starts_at' => $query->orderByRaw('starts_at IS NULL')->orderBy('starts_at', $sortOrder)->orderByDesc('id'),
            'created_at', 'id' => $query->orderBy('id', $sortOrder),
            default => $query->orderByRaw('ends_at IS NULL')->orderBy('ends_at', $sortOrder)->orderByDesc('id'),
        };

        // Bounded per-page (10 - 100, default 25)
        $perPage = min(max((int) $request->input('per_page', 25), 10), 100);

        return Inertia::render('Company/Lifecycle/SubscriptionsWorkspace', [
            'subscriptions' => $query->paginate($perPage)->withQueryString()
                ->through(fn (CompanySubscription $subscription) => $this->lifecycle->subscriptionRow($subscription)),
            'filters' => $filters,
            'summary' => $this->lifecycle->subscriptionSummary($user),
            'plans' => CompanyPlan::query()->orderBy('name')->get(['id', 'name', 'billing_period']),
            'statuses' => collect(SubscriptionStatus::cases())
                ->map(fn (SubscriptionStatus $status) => ['value' => $status->value, 'label' => ucfirst($status->value)])
                ->values(),
            'can' => [
                'viewRenewals' => Gate::allows('view-renewals'),
                'viewAccounts' => Gate::allows('view-organizations'),
                'createDeal' => $user !== null && Gate::forUser($user)->allows('create', Lead::class),
            ],
        ]);
    }

    public function show(Request $request, CompanySubscription $subscription): Response
    {
        Gate::authorize('view-subscriptions');

        if (! CrmScope::customerAccounts($request->user())->where('organizations.id', $subscription->organization_id)->exists()) {
            abort(404);
        }

        return Inertia::render('Company/Lifecycle/SubscriptionDetail', [
            'subscription' => $this->lifecycle->subscriptionDetail($subscription),
            'can' => [
                'viewRenewals' => Gate::allows('view-renewals'),
                'viewAccounts' => Gate::allows('view-organizations'),
            ],
        ]);
    }

    /**
     * Usage, capacity and expansion worklist across the customer book.
     */
    public function usage(Request $request): Response
    {
        Gate::authorize('view-usage');

        $user = $request->user();
        $perPage = min(100, max(10, (int) $request->input('per_page', 25)));

        $filters = [
            'search' => $request->filled('search') ? trim((string) $request->input('search')) : null,
            'type' => $request->input('type') ?? $request->input('flag') ?? 'all',
            'threshold' => $request->filled('threshold') ? (string) $request->input('threshold') : null,
            'expansion' => $request->filled('expansion') ? (string) $request->input('expansion') : null,
            'churn_risk' => $request->filled('churn_risk') ? (string) $request->input('churn_risk') : null,
            'stage' => $request->filled('stage') ? (string) $request->input('stage') : null,
            'subscription_status' => $request->filled('subscription_status') ? (string) $request->input('subscription_status') : null,
            'owner_id' => $request->filled('owner_id') ? (int) $request->input('owner_id') : null,
            'sort_by' => $request->input('sort_by', 'seat_utilization'),
            'direction' => strtolower((string) $request->input('direction', 'desc')) === 'asc' ? 'asc' : 'desc',
            'per_page' => $perPage,
        ];

        // 1. Scoped customer accounts query strictly excluding provider organizations
        $baseScoped = CrmScope::customerAccounts($user);

        // 2. Compute aggregate summary metrics directly in SQL across scoped customers
        $summaryBase = (clone $baseScoped)
            ->join('customer_success_snapshots as css', 'css.organization_id', '=', 'organizations.id');

        $summaryRaw = (clone $summaryBase)
            ->selectRaw('
                COUNT(*) as total_accounts,
                SUM(CASE WHEN (css.used_seats > css.purchased_seats AND css.purchased_seats > 0)
                          OR (css.active_branches > css.purchased_branches AND css.purchased_branches > 0)
                          OR css.seat_utilization_pct >= 100
                          OR css.branch_utilization_pct >= 100
                         THEN 1 ELSE 0 END) as over_capacity,
                SUM(CASE WHEN (css.seat_utilization_pct >= 80 AND css.seat_utilization_pct < 100)
                          OR (css.branch_utilization_pct >= 80 AND css.branch_utilization_pct < 100)
                         THEN 1 ELSE 0 END) as near_capacity,
                SUM(CASE WHEN css.expansion_opportunity = 1 THEN 1 ELSE 0 END) as expansion_opportunities,
                SUM(CASE WHEN css.seat_utilization_pct >= 90
                          OR css.branch_utilization_pct >= 90
                         THEN 1 ELSE 0 END) as near_seat_limit,
                SUM(CASE WHEN css.used_seats > css.purchased_seats AND css.purchased_seats > 0
                         THEN 1 ELSE 0 END) as over_seat_limit,
                SUM(CASE WHEN css.has_subscription = 0 OR css.has_subscription IS NULL
                         THEN 1 ELSE 0 END) as without_subscription
            ')
            ->first();

        // 3. Filtered & sorted query
        $query = (clone $baseScoped)
            ->join('customer_success_snapshots as css', 'css.organization_id', '=', 'organizations.id')
            ->select('organizations.*');

        // Search
        if ($filters['search']) {
            $search = $filters['search'];
            $cleanSearch = ltrim($search, '#');

            $query->where(function ($q) use ($search, $cleanSearch) {
                $q->where('organizations.name', 'like', "%{$search}%")
                    ->orWhere('organizations.contact_person', 'like', "%{$search}%")
                    ->orWhere('organizations.email', 'like', "%{$search}%")
                    ->orWhereHas('clinics', fn ($cq) => $cq->where('name', 'like', "%{$search}%"));

                if (is_numeric($cleanSearch)) {
                    $q->orWhere('organizations.id', (int) $cleanSearch);
                }
            });
        }

        // Quick View / Type tabs
        match ($filters['type']) {
            'over_capacity' => $query->where(function ($q) {
                $q->whereRaw('css.used_seats > css.purchased_seats AND css.purchased_seats > 0')
                    ->orWhereRaw('css.active_branches > css.purchased_branches AND css.purchased_branches > 0')
                    ->orWhere('css.seat_utilization_pct', '>=', 100)
                    ->orWhere('css.branch_utilization_pct', '>=', 100);
            }),
            'near_capacity' => $query->where(function ($q) {
                $q->whereBetween('css.seat_utilization_pct', [80, 99])
                    ->orWhereBetween('css.branch_utilization_pct', [80, 99]);
            }),
            'near_limit' => $query->where(function ($q) {
                $q->where('css.seat_utilization_pct', '>=', 90)
                    ->orWhere('css.branch_utilization_pct', '>=', 90)
                    ->orWhereRaw('css.used_seats > css.purchased_seats AND css.purchased_seats > 0');
            }),
            'seats' => $query->where('css.purchased_seats', '>', 0),
            'branches' => $query->where('css.purchased_branches', '>', 0),
            'doctors' => $query->where(function (Builder $q) {
                $q->whereHas('subscriptions', fn ($sub) => $sub->whereNotNull('max_doctors')->where('max_doctors', '>', 0));
            }),
            'expansion', 'expansion_opportunities' => $query->where(function ($q) {
                $q->where('css.expansion_opportunity', true)
                    ->orWhere(function ($sq) {
                        $sq->whereNull('css.id')->where('organizations.expansion_opportunity', true);
                    });
            }),
            default => null,
        };

        // Threshold filter
        if ($filters['threshold']) {
            match ((string) $filters['threshold']) {
                '80' => $query->where(function ($q) {
                    $q->where('css.seat_utilization_pct', '>=', 80)
                        ->orWhere('css.branch_utilization_pct', '>=', 80);
                }),
                '90' => $query->where(function ($q) {
                    $q->where('css.seat_utilization_pct', '>=', 90)
                        ->orWhere('css.branch_utilization_pct', '>=', 90);
                }),
                '100' => $query->where(function ($q) {
                    $q->where('css.seat_utilization_pct', '>=', 100)
                        ->orWhere('css.branch_utilization_pct', '>=', 100)
                        ->orWhereRaw('css.used_seats > css.purchased_seats AND css.purchased_seats > 0');
                }),
                default => null,
            };
        }

        // Expansion Opportunity filter
        if ($filters['expansion']) {
            if (in_array($filters['expansion'], ['yes', 'true', '1'], true)) {
                $query->where(function ($q) {
                    $q->where('css.expansion_opportunity', true)
                        ->orWhere(function ($sq) {
                            $sq->whereNull('css.id')->where('organizations.expansion_opportunity', true);
                        });
                });
            } elseif (in_array($filters['expansion'], ['no', 'false', '0'], true)) {
                $query->where(function ($q) {
                    $q->where('css.expansion_opportunity', false)
                        ->orWhere(function ($sq) {
                            $sq->whereNull('css.id')->where('organizations.expansion_opportunity', false);
                        });
                });
            }
        }

        // Churn Risk filter
        if ($filters['churn_risk']) {
            $risk = strtolower($filters['churn_risk']);
            $query->whereRaw('LOWER(COALESCE(css.churn_risk, organizations.churn_risk, "low")) = ?', [$risk]);
        }

        // Stage / Lifecycle filter
        if ($filters['stage']) {
            $query->where('css.stage', $filters['stage']);
        }

        // Subscription Status filter
        if ($filters['subscription_status']) {
            match ($filters['subscription_status']) {
                'active' => $query->where('css.has_subscription', true),
                'trialing' => $query->where('css.stage', 'trialing'),
                'none', 'no_subscription' => $query->where(function ($q) {
                    $q->where('css.has_subscription', false)
                        ->orWhereNull('css.has_subscription');
                }),
                default => null,
            };
        }

        // Owner filter
        if ($filters['owner_id']) {
            $query->where('organizations.assigned_user_id', $filters['owner_id']);
        }

        // Sorting
        match ($filters['sort_by']) {
            'seat_utilization' => $query->orderByRaw("COALESCE(css.seat_utilization_pct, 0) {$filters['direction']}")->orderBy('organizations.name'),
            'branch_utilization' => $query->orderByRaw("COALESCE(css.branch_utilization_pct, 0) {$filters['direction']}")->orderBy('organizations.name'),
            'health_score' => $query->orderByRaw("COALESCE(css.health_score, 100) {$filters['direction']}")->orderBy('organizations.name'),
            'account', 'name' => $query->orderBy('organizations.name', $filters['direction']),
            'renewal', 'days_to_expiry' => $query->orderByRaw("COALESCE(css.days_to_expiry, 9999) {$filters['direction']}")->orderBy('organizations.name'),
            'last_activity' => $query->orderByRaw("COALESCE(css.last_activity_at, organizations.updated_at) {$filters['direction']}")->orderBy('organizations.name'),
            default => $query->orderByRaw("COALESCE(css.seat_utilization_pct, 0) {$filters['direction']}")->orderBy('organizations.name'),
        };

        // 4. Paginate safely with user per_page
        $accounts = $query->paginate($perPage)->withQueryString();

        // 5. Eager-load relationships to eliminate N+1 queries
        $accounts->loadMissing([
            'assignedUser:id,name,email',
            'snapshot.plan:id,name,key',
            'snapshot.activeSubscription:id,status,mrr,starts_at,ends_at,max_users,max_branches,max_doctors',
            'license:id,organization_id,max_users,max_branches',
        ]);

        $rows = $this->lifecycle->utilisationFor($accounts->getCollection());

        // 6. Owner list for filter dropdown
        $owners = User::query()
            ->whereIn('id', (clone $baseScoped)->whereNotNull('assigned_user_id')->select('assigned_user_id'))
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        return Inertia::render('Company/Lifecycle/UsageWorkspace', [
            'rows' => $rows,
            'paginator' => [
                'links' => $accounts->linkCollection(),
                'from' => $accounts->firstItem(),
                'to' => $accounts->lastItem(),
                'total' => $accounts->total(),
                'current_page' => $accounts->currentPage(),
                'last_page' => $accounts->lastPage(),
                'per_page' => $accounts->perPage(),
            ],
            'filters' => $filters,
            'summary' => [
                'accounts_on_page' => count($rows),
                'total_accounts' => (int) ($summaryRaw?->total_accounts ?? 0),
                'over_capacity' => (int) ($summaryRaw?->over_capacity ?? 0),
                'near_capacity' => (int) ($summaryRaw?->near_capacity ?? 0),
                'expansion_opportunities' => (int) ($summaryRaw?->expansion_opportunities ?? 0),
                'near_seat_limit' => (int) ($summaryRaw?->near_seat_limit ?? 0),
                'over_seat_limit' => (int) ($summaryRaw?->over_seat_limit ?? 0),
                'without_subscription' => (int) ($summaryRaw?->without_subscription ?? 0),
            ],
            'owners' => $owners,
        ]);
    }

    /** One account's lifecycle, from provisioning to now. */
    public function lifecycle(Request $request, Organization $organization): Response
    {
        Gate::authorize('view-provisioning');

        if (! CrmScope::customerAccounts($request->user())->where('organizations.id', $organization->id)->exists()) {
            abort(404);
        }

        return Inertia::render('Company/Lifecycle/AccountLifecycle', [
            'lifecycle' => $this->lifecycle->lifecycleFor($organization),
            'can' => [
                'viewAccounts' => Gate::allows('view-organizations'),
                'viewSubscriptions' => Gate::allows('view-subscriptions'),
            ],
        ]);
    }
}
