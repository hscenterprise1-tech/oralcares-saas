<?php

namespace App\Repositories;

use App\LeadStatus;
use App\Models\Activity;
use App\Models\CommercialPayment;
use App\Models\CompanyQuotation;
use App\Models\CompanySubscription;
use App\Models\Lead;
use App\Models\Task;
use App\QuotationStatus;
use App\SubscriptionStatus;
use Illuminate\Support\Carbon;

class DashboardRepository
{
    public function getTodaysRevenue(): float
    {
        return (float) CommercialPayment::whereDate('paid_at', Carbon::today())->sum('amount');
    }

    public function getPendingRenewals(int $days = 30): int
    {
        $today = Carbon::today();

        return CompanySubscription::where('status', SubscriptionStatus::Active)
            ->whereBetween('ends_at', [$today, $today->copy()->addDays($days)])
            ->count();
    }

    public function getPendingQuotations(): int
    {
        return CompanyQuotation::where('status', QuotationStatus::Sent)->count();
    }

    public function getTotalLeads(): int
    {
        return Lead::count();
    }

    public function getNewLeads(): int
    {
        return Lead::where('status', LeadStatus::New)->count();
    }

    public function getDemoStageLeads(): int
    {
        return Task::where('taskable_type', Lead::class)->count();
    }

    public function getWonLeadsThisMonth(): int
    {
        return Lead::where('status', LeadStatus::Won)
            ->where('updated_at', '>=', Carbon::now()->startOfMonth())
            ->count();
    }

    public function getRecentActivities(int $limit = 10)
    {
        return Activity::with(['causer', 'subject'])
            ->latest()
            ->limit($limit)
            ->get();
    }
}
