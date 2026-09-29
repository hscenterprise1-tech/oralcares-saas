<?php

declare(strict_types=1);

namespace App\Services\CustomerSuccess;

use App\CustomerChurnRisk;
use App\Models\Branch;
use App\Models\CompanySubscription;
use App\Models\CustomerSuccessSnapshot;
use App\Models\OnboardingProgress;
use App\Models\Organization;
use App\Services\CommercialAccountHealthService;
use App\Services\CommercialEntitlementService;
use App\Services\CommercialExpansionService;
use App\Services\Lifecycle\AccountLifecycleService;
use App\SubscriptionStatus;

/**
 * Materialized Customer Success Snapshot Service.
 *
 * Provides a single, scale-safe projection layer for Customer Success accounts.
 * Rather than computing health, expansion radar, entitlements, and lifecycle
 * stages on-the-fly across 120,000+ accounts during HTTP requests, this service
 * projects canonical business state into `customer_success_snapshots`.
 *
 * Rules:
 *   - Reuses canonical services (CommercialAccountHealthService, CommercialExpansionService,
 *     CommercialEntitlementService, AccountLifecycleService); does not re-derive business logic.
 *   - Strictly excludes provider/vendor organizations.
 *   - Preserves unrecorded subscription MRR as null rather than fabricating zero.
 *   - Supports safe, chunked, idempotent population.
 */
class AccountSnapshotService
{
    public function __construct(
        protected CommercialAccountHealthService $healthService,
        protected CommercialExpansionService $expansionService,
        protected CommercialEntitlementService $entitlementService,
        protected AccountLifecycleService $lifecycleService,
        protected ChurnRiskService $churnRisk,
    ) {}

    /**
     * Compute snapshot attributes for a customer organization from canonical services.
     *
     * @return array<string, mixed>
     */
    public function computeForOrganization(Organization $organization): array
    {
        /*
         * Churn is settled before health is measured, and the order matters.
         *
         * `CommercialAccountHealthService` scores five dimensions and the fifth
         * is a churn-risk inversion — so health is a function of churn. Deriving
         * churn afterwards left the stored score describing the *previous*
         * risk: the snapshot said 56 while recomputing the same account a
         * moment later said 61, and neither was wrong on its own.
         */
        $entitlements = $this->entitlementService->forOrganization($organization);

        // Find active subscription, falling back to latest subscription
        $activeSub = CompanySubscription::query()
            ->where('organization_id', $organization->id)
            ->where('status', SubscriptionStatus::Active->value)
            ->latest('id')
            ->first()
            ?? CompanySubscription::query()
                ->where('organization_id', $organization->id)
                ->latest('id')
                ->first();

        $daysToExpiry = $activeSub ? $this->lifecycleService->daysToExpiry($activeSub) : null;

        $onboarding = OnboardingProgress::query()
            ->where('organization_id', $organization->id)
            ->latest('id')
            ->first();

        /*
         * Stage only. `lifecycleFor()` would answer the same question after
         * loading the account's audits, events and trial — history this
         * projection never stores, paid for once per account per night.
         */
        $stage = $this->lifecycleService->stageOnly($activeSub, $onboarding);

        /* One read, used by both the churn signal and the stored column. */
        $lastActivityAt = $organization->activities()->latest()->first()?->created_at;

        /*
         * Churn risk, derived — unless a person has pinned it.
         *
         * Nothing used to write this column but a rep typing into a form, so at
         * any real number of accounts it kept whatever value it was created
         * with. `ChurnRiskService` reads it off signals the account already
         * carries; an override set by hand wins and is left alone until it is
         * cleared, because a rep who has actually spoken to the customer knows
         * something this job cannot.
         */
        $stored = $organization->churn_risk instanceof CustomerChurnRisk
            ? $organization->churn_risk
            : ($organization->churn_risk ? CustomerChurnRisk::tryFrom((string) $organization->churn_risk) : null);

        if ($this->churnRisk->isOverridden($organization)) {
            $churnRisk = $stored;
            $churnReasons = ['Set by hand'];
        } else {
            $derived = $this->churnRisk->derive([
                'window_state' => $activeSub ? $this->lifecycleService->windowStateFor($activeSub) : null,
                'seat_utilization_pct' => $entitlements['seat_utilization_pct'] ?? null,
                'has_subscription' => (bool) ($entitlements['has_subscription'] ?? false),
                'onboarding_status' => $onboarding?->status,
                'last_activity_at' => $lastActivityAt,
            ]);

            $churnRisk = $derived['risk'];
            $churnReasons = $derived['reasons'];

            /*
             * Written back to the account as well as the snapshot. Everything
             * outside Customer Success — the CRM deal payload, the audit trail —
             * reads `organizations.churn_risk`, and a projection the rest of the
             * product cannot see is not an answer.
             */
            if ($stored !== $churnRisk) {
                $organization->forceFill(['churn_risk' => $churnRisk])->saveQuietly();
            }
        }

        /*
         * Measured against the risk just settled, not the one the row arrived
         * with.
         */
        $healthData = $this->healthService->calculateHealthScore($organization);
        $expansionData = $this->expansionService->calculateExpansionRadar($organization);

        $renewalProbability = $organization->renewal_probability !== null
            ? (int) round((float) $organization->renewal_probability)
            : ($activeSub ? 75 : null);

        /*
         * Recorded MRR from subscription source.
         * Preserve null when MRR is genuinely unrecorded (do not silently default to 0.00).
         */
        $mrr = $activeSub && $activeSub->mrr !== null ? (float) $activeSub->mrr : null;

        return [
            'organization_id' => $organization->id,
            /*
             * The computed score, unless somebody has pinned one by hand.
             *
             * `CommercialAccountHealthService` has always produced a real
             * five-dimension score; the column beside it was a second, manual
             * number, and the two disagreed on every account where anyone had
             * typed into the form. The computed one is authoritative now, and
             * an override is an explicit, stamped decision rather than an
             * accident of which page was opened last.
             */
            'health_score' => $organization->health_score_overridden_at !== null
                ? (int) $organization->health_score
                : (int) $healthData['total_score'],
            'health_dimensions' => $healthData['dimensions'],
            'churn_risk' => $churnRisk?->value ?? CustomerChurnRisk::Low->value,
            /* Why, so the page can say more than a colour. */
            'churn_reasons' => $churnReasons,
            'renewal_probability' => $renewalProbability,
            'purchased_seats' => (int) ($entitlements['purchased_seats'] ?? 0),
            'used_seats' => (int) ($entitlements['used_seats'] ?? 0),
            'seat_utilization_pct' => $entitlements['seat_utilization_pct'],
            'purchased_branches' => (int) ($entitlements['purchased_branches'] ?? 0),
            'active_branches' => (int) ($entitlements['active_branches'] ?? 0),
            'branch_utilization_pct' => $entitlements['branch_utilization_pct'],
            'has_subscription' => (bool) ($entitlements['has_subscription'] ?? ($activeSub !== null)),
            'active_subscription_id' => $activeSub?->id,
            'plan_id' => $activeSub?->company_plan_id,
            'mrr' => $mrr,
            'ends_at' => $activeSub?->ends_at,
            'days_to_expiry' => $daysToExpiry,
            'onboarding_status' => $onboarding?->status ?? 'completed',
            'onboarding_current_step' => $onboarding?->current_step ? (string) $onboarding->current_step : null,
            'stage' => $stage,
            'expansion_opportunity' => (bool) ($expansionData['has_expansion_opportunity'] ?? $organization->expansion_opportunity),
            'last_activity_at' => $lastActivityAt,
            'computed_at' => now(),
        ];
    }

    /**
     * Compute and persist snapshot for a single customer organization.
     * Strictly excludes provider/vendor organizations.
     */
    public function refresh(Organization $organization): ?CustomerSuccessSnapshot
    {
        if ($organization->isProvider()) {
            return null;
        }

        $payload = $this->computeForOrganization($organization);

        return CustomerSuccessSnapshot::updateOrCreate(
            ['organization_id' => $organization->id],
            $payload
        );
    }

    /**
     * Refresh snapshot by organization ID.
     */
    public function refreshById(int $organizationId): ?CustomerSuccessSnapshot
    {
        $organization = Organization::find($organizationId);
        if (! $organization) {
            return null;
        }

        return $this->refresh($organization);
    }

    /**
     * Safe entry-point for write-paths: refresh if organization exists and is a customer.
     */
    public function refreshIfCustomer(?Organization $organization): ?CustomerSuccessSnapshot
    {
        if (! $organization || $organization->isProvider()) {
            return null;
        }

        return $this->refresh($organization);
    }

    /**
     * Safe entry-point for subscription mutation write-paths.
     */
    public function refreshForSubscription(?CompanySubscription $subscription): ?CustomerSuccessSnapshot
    {
        if (! $subscription) {
            return null;
        }

        return $this->refreshIfCustomer($subscription->organization);
    }

    /**
     * Safe entry-point for branch mutation write-paths.
     */
    public function refreshForBranch(?Branch $branch): ?CustomerSuccessSnapshot
    {
        if (! $branch) {
            return null;
        }

        return $this->refreshIfCustomer($branch->organization);
    }

    /**
     * Safe entry-point for onboarding mutation write-paths.
     */
    public function refreshForOnboarding(?OnboardingProgress $onboarding): ?CustomerSuccessSnapshot
    {
        if (! $onboarding) {
            return null;
        }

        return $this->refreshIfCustomer($onboarding->organization);
    }

    /**
     * Batch backfill / refresh snapshots in memory-bounded chunks.
     * Uses chunkById to guarantee O(chunkSize) memory scale for 120,000+ accounts.
     */
    public function refreshAllChunks(int $chunkSize = 100, ?callable $onProgress = null): int
    {
        $totalProcessed = 0;

        $query = Organization::query()
            ->customerAccounts()
            ->orderBy('id');

        $query->chunkById($chunkSize, function ($organizations) use (&$totalProcessed, $onProgress) {
            foreach ($organizations as $organization) {
                $this->refresh($organization);
                $totalProcessed++;
            }

            if ($onProgress) {
                $onProgress($totalProcessed);
            }
        });

        return $totalProcessed;
    }
}
