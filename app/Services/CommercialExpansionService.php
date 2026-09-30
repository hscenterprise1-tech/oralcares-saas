<?php

namespace App\Services;

use App\Models\Organization;

class CommercialExpansionService
{
    /**
     * Calculate Account Expansion Capacity & Opportunities (+₹ ARR Potential).
     */
    public function calculateExpansionRadar(Organization $org): array
    {
        // Entitlements come from the one shared projection. This used to read
        // caps off the licence and count *contacts* as active users, with a
        // `?: 1` floor that invented usage for an empty account — which is why
        // this page and Subscriptions reported different numbers for the same
        // customer.
        $entitlements = app(CommercialEntitlementService::class)->forOrganization($org);

        $userUtilPct = $entitlements['seat_utilization_pct'];
        $branchUtilPct = $entitlements['branch_utilization_pct'];

        $hasExpansionPotential = ($userUtilPct !== null && $userUtilPct >= 80)
            || ($branchUtilPct !== null && $branchUtilPct >= 100)
            || (bool) $org->expansion_opportunity;

        return [
            'user_utilization_pct' => $userUtilPct,
            'branch_utilization_pct' => $branchUtilPct,
            'active_users' => $entitlements['used_seats'],
            'max_users' => $entitlements['purchased_seats'],
            'active_branches' => $entitlements['active_branches'],
            'max_branches' => $entitlements['purchased_branches'],
            'has_expansion_opportunity' => $hasExpansionPotential,
            'potential_arr_upsell' => $hasExpansionPotential ? 240000.00 : 0.00,
        ];
    }
}
