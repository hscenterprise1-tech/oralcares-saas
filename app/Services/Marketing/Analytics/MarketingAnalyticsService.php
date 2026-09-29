<?php

namespace App\Services\Marketing\Analytics;

use App\LeadStatus;
use App\Models\Lead;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingContact;
use Illuminate\Support\Facades\Cache;

class MarketingAnalyticsService
{
    public function __construct(
        protected MarketingFunnelService $funnelService,
        protected CampaignAnalyticsService $campaignAnalyticsService,
        protected MarketingAttributionService $attributionService,
        protected MarketingJourneyService $journeyService
    ) {}

    /**
     * Get complete Executive Analytics Overview Dashboard Payload.
     */
    public function getExecutiveOverview(int $orgId, MarketingAnalyticsFilter $filter): array
    {
        $cacheKey = "mkt_analytics_overview:org_{$orgId}:".md5(serialize($filter->toArray()));

        return Cache::remember($cacheKey, 30, function () use ($orgId, $filter) {
            // 1. Executive Top KPI Cards with Period-over-Period % comparison
            $kpis = $this->calculateExecutiveKpis($orgId, $filter);

            // 2. Marketing-to-Won Funnel
            $funnel = $this->funnelService->calculateFunnel($orgId, $filter);

            // 3. Campaign Performance Table
            $campaignPerformance = $this->campaignAnalyticsService->getCampaignPerformanceTable($orgId, $filter);

            // 4. Lead Source Attribution
            $leadAttribution = $this->attributionService->getLeadSourceAttribution($orgId, $filter);

            // 5. Safety & Suppression Metrics
            $safetyMetrics = $this->calculateSafetyMetrics($orgId);

            return [
                'kpis' => $kpis,
                'funnel' => $funnel,
                'campaign_performance' => $campaignPerformance,
                'lead_attribution' => $leadAttribution,
                'safety_metrics' => $safetyMetrics,
                'filter' => $filter->toArray(),
            ];
        });
    }

    /**
     * Calculate Top Executive KPI metrics with period-over-period comparison.
     *
     * Scoped by the same campaign/audience/WhatsApp-account selection the
     * funnel and campaign-performance table already honor, so all three
     * sections of the page agree for a given filter — previously this only
     * applied organization + date, silently ignoring the rest of the
     * filter contract. Recipient filtering mirrors
     * `MarketingFunnelService::calculateFunnel()`'s `$recipientQuery`
     * exactly; lead filtering mirrors that same funnel's `$leadQuery` —
     * `audienceId`/`whatsappAccountId` have no direct column on `leads`,
     * so both scope through `marketing_campaign_id` via an identical
     * subquery, rather than one service filtering leads by audience and
     * the other silently not.
     */
    protected function calculateExecutiveKpis(int $orgId, MarketingAnalyticsFilter $filter): array
    {
        $recipientQuery = MarketingCampaignRecipient::whereHas('campaign', function ($q) use ($orgId, $filter) {
            $q->where('organization_id', $orgId);
            if ($filter->campaignId) {
                $q->where('id', $filter->campaignId);
            }
            if ($filter->audienceId) {
                $q->where('audience_id', $filter->audienceId);
            }
            if ($filter->whatsappAccountId) {
                $q->where('whatsapp_account_id', $filter->whatsappAccountId);
            }
        });

        $messagesDelivered = (clone $recipientQuery)
            ->when($filter->startDate && $filter->endDate, fn ($q) => $q->whereBetween('created_at', [$filter->startDate, $filter->endDate]))
            ->whereIn('status', ['delivered', 'read', 'replied'])
            ->count();

        $repliesCount = (clone $recipientQuery)
            ->when($filter->startDate && $filter->endDate, fn ($q) => $q->whereBetween('created_at', [$filter->startDate, $filter->endDate]))
            ->where('status', 'replied')
            ->count();

        $leadsQuery = Lead::where('organization_id', $orgId)
            ->whereNotNull('marketing_contact_id')
            ->when($filter->campaignId, fn ($q) => $q->where('marketing_campaign_id', $filter->campaignId))
            ->when($filter->audienceId || $filter->whatsappAccountId, function ($q) use ($filter) {
                $q->whereIn('marketing_campaign_id', function ($sub) use ($filter) {
                    $sub->select('id')->from('marketing_campaigns');
                    if ($filter->audienceId) {
                        $sub->where('audience_id', $filter->audienceId);
                    }
                    if ($filter->whatsappAccountId) {
                        $sub->where('whatsapp_account_id', $filter->whatsappAccountId);
                    }
                });
            });

        $crmLeads = (clone $leadsQuery)
            ->when($filter->startDate && $filter->endDate, fn ($q) => $q->whereBetween('created_at', [$filter->startDate, $filter->endDate]))
            ->count();
        $wonLeads = (clone $leadsQuery)
            ->when($filter->startDate && $filter->endDate, fn ($q) => $q->whereBetween('created_at', [$filter->startDate, $filter->endDate]))
            ->where('status', LeadStatus::Won)
            ->count();

        // Previous Period Counts (for period-over-period % comparison) — same scope, prior window.
        $prevDelivered = (clone $recipientQuery)
            ->when($filter->prevStartDate && $filter->prevEndDate, fn ($q) => $q->whereBetween('created_at', [$filter->prevStartDate, $filter->prevEndDate]))
            ->whereIn('status', ['delivered', 'read', 'replied'])
            ->count();

        $prevReplies = (clone $recipientQuery)
            ->when($filter->prevStartDate && $filter->prevEndDate, fn ($q) => $q->whereBetween('created_at', [$filter->prevStartDate, $filter->prevEndDate]))
            ->where('status', 'replied')
            ->count();

        $prevLeads = (clone $leadsQuery)
            ->when($filter->prevStartDate && $filter->prevEndDate, fn ($q) => $q->whereBetween('created_at', [$filter->prevStartDate, $filter->prevEndDate]))
            ->count();

        $prevWon = (clone $leadsQuery)
            ->when($filter->prevStartDate && $filter->prevEndDate, fn ($q) => $q->whereBetween('created_at', [$filter->prevStartDate, $filter->prevEndDate]))
            ->where('status', LeadStatus::Won)
            ->count();

        return [
            'messages_delivered' => [
                'value' => $messagesDelivered,
                'prev_value' => $prevDelivered,
                'pct_change' => $this->pctChange($messagesDelivered, $prevDelivered),
            ],
            'replies' => [
                'value' => $repliesCount,
                'prev_value' => $prevReplies,
                'pct_change' => $this->pctChange($repliesCount, $prevReplies),
            ],
            'crm_leads' => [
                'value' => $crmLeads,
                'prev_value' => $prevLeads,
                'pct_change' => $this->pctChange($crmLeads, $prevLeads),
            ],
            'won_leads' => [
                'value' => $wonLeads,
                'prev_value' => $prevWon,
                'pct_change' => $this->pctChange($wonLeads, $prevWon),
            ],
        ];
    }

    protected function pctChange(int $current, int $previous): float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    protected function calculateSafetyMetrics(int $orgId): array
    {
        $base = MarketingContact::where('organization_id', $orgId);

        return [
            'active' => (clone $base)->where('marketing_status', 'active')->count(),
            'opted_out' => (clone $base)->where('marketing_status', 'opted_out')->count(),
            'suppressed' => (clone $base)->where('marketing_status', 'suppressed')->count(),
            'total' => (clone $base)->count(),
        ];
    }
}
