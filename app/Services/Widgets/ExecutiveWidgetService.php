<?php

namespace App\Services\Widgets;

use App\Repositories\DashboardRepository;

class ExecutiveWidgetService
{
    public function __construct(private DashboardRepository $repository) {}

    public function getHeroData(): array
    {
        return [
            'todaysRevenue' => $this->repository->getTodaysRevenue(),
            'companyHealth' => 96,
            'revenueForecast' => '8.4L',
            'pendingRenewals' => $this->repository->getPendingRenewals(),
            'quotationsPending' => $this->repository->getPendingQuotations(),
            'infrastructureAlerts' => 0,
        ];
    }

    public function getKPIs(): array
    {
        return [
            'totalRevenue' => 12450000,
            'targetRevenue' => 15000000,
            'revenueGrowth' => 24.5,
            'mrr' => 0,
            'mrrGrowth' => 8.2,
            'arr' => 0,
            'arrGrowth' => 12.4,
            'activeLeads' => $this->repository->getTotalLeads(),
            'renewalsPending' => $this->repository->getPendingRenewals(),
            'activeDeployments' => 14,
            'openSupportTickets' => 0,
        ];
    }

    public function getRevenueAnalytics(): array
    {
        return [
            'status' => 'active',
        ];
    }

    public function getSalesFunnel(): array
    {
        return [
            'newLeads' => $this->repository->getNewLeads(),
            'demoStage' => $this->repository->getDemoStageLeads(),
            'quotations' => $this->repository->getPendingQuotations(),
            'won' => $this->repository->getWonLeadsThisMonth(),
        ];
    }

    public function getOperationsOverview(): array
    {
        return [
            'recentDeployments' => [
                ['name' => 'Smile Dental Group', 'plan' => 'Enterprise', 'status' => 'Active', 'date' => 'Today, 10:42 AM', 'statusColor' => 'text-[#22C55E] bg-[#22C55E]/10'],
                ['name' => 'City Hospital', 'plan' => 'Professional', 'status' => 'Deploying', 'date' => 'Yesterday', 'statusColor' => 'text-[#F59E0B] bg-[#F59E0B]/10'],
                ['name' => 'Dr. Sharma Clinic', 'plan' => 'Starter', 'status' => 'Active', 'date' => 'Jul 28', 'statusColor' => 'text-[#22C55E] bg-[#22C55E]/10'],
                ['name' => 'Metro Healthcare', 'plan' => 'Enterprise', 'status' => 'Failed', 'date' => 'Jul 25', 'statusColor' => 'text-[#EF4444] bg-[#EF4444]/10'],
            ],
        ];
    }

    public function getActivityTimeline(): array
    {
        return [
            'activities' => $this->repository->getRecentActivities(),
        ];
    }

    public function getSystemHealth(): array
    {
        return [
            'coreApi' => 'Operational',
            'payments' => 'Operational',
            'whatsappSync' => 'Degraded',
        ];
    }
}
