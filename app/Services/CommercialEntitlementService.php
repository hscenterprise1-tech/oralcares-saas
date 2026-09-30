<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CompanySubscription;
use App\Models\Organization;
use App\Models\User;
use App\UserType;

/**
 * The one projection of what an account bought and what it is using.
 *
 * Two pages disagreed about the same customer. Subscriptions read the caps off
 * `CompanySubscription` and labelled them "seats provisioned"; Account 360 read
 * them off `License` and counted **contacts** as active users, with a `?: 1`
 * floor that invented a user for an empty account. The same subscription
 * therefore rendered as "3 seats across 2 branches" on one page and
 * "1 / 10 seats, 1 / 2 branches" on the other.
 *
 * DEFINITIONS
 *
 *   purchased_seats     contracted user cap. The active subscription's
 *                       `max_users`, falling back to the licence.
 *   used_seats          real users belonging to the organization. Clinic users
 *                       only — contacts are people at the customer, not seats.
 *   purchased_doctors   contracted clinician cap (`max_doctors`).
 *   purchased_branches  contracted branch cap.
 *   active_branches     branches actually provisioned under the account.
 *
 * Utilisation is used ÷ purchased, capped at 100, and is null when nothing was
 * purchased — an unknown ratio is not zero.
 *
 * Presentation may differ per page; these numbers may not.
 */
class CommercialEntitlementService
{
    /**
     * @return array{
     *     purchased_seats:int, used_seats:int, seat_utilization_pct:?int,
     *     purchased_doctors:?int,
     *     purchased_branches:int, active_branches:int, branch_utilization_pct:?int,
     *     has_subscription:bool
     * }
     */
    public function forOrganization(Organization $organization): array
    {
        $subscription = $this->activeSubscription($organization);
        $license = $organization->license;

        $purchasedSeats = (int) ($subscription?->max_users ?? $license?->max_users ?? 0);
        $purchasedBranches = (int) ($subscription?->max_branches ?? $license?->max_branches ?? 0);
        $purchasedDoctors = $subscription?->max_doctors !== null ? (int) $subscription->max_doctors : null;

        $usedSeats = $this->countSeats($organization);
        $activeBranches = $this->countBranches($organization);

        return [
            'purchased_seats' => $purchasedSeats,
            'used_seats' => $usedSeats,
            'seat_utilization_pct' => $this->utilization($usedSeats, $purchasedSeats),
            'purchased_doctors' => $purchasedDoctors,
            'purchased_branches' => $purchasedBranches,
            'active_branches' => $activeBranches,
            'branch_utilization_pct' => $this->utilization($activeBranches, $purchasedBranches),
            'has_subscription' => $subscription !== null,
        ];
    }

    /**
     * Seats in use: users who belong to this customer organization.
     *
     * Contacts are decision-makers on the account, not licensed users, and
     * counting them was why Account 360 reported usage for an account with no
     * users at all.
     */
    protected function countSeats(Organization $organization): int
    {
        return User::query()
            ->where('organization_id', $organization->id)
            ->where('user_type', UserType::ClinicUser)
            ->count();
    }

    /**
     * Branches provisioned under the account.
     *
     * The global organization scope is removed explicitly: this runs from the
     * company portal, where the acting user's own organization would otherwise
     * filter the customer's branches out entirely.
     */
    protected function countBranches(Organization $organization): int
    {
        return Branch::withoutOrganizationScope()
            ->where('organization_id', $organization->id)
            ->count();
    }

    protected function activeSubscription(Organization $organization): ?CompanySubscription
    {
        return CompanySubscription::where('organization_id', $organization->id)
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->latest('id')
            ->first();
    }

    /**
     * Null rather than zero when nothing was purchased — "unknown" and "none
     * used" are different answers, and CrmMetricsService already sets that
     * precedent for unavailable metrics.
     */
    protected function utilization(int $used, int $purchased): ?int
    {
        if ($purchased <= 0) {
            return null;
        }

        return (int) min(100, round(($used / $purchased) * 100));
    }
}
