<?php

namespace App\Services;

use App\Models\CommercialInvoice;
use App\Models\CompanySubscription;
use App\SubscriptionStatus;
use Illuminate\Support\Facades\DB;

class RenewalService
{
    public function __construct(
        protected SubscriptionService $subscriptionService,
        protected CommercialInvoiceService $invoiceService
    ) {}

    /**
     * Scan and process all renewals, expiring those whose ends_at has passed.
     */
    public function checkAndProcessRenewals(): array
    {
        return DB::transaction(function () {
            $expiredCount = 0;
            $invoicedCount = 0;

            // 1. Expire subscriptions whose ends_at is in the past and status is Active
            $expiredSubscriptions = CompanySubscription::where('status', SubscriptionStatus::Active)
                ->where('ends_at', '<', now())
                ->get();

            foreach ($expiredSubscriptions as $sub) {
                $this->subscriptionService->transitionStatus($sub, SubscriptionStatus::Expired);
                $expiredCount++;
            }

            // 2. Automatically invoice subscriptions expiring within 30 days that haven't been invoiced yet
            $upcomingRenewals = CompanySubscription::where('status', SubscriptionStatus::Active)
                ->where('ends_at', '>=', now())
                ->where('ends_at', '<=', now()->addDays(30))
                ->get();

            foreach ($upcomingRenewals as $sub) {
                // Check if a draft/issued renewal invoice already exists for this ends_at date
                $exists = CommercialInvoice::where('company_subscription_id', $sub->id)
                    ->whereIn('status', ['draft', 'issued'])
                    ->exists();

                if (! $exists) {
                    $this->invoiceService->generateInvoice($sub);
                    $invoicedCount++;
                }
            }

            return [
                'expired_count' => $expiredCount,
                'invoiced_count' => $invoicedCount,
            ];
        });
    }
}
