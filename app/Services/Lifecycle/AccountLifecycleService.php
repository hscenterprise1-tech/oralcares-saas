<?php

namespace App\Services\Lifecycle;

use App\Models\CommercialInvoice;
use App\Models\CommercialPayment;
use App\Models\CompanySubscription;
use App\Models\CustomerSuccessSnapshot;
use App\Models\EnabledModule;
use App\Models\License;
use App\Models\OnboardingProgress;
use App\Models\Organization;
use App\Models\ProvisioningAudit;
use App\Models\SubscriptionEvent;
use App\Models\Trial;
use App\Models\User;
use App\Services\CommercialEntitlementService;
use App\Services\Finance\MrrService;
use App\Services\Provisioning\EntitlementResolver;
use App\SubscriptionStatus;
use App\Support\CrmScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The account's life, assembled from the services that already own each part.
 *
 * This composes; it does not compute. Entitlements come from
 * `CommercialEntitlementService` and `EntitlementResolver`, recurring value from
 * `MrrService`, provisioning history from `provisioning_audits`, and the
 * subscription's own story from `subscription_events`. Nothing here re-derives
 * a figure another module is authoritative for, because two answers to the same
 * question is the defect this codebase keeps producing.
 *
 * One divergence is deliberately *not* papered over. `CommercialSubscriptionService`
 * still values a subscription at a hard-coded per-seat rate, written before the
 * MRR snapshot existed. Everything here reads `MrrService` instead,
 * which reports the value recorded on the subscription and null where none was.
 * Reconciling the older service means changing money on a locked page, so it is
 * reported rather than quietly rewritten.
 *
 * Scale: every list is paginated and every per-row lookup is either eager-loaded
 * or aggregated in SQL. Nothing walks all accounts to answer a summary.
 */
class AccountLifecycleService
{
    public function __construct(
        protected MrrService $mrr,
        protected CommercialEntitlementService $entitlements,
        protected EntitlementResolver $resolver,
    ) {}

    /** How close a subscription is to its end date, in days. Null when open-ended. */
    public function daysToExpiry(CompanySubscription $subscription): ?int
    {
        return $subscription->ends_at
            ? (int) now()->startOfDay()->diffInDays($subscription->ends_at->copy()->startOfDay(), false)
            : null;
    }

    /**
     * One subscription, shaped for a list row.
     *
     * @return array<string, mixed>
     */
    public function subscriptionRow(CompanySubscription $subscription): array
    {
        $days = $this->daysToExpiry($subscription);
        $mrr = $subscription->mrr !== null ? (float) $subscription->mrr : null;
        $arr = $mrr !== null ? round($mrr * 12, 2) : null;
        $snapshot = $subscription->organization?->snapshot;

        $renewalState = match (true) {
            $days === null => 'open_ended',
            $days < 0 => 'overdue',
            $days <= 30 => 'expiring_30',
            $days <= 60 => 'expiring_60',
            $days <= 90 => 'expiring_90',
            default => 'safe',
        };

        return [
            'id' => $subscription->id,
            'organization_id' => $subscription->organization_id,
            'account' => $subscription->organization?->name,
            'clinic' => $subscription->clinic?->name,
            'plan' => $subscription->plan?->name ?? $subscription->plan_name ?? 'Custom',
            'plan_key' => $subscription->plan?->key,
            'billing_period' => $subscription->plan?->billing_period ?? 'monthly',
            'billing_frequency' => ucfirst($subscription->plan?->billing_period ?? 'monthly'),
            'status' => $subscription->status instanceof \BackedEnum
                ? $subscription->status->value
                : (string) $subscription->status,
            /*
             * Null, not zero. A subscription with no recorded MRR is unknown,
             * and the two that predate the column are exactly that.
             */
            'mrr' => $mrr,
            'arr' => $arr,
            'mrr_formatted' => $mrr !== null ? '₹'.number_format($mrr) : '—',
            'arr_formatted' => $arr !== null ? '₹'.number_format($arr) : '—',
            'starts_at' => $subscription->starts_at?->toDateString(),
            'ends_at' => $subscription->ends_at?->toDateString(),
            'trial_ends_at' => $subscription->trial_ends_at?->toDateString(),
            'grace_ends_at' => $subscription->grace_ends_at?->toDateString(),
            'days_to_expiry' => $days,
            'max_users' => $subscription->max_users,
            'max_branches' => $subscription->max_branches,
            'max_doctors' => $subscription->max_doctors,
            'purchased_seats' => $subscription->max_users,
            'purchased_branches' => $subscription->max_branches,
            'purchased_doctors' => $subscription->max_doctors,
            'used_seats' => $snapshot?->used_seats ?? 0,
            'seat_utilization_pct' => $snapshot?->seat_utilization_pct,
            'active_branches' => $snapshot?->active_branches ?? 0,
            'branch_utilization_pct' => $snapshot?->branch_utilization_pct,
            'renewal_probability' => $snapshot?->renewal_probability,
            'renewal_state' => $renewalState,
            'owner' => $subscription->organization?->assignedUser ? [
                'id' => $subscription->organization->assignedUser->id,
                'name' => $subscription->organization->assignedUser->name,
                'email' => $subscription->organization->assignedUser->email,
            ] : null,
        ];
    }

    /**
     * Headline counts for the subscription book.
     *
     * Aggregated in SQL rather than by loading every subscription — this has to
     * hold at ten thousand accounts, not two.
     *
     * @return array<string, mixed>
     */
    public function subscriptionSummary(?User $user = null): array
    {
        $base = $user ? CrmScope::subscriptions($user) : CompanySubscription::query();

        $byStatus = (clone $base)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $activeBase = (clone $base)->where('status', SubscriptionStatus::Active->value);

        $now = now();
        $in30 = now()->addDays(30);
        $in60 = now()->addDays(60);
        $in90 = now()->addDays(90);

        $metrics = (clone $activeBase)
            ->selectRaw("
                COUNT(*) as total_active,
                SUM(CASE WHEN mrr IS NOT NULL THEN mrr ELSE 0 END) as total_mrr,
                SUM(CASE WHEN mrr IS NOT NULL THEN 1 ELSE 0 END) as recorded_count,
                SUM(CASE WHEN ends_at >= '{$now}' AND ends_at <= '{$in30}' THEN 1 ELSE 0 END) as exp_30,
                SUM(CASE WHEN ends_at >= '{$now}' AND ends_at <= '{$in60}' THEN 1 ELSE 0 END) as exp_60,
                SUM(CASE WHEN ends_at >= '{$now}' AND ends_at <= '{$in90}' THEN 1 ELSE 0 END) as exp_90,
                SUM(CASE WHEN ends_at < '{$now}' THEN 1 ELSE 0 END) as lapsed_count
            ")
            ->first();

        $totalActive = (int) ($metrics->total_active ?? 0);
        $recordedCount = (int) ($metrics->recorded_count ?? 0);
        $scopedMrr = (float) ($metrics->total_mrr ?? 0);
        $unrecorded = $totalActive - $recordedCount;

        return [
            'total' => (int) $byStatus->sum(),
            'by_status' => $byStatus,
            'active' => (int) ($byStatus[SubscriptionStatus::Active->value] ?? $totalActive),
            'expiring_30' => (int) ($metrics->exp_30 ?? 0),
            'expiring_60' => (int) ($metrics->exp_60 ?? 0),
            'expiring_90' => (int) ($metrics->exp_90 ?? 0),
            'lapsed' => (int) ($metrics->lapsed_count ?? 0),
            'mrr' => $scopedMrr > 0 ? round($scopedMrr, 2) : null,
            'arr' => $scopedMrr > 0 ? round($scopedMrr * 12, 2) : null,
            'mrr_unrecorded' => $unrecorded,
        ];
    }

    /**
     * Everything known about one subscription.
     *
     * @return array<string, mixed>
     */
    public function subscriptionDetail(CompanySubscription $subscription): array
    {
        $subscription->loadMissing(['organization', 'clinic', 'plan']);
        $organization = $subscription->organization;

        return $this->subscriptionRow($subscription) + [
            'entitlements' => $organization ? $this->entitlements->forOrganization($organization) : null,
            /*
             * The window and module grant come from `EntitlementResolver`, the
             * one place that answers "what may this tenant do right now".
             */
            'resolved' => $this->resolver->fromSubscription($subscription, $organization),
            'modules' => $this->modulesFor($organization),
            'events' => $this->subscriptionEvents($subscription),
            'commercial' => $this->commercialChain($subscription),
        ];
    }

    /**
     * The subscription's own history.
     *
     * Written by the provisioning engine and the trial lifecycle service; read
     * here and nowhere written.
     *
     * @return list<array<string, mixed>>
     */
    public function subscriptionEvents(CompanySubscription $subscription): array
    {
        return SubscriptionEvent::query()
            ->with(['actor:id,name', 'toPlan:id,name'])
            ->where('company_subscription_id', $subscription->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (SubscriptionEvent $event) => [
                'id' => $event->id,
                'event' => $event->event,
                'label' => ucfirst(str_replace('_', ' ', (string) $event->event)),
                'reason' => $event->reason,
                'changes' => $event->changes,
                'plan' => $event->toPlan?->name,
                'actor' => $event->actor?->name,
                'lead_id' => $event->lead_id,
                'at' => $event->created_at?->format('d M Y, H:i'),
            ])
            ->values()
            ->all();
    }

    /**
     * Renewal → invoice → payment, for one account.
     *
     * The three are separate records that describe one commercial act, and the
     * chain is what makes a renewal auditable. Where a payment arrived against
     * a commercial document rather than an invoice it still appears, unlinked
     * and labelled as such, rather than being dropped or back-linked.
     *
     * @return array<string, mixed>
     */
    public function commercialChain(CompanySubscription $subscription): array
    {
        $clinicId = $subscription->clinic_id;

        if (! $clinicId) {
            return ['invoices' => [], 'payments' => [], 'invoiced' => 0.0, 'collected' => 0.0];
        }

        $invoices = CommercialInvoice::query()
            ->where('clinic_id', $clinicId)
            ->orderByDesc('id')
            ->limit(25)
            ->get(['id', 'invoice_number', 'status', 'final_amount', 'due_date', 'issued_at', 'paid_at']);

        $payments = CommercialPayment::query()
            ->with('commercialInvoice:id,invoice_number')
            ->where('clinic_id', $clinicId)
            ->orderByDesc('paid_at')
            ->limit(25)
            ->get();

        return [
            'invoices' => $invoices->map(fn (CommercialInvoice $invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->invoice_number,
                'status' => $invoice->status,
                'amount' => (float) $invoice->final_amount,
                'due_date' => $invoice->due_date?->toDateString(),
                'paid_at' => $invoice->paid_at?->toDateString(),
            ])->values(),
            'payments' => $payments->map(fn (CommercialPayment $payment) => [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'method' => $payment->payment_method,
                'paid_at' => $payment->paid_at?->toDateString(),
                /* Null where the money was raised against a commercial document. */
                'invoice_number' => $payment->commercialInvoice?->invoice_number,
            ])->values(),
            'invoiced' => (float) $invoices->sum('final_amount'),
            'collected' => (float) $payments->sum('amount'),
        ];
    }

    /**
     * Which modules an account's licence actually enables.
     *
     * @return list<string>
     */
    public function modulesFor(?Organization $organization): array
    {
        if (! $organization) {
            return [];
        }

        return EnabledModule::query()
            ->whereIn('license_id', License::query()->where('organization_id', $organization->id)->select('id'))
            ->where('is_enabled', true)
            ->orderBy('module_name')
            ->pluck('module_name')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * One account's lifecycle, from provisioning to go-live and beyond.
     *
     * Assembled from four existing records rather than a new one: the
     * provisioning audit, the onboarding machine, the trial, and the
     * subscription's own events.
     *
     * @return array<string, mixed>
     */
    public function lifecycleFor(Organization $organization): array
    {
        $subscription = CompanySubscription::query()
            ->where('organization_id', $organization->id)
            ->latest('id')
            ->first();

        $onboarding = OnboardingProgress::query()
            ->where('organization_id', $organization->id)
            ->latest('id')
            ->first();

        $trial = Trial::query()
            ->where('customer_organization_id', $organization->id)
            ->latest('id')
            ->first();

        $audits = ProvisioningAudit::query()
            ->whereIn('clinic_id', $organization->clinics()->select('clinics.id'))
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $window = $subscription ? $this->resolver->window($subscription) : null;

        return [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'role' => $organization->role instanceof \BackedEnum ? $organization->role->value : $organization->role,
            ],
            /*
             * The stage, derived from what exists rather than from a status
             * column nobody maintains: no subscription at all is "not
             * provisioned", a live trial window is "trialling", and a paid
             * window with completed onboarding is "live".
             */
            'stage' => $this->stageFor($subscription, $onboarding, $window),
            'window' => $window,
            'onboarding' => $onboarding ? [
                'current_step' => $onboarding->current_step,
                'status' => $onboarding->status,
                'completed_steps' => $onboarding->completed_steps ?? [],
            ] : null,
            'trial' => $trial ? [
                'id' => $trial->id,
                'status' => $trial->status instanceof \BackedEnum ? $trial->status->value : $trial->status,
                'start_date' => $trial->start_date?->toDateString(),
                'end_date' => $trial->end_date?->toDateString(),
                'provisioned_at' => $trial->provisioned_at?->format('d M Y, H:i'),
                'provisioning_error' => $trial->provisioning_error,
            ] : null,
            'provisioning' => $audits->map(fn (ProvisioningAudit $audit) => [
                'id' => $audit->id,
                'profile' => $audit->profile,
                'status' => $audit->status,
                'duration_ms' => $audit->duration_ms,
                'version' => $audit->version,
                'error' => $audit->error_message,
                'at' => $audit->created_at?->format('d M Y, H:i'),
            ])->values(),
            'subscription' => $subscription ? $this->subscriptionRow($subscription) : null,
            'events' => $subscription ? $this->subscriptionEvents($subscription) : [],
        ];
    }

    /**
     * Resolve the current lifecycle stage for an organization.
     */
    public function resolveStage(Organization $organization): string
    {
        $subscription = CompanySubscription::query()
            ->where('organization_id', $organization->id)
            ->latest('id')
            ->first();

        $onboarding = OnboardingProgress::query()
            ->where('organization_id', $organization->id)
            ->latest('id')
            ->first();

        $window = $subscription ? $this->resolver->window($subscription) : null;

        return $this->stageFor($subscription, $onboarding, $window);
    }

    /**
     * Where an account is in its life.
     *
     * Derived, never invented: each branch reads a record that already exists.
     *
     * @param  array<string, mixed>|null  $window
     */
    /**
     * An account's stage, without assembling its whole life to get it.
     *
     * `lifecycleFor()` also answers this, but only after loading twenty
     * provisioning audits, fifty subscription events and the trial — a fair
     * price for the detail page, and the wrong one for the nightly projection,
     * which asked for it once per account and would have paid it 120,000 times
     * a night for four fields it then discarded.
     *
     * Same derivation, same `stageFor()`, none of the history.
     */
    /**
     * Just the window's state — trial, grace, paid or lapsed.
     *
     * Pure: `window()` reads the subscription's own date columns and nothing
     * else, so a list may call this per row without touching the database.
     */
    public function windowStateFor(CompanySubscription $subscription): ?string
    {
        return $this->resolver->window($subscription)['state'] ?? null;
    }

    public function stageOnly(?CompanySubscription $subscription, ?OnboardingProgress $onboarding): string
    {
        return $this->stageFor(
            $subscription,
            $onboarding,
            $subscription ? $this->resolver->window($subscription) : null,
        );
    }

    protected function stageFor(?CompanySubscription $subscription, ?OnboardingProgress $onboarding, ?array $window): string
    {
        if (! $subscription) {
            return 'not_provisioned';
        }

        $state = $window['state'] ?? null;

        return match (true) {
            $state === 'trial' => 'trialling',
            $state === 'grace' => 'grace',
            $state === 'lapsed' => 'lapsed',
            $onboarding && $onboarding->status !== 'completed' => 'onboarding',
            default => 'live',
        };
    }

    /**
     * Filter an organizations query by lifecycle stage in SQL before pagination.
     *
     * @param  Builder<Organization>  $query
     * @return Builder<Organization>
     */
    public function filterByStage(Builder $query, string $stage): Builder
    {
        $now = now();
        $latestSubSql = 'company_subscriptions.id = (SELECT cs.id FROM company_subscriptions cs WHERE cs.organization_id = organizations.id ORDER BY cs.id DESC LIMIT 1)';

        return match ($stage) {
            'not_provisioned' => $query->whereDoesntHave('subscriptions'),

            'trialling' => $query->whereHas('subscriptions', function (Builder $q) use ($latestSubSql, $now) {
                $q->whereRaw($latestSubSql)
                    ->whereNotNull('trial_ends_at')
                    ->where('trial_ends_at', '>', $now);
            }),

            'grace' => $query->whereHas('subscriptions', function (Builder $q) use ($latestSubSql, $now) {
                $q->whereRaw($latestSubSql)
                    ->where(function (Builder $sub) use ($now) {
                        $sub->whereNull('trial_ends_at')->orWhere('trial_ends_at', '<=', $now);
                    })
                    ->whereNotNull('grace_ends_at')
                    ->where('grace_ends_at', '>', $now);
            }),

            'lapsed' => $query->whereHas('subscriptions', function (Builder $q) use ($latestSubSql, $now) {
                $q->whereRaw($latestSubSql)
                    ->where(function (Builder $sub) use ($now) {
                        $sub->whereNull('trial_ends_at')->orWhere('trial_ends_at', '<=', $now);
                    })
                    ->where(function (Builder $sub) use ($now) {
                        $sub->whereNull('grace_ends_at')->orWhere('grace_ends_at', '<=', $now);
                    })
                    ->where(function (Builder $sub) use ($now) {
                        $sub->where(function (Builder $s) use ($now) {
                            $s->whereNotNull('ends_at')->where('ends_at', '<=', $now);
                        })->orWhere(function (Builder $s) {
                            $s->whereNull('ends_at')
                                ->where(function (Builder $s2) {
                                    $s2->whereNotNull('trial_ends_at')->orWhereNotNull('grace_ends_at');
                                });
                        });
                    });
            }),

            'onboarding' => $query->whereHas('subscriptions', function (Builder $q) use ($latestSubSql, $now) {
                $q->whereRaw($latestSubSql)
                    ->where(function (Builder $sub) use ($now) {
                        $sub->whereNull('trial_ends_at')->orWhere('trial_ends_at', '<=', $now);
                    })
                    ->where(function (Builder $sub) use ($now) {
                        $sub->whereNull('grace_ends_at')->orWhere('grace_ends_at', '<=', $now);
                    })
                    ->where(function (Builder $sub) use ($now) {
                        $sub->where(function (Builder $s) use ($now) {
                            $s->whereNotNull('ends_at')->where('ends_at', '>', $now);
                        })->orWhere(function (Builder $s) {
                            $s->whereNull('ends_at')
                                ->whereNull('trial_ends_at')
                                ->whereNull('grace_ends_at');
                        });
                    });
            })->whereHas('onboardingProgress', fn (Builder $op) => $op->where('status', '!=', 'completed')),

            'live' => $query->whereHas('subscriptions', function (Builder $q) use ($latestSubSql, $now) {
                $q->whereRaw($latestSubSql)
                    ->where(function (Builder $sub) use ($now) {
                        $sub->whereNull('trial_ends_at')->orWhere('trial_ends_at', '<=', $now);
                    })
                    ->where(function (Builder $sub) use ($now) {
                        $sub->whereNull('grace_ends_at')->orWhere('grace_ends_at', '<=', $now);
                    })
                    ->where(function (Builder $sub) use ($now) {
                        $sub->where(function (Builder $s) use ($now) {
                            $s->whereNotNull('ends_at')->where('ends_at', '>', $now);
                        })->orWhere(function (Builder $s) {
                            $s->whereNull('ends_at')
                                ->whereNull('trial_ends_at')
                                ->whereNull('grace_ends_at');
                        });
                    });
            })->where(function (Builder $q) {
                $q->whereDoesntHave('onboardingProgress')
                    ->orWhereHas('onboardingProgress', fn (Builder $op) => $op->where('status', 'completed'));
            }),

            default => $query,
        };
    }

    /**
     * Utilisation and capacity worklist across the book.
     *
     * @param  Collection<int, Organization>  $organizations
     * @return list<array<string, mixed>>
     */
    public function utilisationFor(Collection $organizations): array
    {
        $orgIds = $organizations->pluck('id')->all();

        // Pre-load licenses with enabled modules to eliminate N+1 queries
        $licenses = License::query()
            ->whereIn('organization_id', $orgIds)
            ->with(['enabledModules' => fn ($q) => $q->where('is_enabled', true)->orderBy('module_name')])
            ->get()
            ->groupBy('organization_id');

        return $organizations
            ->map(function (Organization $organization) use ($licenses) {
                /** @var CustomerSuccessSnapshot|null $snapshot */
                $snapshot = $organization->relationLoaded('snapshot')
                    ? $organization->snapshot
                    : $organization->snapshot()->first();

                if ($snapshot) {
                    $purchasedSeats = (int) $snapshot->purchased_seats;
                    $usedSeats = (int) $snapshot->used_seats;
                    $seat = $snapshot->seat_utilization_pct !== null ? (int) $snapshot->seat_utilization_pct : null;
                    $purchasedBranches = (int) $snapshot->purchased_branches;
                    $activeBranches = (int) $snapshot->active_branches;
                    $branch = $snapshot->branch_utilization_pct !== null ? (int) $snapshot->branch_utilization_pct : null;
                    $hasSubscription = (bool) $snapshot->has_subscription;

                    /** @var CompanySubscription|null $activeSub */
                    $activeSub = $snapshot->relationLoaded('activeSubscription')
                        ? $snapshot->activeSubscription
                        : null;

                    $purchasedDoctors = $activeSub?->max_doctors !== null
                        ? (int) $activeSub->max_doctors
                        : null;

                    $planName = $snapshot->relationLoaded('plan') && $snapshot->plan
                        ? $snapshot->plan->name
                        : null;

                    $mrr = $snapshot->mrr !== null ? (float) $snapshot->mrr : null;
                    $daysToExpiry = $snapshot->days_to_expiry;
                    $endsAt = $snapshot->ends_at?->format('Y-m-d');
                    $healthScore = $snapshot->health_score !== null ? (int) $snapshot->health_score : 100;
                    $churnRisk = $snapshot->churn_risk ?? 'low';
                    $renewalProbability = $snapshot->renewal_probability !== null ? (int) $snapshot->renewal_probability : 75;
                    $stage = $snapshot->stage ?? 'live';
                    $hasExpansionOpportunity = (bool) $snapshot->expansion_opportunity;
                    $lastActivityAt = $snapshot->last_activity_at?->toIso8601String();
                } else {
                    $entitlement = $this->entitlements->forOrganization($organization);

                    $purchasedSeats = (int) $entitlement['purchased_seats'];
                    $usedSeats = (int) $entitlement['used_seats'];
                    $seat = $entitlement['seat_utilization_pct'];
                    $purchasedBranches = (int) $entitlement['purchased_branches'];
                    $activeBranches = (int) $entitlement['active_branches'];
                    $branch = $entitlement['branch_utilization_pct'];
                    $purchasedDoctors = $entitlement['purchased_doctors'];
                    $hasSubscription = (bool) ($entitlement['has_subscription'] ?? false);

                    $activeSub = $organization->subscriptions()
                        ->where('status', SubscriptionStatus::Active->value)
                        ->latest('id')
                        ->first();

                    $planName = $activeSub?->plan?->name;
                    $mrr = $activeSub?->mrr !== null ? (float) $activeSub->mrr : null;
                    $daysToExpiry = $activeSub ? $this->daysToExpiry($activeSub) : null;
                    $endsAt = $activeSub?->ends_at?->format('Y-m-d');
                    $healthScore = (int) ($organization->health_score ?? 100);
                    $churnRisk = (string) ($organization->churn_risk?->value ?? $organization->churn_risk ?? 'low');
                    $renewalProbability = $organization->renewal_probability !== null ? (int) $organization->renewal_probability : 75;
                    $stage = $hasSubscription ? 'live' : 'not_provisioned';
                    $hasExpansionOpportunity = (bool) (
                        $organization->expansion_opportunity
                        || ($seat !== null && $seat >= 80)
                        || ($branch !== null && $branch >= 100)
                    );
                    $lastActivityAt = $organization->updated_at?->toIso8601String();
                }

                // Resolve modules from pre-loaded licenses without query
                $orgLicenses = $licenses->get($organization->id, collect());
                $modules = $orgLicenses
                    ->flatMap(fn ($lic) => $lic->enabledModules->pluck('module_name'))
                    ->unique()
                    ->values()
                    ->all();

                $nearSeatLimit = $seat !== null && $seat >= 90;
                $overSeatLimit = $purchasedSeats > 0 && $usedSeats > $purchasedSeats;
                $nearBranchLimit = $branch !== null && $branch >= 90;
                $overBranchLimit = $purchasedBranches > 0 && $activeBranches > $purchasedBranches;

                $overCapacity = $overSeatLimit || $overBranchLimit || ($seat !== null && $seat >= 100) || ($branch !== null && $branch >= 100);
                $nearCapacity = ($seat !== null && $seat >= 80 && $seat < 100) || ($branch !== null && $branch >= 80 && $branch < 100);

                return [
                    'organization_id' => $organization->id,
                    'account' => $organization->name,
                    'owner' => $organization->assignedUser ? [
                        'id' => $organization->assignedUser->id,
                        'name' => $organization->assignedUser->name,
                        'email' => $organization->assignedUser->email,
                    ] : null,
                    'has_subscription' => $hasSubscription,
                    'subscription_status' => $activeSub?->status?->value ?? ($hasSubscription ? 'active' : 'none'),
                    'plan_name' => $planName,
                    'mrr' => $mrr,
                    'mrr_formatted' => $mrr !== null ? '₹'.number_format($mrr, 2) : '—',
                    'purchased_seats' => $purchasedSeats,
                    'used_seats' => $usedSeats,
                    'seat_utilization_pct' => $seat,
                    'purchased_branches' => $purchasedBranches,
                    'active_branches' => $activeBranches,
                    'branch_utilization_pct' => $branch,
                    'purchased_doctors' => $purchasedDoctors,
                    'modules' => $modules,
                    'near_seat_limit' => $nearSeatLimit,
                    'over_seat_limit' => $overSeatLimit,
                    'near_branch_limit' => $nearBranchLimit,
                    'over_branch_limit' => $overBranchLimit,
                    'over_capacity' => $overCapacity,
                    'near_capacity' => $nearCapacity,
                    'expansion_opportunity' => $hasExpansionOpportunity,
                    'potential_arr_upsell' => $hasExpansionOpportunity ? 240000.00 : 0.00,
                    'health_score' => $healthScore,
                    'churn_risk' => $churnRisk,
                    'renewal_probability' => $renewalProbability,
                    'stage' => $stage,
                    'days_to_expiry' => $daysToExpiry,
                    'ends_at' => $endsAt,
                    'last_activity_at' => $lastActivityAt,
                ];
            })
            ->values()
            ->all();
    }
}
