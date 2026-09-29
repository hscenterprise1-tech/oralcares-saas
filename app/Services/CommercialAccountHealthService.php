<?php

namespace App\Services;

use App\Models\Organization;

class CommercialAccountHealthService
{
    /**
     * Calculate 5D Account Health Score (0-100) with dimension breakdowns.
     */
    public function calculateHealthScore(Organization $org): array
    {
        // 1. Relationship Activity (0-20)
        $latestActivity = $org->activities()->latest()->first();
        $daysSinceActivity = $latestActivity ? $latestActivity->created_at->diffInDays(now()) : 99;
        $relationshipScore = match (true) {
            $daysSinceActivity <= 7 => 20,
            $daysSinceActivity <= 14 => 15,
            $daysSinceActivity <= 30 => 10,
            default => 5,
        };

        // 2. Entitlement Usage (0-20)
        //
        // From the shared projection, so the score and the telemetry on the
        // same page can no longer be computed from different numbers. A null
        // utilisation (nothing purchased) scores zero rather than being
        // treated as full usage.
        $license = $org->license;
        $entitlements = app(CommercialEntitlementService::class)->forOrganization($org);
        $entitlementScore = (int) round((($entitlements['seat_utilization_pct'] ?? 0) / 100) * 20);

        // 3. Onboarding Status (0-20)
        $onboarding = $org->onboardingProgress;
        $onboardingStatus = $onboarding?->status ?? 'completed';
        $onboardingScore = match ($onboardingStatus) {
            'completed' => 20,
            'in_progress' => 10,
            default => 5,
        };

        // 4. Commercial Engagement (0-20)
        $licenseStatus = $license?->status ?? 'active';
        $enabledModulesCount = $license?->enabledModules()->count() ?? 3;
        $engagementScore = match (true) {
            $licenseStatus === 'active' && $enabledModulesCount >= 3 => 20,
            $licenseStatus === 'active' => 15,
            default => 0,
        };

        // 5. Churn Risk Inversion (0-20)
        $churnRiskVal = is_object($org->churn_risk) ? $org->churn_risk->value : (string) ($org->churn_risk ?? 'low');
        $churnRiskScore = match (strtolower($churnRiskVal)) {
            'low' => 20,
            'medium' => 10,
            'high' => 5,
            'critical' => 0,
            default => 20,
        };

        $totalScore = $relationshipScore + $entitlementScore + $onboardingScore + $engagementScore + $churnRiskScore;

        return [
            'total_score' => $totalScore,
            'dimensions' => [
                'relationship_activity' => ['score' => $relationshipScore, 'max' => 20, 'label' => 'Relationship Activity'],
                'entitlement_usage' => ['score' => $entitlementScore, 'max' => 20, 'label' => 'Entitlement Usage'],
                'onboarding_status' => ['score' => $onboardingScore, 'max' => 20, 'label' => 'Onboarding Progress'],
                'commercial_engagement' => ['score' => $engagementScore, 'max' => 20, 'label' => 'Commercial Engagement'],
                'churn_risk' => ['score' => $churnRiskScore, 'max' => 20, 'label' => 'Churn Risk Inversion'],
            ],
            'status' => $totalScore >= 80 ? 'Healthy' : ($totalScore >= 50 ? 'Moderate' : 'At Risk'),
        ];
    }
}
