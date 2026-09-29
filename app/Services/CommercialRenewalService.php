<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CompanySubscription;
use App\Models\CustomerSuccessSnapshot;
use App\Models\Organization;
use App\Models\User;
use App\SubscriptionStatus;
use App\Support\CrmScope;
use App\UserType;
use Carbon\CarbonInterface as CarbonContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class CommercialRenewalService
{
    /** How far back a contract must have ended to count toward retention. */
    private const RETENTION_LOOKBACK_MONTHS = 12;

    public function __construct(protected CommercialSubscriptionService $subscriptionService) {}

    /**
     * Get Renewals Radar Payload with server-side filtering, searching, sorting and pagination.
     */
    public function getRenewalsPayload(?User $user = null, array $filters = []): array
    {
        $now = now();
        $customerAccountsQuery = CrmScope::customerAccounts($user);

        // Customers only — see Organization::scopeCustomerAccounts().
        $baseQuery = CompanySubscription::query()
            ->whereIn('organization_id', $customerAccountsQuery->select('organizations.id'))
            ->whereNotNull('ends_at');

        // Headline summary metrics aggregated in SQL using consistent $now
        $dueThisMonth = (clone $baseQuery)
            ->where('ends_at', '>=', $now)
            ->where('ends_at', '<=', $now->copy()->addDays(30))
            ->count();

        // At risk: snapshot first, fallback to organization churn_risk
        $atRiskRenewals = (clone $baseQuery)
            ->whereHas('organization', function (Builder $oq) {
                $oq->where(function (Builder $q) {
                    $q->whereHas('snapshot', fn (Builder $sq) => $sq->whereIn('churn_risk', ['High', 'high', 'critical', 'Critical']))
                        ->orWhere(function (Builder $noSnap) {
                            $noSnap->whereDoesntHave('snapshot')
                                ->whereIn('churn_risk', ['High', 'high', 'critical', 'Critical']);
                        });
                });
            })
            ->count();

        // Canonical MRR: strictly sum recorded subscription mrr in single aggregated query. Never fabricate.
        $mrrStats = (clone $baseQuery)
            ->selectRaw('SUM(company_subscriptions.mrr) as total, COUNT(company_subscriptions.mrr) as recorded, (COUNT(*) - COUNT(company_subscriptions.mrr)) as unrecorded')
            ->first();

        $expectedRenewalMrr = (float) ($mrrStats?->total ?? 0.0);
        $recordedSubscriptionsCount = (int) ($mrrStats?->recorded ?? 0);
        $unrecordedSubscriptionsCount = (int) ($mrrStats?->unrecorded ?? 0);

        $retention = $this->retention($user, $now);

        $metrics = [
            'due_this_month' => $dueThisMonth,
            'at_risk_renewals' => $atRiskRenewals,
            'expected_renewal_mrr' => $expectedRenewalMrr,
            'recorded_subscriptions_count' => $recordedSubscriptionsCount,
            'unrecorded_subscriptions_count' => $unrecordedSubscriptionsCount,
            'retention_rate' => $retention['rate'],
            'retention_sample_size' => $retention['sample_size'],
            'retention_available' => $retention['rate'] !== null,
            'retention_unavailable_reason' => $retention['reason'],
        ];

        // List query with eager loading for current page (including CustomerSuccessSnapshot)
        $query = (clone $baseQuery)
            ->with([
                'plan:id,name,key,billing_period,price',
                'organization.assignedUser:id,name,email',
                'organization.snapshot:id,organization_id,health_score,churn_risk,renewal_probability,purchased_seats,used_seats,seat_utilization_pct,purchased_branches,active_branches,branch_utilization_pct,expansion_opportunity',
                'organization.license:id,organization_id,max_users,max_branches',
            ]);

        // Search filter (company name, contact person, owner, subscription code/id)
        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $q) use ($search) {
                $q->whereHas('organization', function (Builder $oq) use ($search) {
                    $oq->where('name', 'like', "%{$search}%")
                        ->orWhere('contact_person', 'like', "%{$search}%")
                        ->orWhere('owner_name', 'like', "%{$search}%");
                });
                if (preg_match('/^(?:sub-)?(\d+)$/i', $search, $matches)) {
                    $q->orWhere('company_subscriptions.id', (int) $matches[1]);
                }
            });
        }

        // Account ID filter (for deep-linking from Accounts / Account 360)
        if (! empty($filters['account_id'])) {
            $query->where('company_subscriptions.organization_id', (int) $filters['account_id']);
        }

        // Owner filter
        if (! empty($filters['owner_id']) && $filters['owner_id'] !== 'all') {
            $ownerId = (int) $filters['owner_id'];
            $query->whereHas('organization', fn (Builder $oq) => $oq->where('assigned_user_id', $ownerId));
        }

        // Date window / horizon filter using consistent $now
        $horizon = $filters['horizon'] ?? ($filters['window'] ?? null);
        if ($horizon && $horizon !== 'all') {
            match ((string) $horizon) {
                'overdue' => $query->where('ends_at', '<', $now),
                '0_30', '0-30', '30' => $query->where('ends_at', '>=', $now)->where('ends_at', '<=', $now->copy()->addDays(30)),
                '31_60', '31-60' => $query->where('ends_at', '>', $now->copy()->addDays(30))->where('ends_at', '<=', $now->copy()->addDays(60)),
                '60' => $query->where('ends_at', '>=', $now)->where('ends_at', '<=', $now->copy()->addDays(60)),
                '61_90', '61-90' => $query->where('ends_at', '>', $now->copy()->addDays(60))->where('ends_at', '<=', $now->copy()->addDays(90)),
                '90' => $query->where('ends_at', '>=', $now)->where('ends_at', '<=', $now->copy()->addDays(90)),
                default => null,
            };
        }

        // Status filter (at_risk, expansion, ready, or raw subscription status) using snapshot first
        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $status = (string) $filters['status'];
            match ($status) {
                'at_risk' => $query->whereHas('organization', function (Builder $oq) {
                    $oq->where(function (Builder $q) {
                        $q->whereHas('snapshot', fn (Builder $sq) => $sq->whereIn('churn_risk', ['High', 'high', 'critical', 'Critical']))
                            ->orWhere(function (Builder $noSnap) {
                                $noSnap->whereDoesntHave('snapshot')
                                    ->whereIn('churn_risk', ['High', 'high', 'critical', 'Critical']);
                            });
                    });
                }),
                'expansion' => $query->whereHas('organization', function (Builder $oq) {
                    $oq->where(function (Builder $q) {
                        $q->whereHas('snapshot', fn (Builder $sq) => $sq->where('expansion_opportunity', true)->whereNotIn('churn_risk', ['High', 'high', 'critical', 'Critical']))
                            ->orWhere(function (Builder $noSnap) {
                                $noSnap->whereDoesntHave('snapshot')
                                    ->where('expansion_opportunity', true)
                                    ->whereNotIn('churn_risk', ['High', 'high', 'critical', 'Critical']);
                            });
                    });
                }),
                'ready' => $query->whereHas('organization', function (Builder $oq) {
                    $oq->where(function (Builder $q) {
                        $q->whereHas('snapshot', fn (Builder $sq) => $sq->where(function (Builder $rsq) {
                            $rsq->whereNull('churn_risk')->orWhereNotIn('churn_risk', ['High', 'high', 'critical', 'Critical']);
                        })->where('expansion_opportunity', false))
                            ->orWhere(function (Builder $noSnap) {
                                $noSnap->whereDoesntHave('snapshot')
                                    ->where(function (Builder $roq) {
                                        $roq->whereNull('churn_risk')->orWhereNotIn('churn_risk', ['High', 'high', 'critical', 'Critical']);
                                    })->where(function (Builder $roq) {
                                        $roq->whereNull('expansion_opportunity')->orWhere('expansion_opportunity', false);
                                    });
                            });
                    });
                }),
                default => $query->where('status', $status),
            };
        }

        // Server-side sorting with explicit NULL handling
        $sort = $filters['sort'] ?? 'ends_at';
        $direction = strtolower($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        match ($sort) {
            'company_name', 'account' => $query->orderBy(
                Organization::select('name')
                    ->whereColumn('organizations.id', 'company_subscriptions.organization_id')
                    ->limit(1),
                $direction
            )->orderBy('company_subscriptions.id', $direction),

            'arr', 'mrr' => $query->orderByRaw('company_subscriptions.mrr IS NULL')
                ->orderBy('company_subscriptions.mrr', $direction)
                ->orderBy('company_subscriptions.ends_at', $direction)
                ->orderBy('company_subscriptions.id', $direction),

            'renewal_probability', 'probability' => $query->orderBy(
                CustomerSuccessSnapshot::select('renewal_probability')
                    ->whereColumn('customer_success_snapshots.organization_id', 'company_subscriptions.organization_id')
                    ->limit(1),
                $direction
            )->orderBy(
                Organization::select('renewal_probability')
                    ->whereColumn('organizations.id', 'company_subscriptions.organization_id')
                    ->limit(1),
                $direction
            )->orderBy('company_subscriptions.id', $direction),

            'risk', 'churn_risk' => $query->orderBy(
                CustomerSuccessSnapshot::select('churn_risk')
                    ->whereColumn('customer_success_snapshots.organization_id', 'company_subscriptions.organization_id')
                    ->limit(1),
                $direction
            )->orderBy(
                Organization::select('churn_risk')
                    ->whereColumn('organizations.id', 'company_subscriptions.organization_id')
                    ->limit(1),
                $direction
            )->orderBy('company_subscriptions.id', $direction),

            default => $query->orderByRaw('company_subscriptions.ends_at IS NULL')
                ->orderBy('company_subscriptions.ends_at', $direction)
                ->orderBy('company_subscriptions.id', $direction),
        };

        // Server-side pagination
        $perPage = (int) ($filters['per_page'] ?? 25);
        $paginator = $query->paginate($perPage)->withQueryString();

        // Check if any subscriptions on page lack snapshots; only query counts if needed
        $orgsNeedingCounts = $paginator->getCollection()
            ->filter(fn (CompanySubscription $sub) => $sub->organization && ! $sub->organization->snapshot)
            ->pluck('organization_id')
            ->unique()
            ->values();

        $userCounts = collect();
        $branchCounts = collect();

        if ($orgsNeedingCounts->isNotEmpty()) {
            $userCounts = User::query()
                ->whereIn('organization_id', $orgsNeedingCounts)
                ->where('user_type', UserType::ClinicUser->value)
                ->selectRaw('organization_id, COUNT(*) as aggregate')
                ->groupBy('organization_id')
                ->pluck('aggregate', 'organization_id');

            $branchCounts = Branch::withoutOrganizationScope()
                ->whereIn('organization_id', $orgsNeedingCounts)
                ->selectRaw('organization_id, COUNT(*) as aggregate')
                ->groupBy('organization_id')
                ->pluck('aggregate', 'organization_id');
        }

        $renewals = $paginator->getCollection()->map(function (CompanySubscription $sub) use ($userCounts, $branchCounts, $now) {
            $org = $sub->organization;
            $snapshot = $org?->snapshot;

            $precomputedEntitlements = null;
            if ($snapshot) {
                $precomputedEntitlements = [
                    'purchased_seats' => $snapshot->purchased_seats,
                    'used_seats' => $snapshot->used_seats,
                    'seat_utilization_pct' => $snapshot->seat_utilization_pct,
                    'purchased_doctors' => $sub->max_doctors !== null ? (int) $sub->max_doctors : null,
                    'purchased_branches' => $snapshot->purchased_branches,
                    'active_branches' => $snapshot->active_branches,
                    'branch_utilization_pct' => $snapshot->branch_utilization_pct,
                    'has_subscription' => true,
                ];
            } elseif ($org) {
                $purchasedSeats = (int) ($sub->max_users ?? $org->license?->max_users ?? 0);
                $purchasedBranches = (int) ($sub->max_branches ?? $org->license?->max_branches ?? 0);
                $purchasedDoctors = $sub->max_doctors !== null ? (int) $sub->max_doctors : null;
                $usedSeats = (int) ($userCounts[$org->id] ?? 0);
                $activeBranches = (int) ($branchCounts[$org->id] ?? 0);

                $precomputedEntitlements = [
                    'purchased_seats' => $purchasedSeats,
                    'used_seats' => $usedSeats,
                    'seat_utilization_pct' => $purchasedSeats > 0 ? (int) min(100, round(($usedSeats / $purchasedSeats) * 100)) : null,
                    'purchased_doctors' => $purchasedDoctors,
                    'purchased_branches' => $purchasedBranches,
                    'active_branches' => $activeBranches,
                    'branch_utilization_pct' => $purchasedBranches > 0 ? (int) min(100, round(($activeBranches / $purchasedBranches) * 100)) : null,
                    'has_subscription' => true,
                ];
            }

            return $this->transform($sub, $precomputedEntitlements, $now);
        });

        return [
            'renewals' => $renewals->values(),
            'paginator' => [
                'links' => $paginator->linkCollection(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
            ],
            'metrics' => $metrics,
            'filters' => $filters,
        ];
    }

    /**
     * Retention, measured in bounded SQL rather than per-row loops.
     *
     * @return array{rate: ?float, sample_size: int, reason: ?string}
     */
    protected function retention(?User $user, ?CarbonContract $now = null): array
    {
        $now = $now ?? now();
        $window = $now->copy()->subMonths(self::RETENTION_LOOKBACK_MONTHS);
        $customerAccountsQuery = CrmScope::customerAccounts($user);

        $eligibleQuery = CompanySubscription::query()
            ->whereIn('organization_id', $customerAccountsQuery->select('organizations.id'))
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', $now)
            ->where('ends_at', '>=', $window);

        $eligibleCount = (clone $eligibleQuery)->count();

        if ($eligibleCount === 0) {
            $hasAnyHistory = CompanySubscription::query()
                ->whereIn('organization_id', $customerAccountsQuery->select('organizations.id'))
                ->exists();

            return [
                'rate' => null,
                'sample_size' => 0,
                'reason' => $hasAnyHistory
                    ? 'No contracts have reached their renewal date yet.'
                    : 'No subscription history yet.',
            ];
        }

        // Bounded SQL query: counts eligible un-canceled subscriptions where another active or later subscription exists for the same account
        $retainedCount = (clone $eligibleQuery)
            ->whereNull('canceled_at')
            ->whereExists(function (QueryBuilder $subQuery) {
                $subQuery->selectRaw('1')
                    ->from('company_subscriptions as other')
                    ->whereColumn('other.organization_id', 'company_subscriptions.organization_id')
                    ->whereColumn('other.id', '!=', 'company_subscriptions.id')
                    ->whereNull('other.canceled_at')
                    ->where(function (QueryBuilder $q) {
                        $q->whereColumn('other.ends_at', '>', 'company_subscriptions.ends_at')
                            ->orWhere('other.status', SubscriptionStatus::Active->value);
                    });
            })
            ->count();

        return [
            'rate' => round(($retainedCount / $eligibleCount) * 100, 1),
            'sample_size' => $eligibleCount,
            'reason' => null,
        ];
    }

    public function transform(CompanySubscription $sub, ?array $entitlements = null, ?CarbonContract $now = null): array
    {
        $now = $now ?? now();
        $base = $this->subscriptionService->transform($sub, $entitlements);
        $org = $sub->organization;
        $snapshot = $org?->snapshot;

        // Canonical MRR/ARR: Only recorded subscription mrr. Never fabricate.
        $mrr = $sub->mrr !== null ? (float) $sub->mrr : null;
        $arr = $mrr !== null ? round($mrr * 12, 2) : null;
        $mrrFormatted = $mrr !== null ? '₹'.number_format($mrr).' / mo' : '—';
        $arrFormatted = $arr !== null ? '₹'.number_format($arr) : '—';

        // Canonical health & risk from CustomerSuccessSnapshot first
        $renewalProbability = $snapshot?->renewal_probability;
        $healthScore = $snapshot?->health_score;
        $churnRisk = $snapshot?->churn_risk instanceof \BackedEnum
            ? $snapshot->churn_risk->value
            : ($snapshot?->churn_risk ? (string) $snapshot->churn_risk : null);
        $expansionOpportunity = $snapshot ? (bool) $snapshot->expansion_opportunity : null;

        // Fallback to Organization columns only where snapshot is absent
        if (! $snapshot && $org) {
            $renewalProbability = $org->renewal_probability !== null ? (int) round((float) $org->renewal_probability) : null;
            $healthScore = $org->health_score;
            $churnRisk = $org->churn_risk instanceof \BackedEnum ? $org->churn_risk->value : ($org->churn_risk ? (string) $org->churn_risk : null);
            $expansionOpportunity = (bool) $org->expansion_opportunity;
        }

        $churnRiskNormalized = strtolower((string) ($churnRisk ?? ''));
        $retentionStatus = match (true) {
            in_array($churnRiskNormalized, ['high', 'critical'], true) => 'At Risk',
            (bool) $expansionOpportunity => 'Expansion Opportunity',
            default => 'Ready to Renew',
        };

        $daysToRenewal = $sub->ends_at ? (int) $now->diffInDays($sub->ends_at, false) : 0;

        return array_merge($base, [
            'mrr' => $mrrFormatted,
            'mrrRaw' => $mrr,
            'arr' => $arrFormatted,
            'arrRaw' => $arr,
            'currentArr' => $arrFormatted,
            'currentArrRaw' => $arr,
            'isMrrRecorded' => $mrr !== null,
            'daysToRenewal' => $daysToRenewal,
            'healthScore' => $healthScore,
            'churnRisk' => $churnRisk ? ucfirst($churnRiskNormalized) : null,
            'expansionTarget' => $expansionOpportunity ? 'Expansion opportunity flagged' : 'No expansion identified',
            'expansionTargetRaw' => 0,
            'renewalProbability' => $renewalProbability,
            'retentionStatus' => $retentionStatus,
        ]);
    }
}
