<?php

namespace App\Services;

use App\Models\CompanySubscription;
use App\Models\User;
use App\Support\CrmScope;

class CommercialSubscriptionService
{
    /**
     * Get Subscriptions & Entitlements Payload.
     *
     * @deprecated Use SubscriptionController with server-side pagination instead.
     */
    public function getSubscriptionsPayload(?User $user = null, array $filters = []): array
    {
        $customerAccountsQuery = CrmScope::customerAccounts($user);

        $query = CompanySubscription::with(['plan', 'organization.assignedUser'])
            ->whereIn('organization_id', $customerAccountsQuery->select('organizations.id'))
            ->latest();

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->whereHas('organization', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%");
            });
        }

        $allSubs = (clone $query)->get();

        $metrics = [
            'total_subscriptions' => $allSubs->count(),
            'active_count' => $allSubs->where('status', 'active')->count(),
            'expiring_soon_count' => $allSubs->filter(fn ($s) => $s->ends_at && $s->ends_at->isFuture() && now()->diffInDays($s->ends_at) <= 30)->count(),
            'total_mrr' => (float) $allSubs->whereNotNull('mrr')->sum('mrr'),
            'total_arr' => (float) $allSubs->whereNotNull('mrr')->sum(fn ($s) => (float) $s->mrr * 12),
        ];

        return [
            'subscriptions' => $allSubs->map(fn (CompanySubscription $sub) => $this->transform($sub))->values(),
            'metrics' => $metrics,
            'filters' => $filters,
        ];
    }

    /**
     * Shape a subscription into the display DTO the Subscriptions/Renewals
     * workspace pages consume — shared so both pages read identically-formed
     * records regardless of which query built the underlying list.
     */
    public function transform(CompanySubscription $sub, ?array $entitlements = null): array
    {
        $mrr = $sub->mrr !== null ? (float) $sub->mrr : null;
        $arr = $mrr !== null ? round($mrr * 12, 2) : null;
        $statusVal = $sub->status instanceof \BackedEnum ? $sub->status->value : (string) $sub->status;
        $org = $sub->organization;

        $entitlements = $entitlements ?? ($org
            ? app(CommercialEntitlementService::class)->forOrganization($org)
            : [
                'purchased_seats' => (int) $sub->max_users,
                'used_seats' => 0,
                'seat_utilization_pct' => null,
                'purchased_doctors' => $sub->max_doctors !== null ? (int) $sub->max_doctors : null,
                'purchased_branches' => (int) $sub->max_branches,
                'active_branches' => 0,
                'branch_utilization_pct' => null,
            ]);

        return [
            'id' => $sub->id,
            'contractCode' => 'SUB-'.str_pad((string) $sub->id, 4, '0', STR_PAD_LEFT),
            'companyName' => $org?->name ?? 'Unknown Organization',
            'organizationId' => $sub->organization_id,
            'contactName' => $org?->contact_person ?? $org?->owner_name ?? '—',
            'contactEmail' => $org?->email,
            'contactPhone' => $org?->mobile,
            'contactRole' => 'Primary Contact',
            'planName' => $sub->plan?->name ?? 'Custom Plan',
            'productFamily' => $org?->type instanceof \BackedEnum ? ucwords(str_replace('_', ' ', $org->type->value)) : 'Clinic HMS',
            'billingFrequency' => $sub->plan?->billing_period === 'yearly' ? 'Annual Pre-Paid' : 'Monthly',
            // From the shared projection, and named for what they are: these
            // are contracted caps, not provisioned counts. The page used to
            // label `max_doctors` as "seats provisioned", which is how it came
            // to disagree with Account 360 about the same subscription.
            'doctorSeats' => $entitlements['purchased_doctors'],
            'branchCount' => $entitlements['purchased_branches'],
            'purchasedSeats' => $entitlements['purchased_seats'],
            'usedSeats' => $entitlements['used_seats'],
            'seatUtilizationPct' => $entitlements['seat_utilization_pct'],
            'activeBranches' => $entitlements['active_branches'],
            'branchUtilizationPct' => $entitlements['branch_utilization_pct'],
            'mrr' => $mrr !== null ? '₹'.number_format($mrr).' / mo' : 'Unrecorded',
            'arr' => $arr !== null ? '₹'.number_format($arr) : 'Unrecorded',
            'arrRaw' => $arr,
            'renewalDate' => $sub->ends_at ? $sub->ends_at->format('d M Y') : '—',
            /*
             * Negative means the renewal date has passed, and that is the most
             * important thing this figure can say. Clamping it at zero made an
             * expired contract indistinguishable from one renewing today, so
             * the queue for overdue renewals could never fill.
             */
            'daysToRenewal' => $sub->ends_at ? (int) now()->diffInDays($sub->ends_at, false) : 0,
            'status' => ucwords(str_replace('_', ' ', $statusVal)),
            'territory' => $org?->city ?? '—',
            'accountManager' => [
                'name' => $org?->assignedUser?->name ?? 'Unassigned',
                'avatarInitials' => $org?->assignedUser
                    ? collect(explode(' ', $org->assignedUser->name))->map(fn ($n) => $n[0] ?? '')->take(2)->join('')
                    : 'UA',
            ],
        ];
    }
}
