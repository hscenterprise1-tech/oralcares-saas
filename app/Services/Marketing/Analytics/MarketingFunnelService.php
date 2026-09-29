<?php

namespace App\Services\Marketing\Analytics;

use App\LeadStatus;
use App\Models\Lead;
use App\Models\MarketingCampaignRecipient;

class MarketingFunnelService
{
    /**
     * Calculate multi-stage marketing-to-won funnel with stage conversion & drop-off rates.
     */
    public function calculateFunnel(int $orgId, MarketingAnalyticsFilter $filter): array
    {
        // 1. Base Query for Recipients
        $recipientQuery = MarketingCampaignRecipient::query()
            ->whereHas('campaign', function ($q) use ($orgId, $filter) {
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
            })
            ->when($filter->startDate && $filter->endDate, function ($q) use ($filter) {
                $q->whereBetween('created_at', [$filter->startDate, $filter->endDate]);
            });

        $totalReached = (clone $recipientQuery)->count();
        $totalDelivered = (clone $recipientQuery)->whereIn('status', ['delivered', 'read', 'replied'])->count();
        $totalRead = (clone $recipientQuery)->whereIn('status', ['read', 'replied'])->count();
        $totalReplied = (clone $recipientQuery)->where('status', 'replied')->count();

        // 2. Base Query for CRM Leads generated from Marketing/WhatsApp
        //
        // Leads have no audience_id/whatsapp_account_id column of their own —
        // scoped through the same marketing_campaign_id FK campaign
        // performance already uses to select campaigns, via a subquery
        // rather than an invented relationship. Identical shape in
        // MarketingAnalyticsService::calculateExecutiveKpis() so the funnel
        // and the KPI band never disagree about what a given filter means.
        $leadQuery = Lead::query()
            ->where('organization_id', $orgId)
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
            })
            ->when($filter->startDate && $filter->endDate, function ($q) use ($filter) {
                $q->whereBetween('created_at', [$filter->startDate, $filter->endDate]);
            });

        $totalLeads = (clone $leadQuery)->count();
        $totalWon = (clone $leadQuery)->where('status', LeadStatus::Won)->count();

        $stages = [
            [
                'key' => 'reached',
                'label' => 'Contacts Reached',
                'count' => $totalReached,
                'conversion_rate' => 100.0,
                'overall_rate' => 100.0,
                'drop_off_rate' => 0.0,
            ],
            [
                'key' => 'delivered',
                'label' => 'Messages Delivered',
                'count' => $totalDelivered,
                'conversion_rate' => $this->rate($totalDelivered, $totalReached),
                'overall_rate' => $this->rate($totalDelivered, $totalReached),
                'drop_off_rate' => 100.0 - $this->rate($totalDelivered, $totalReached),
            ],
            [
                'key' => 'read',
                'label' => 'Messages Read',
                'count' => $totalRead,
                'conversion_rate' => $this->rate($totalRead, $totalDelivered),
                'overall_rate' => $this->rate($totalRead, $totalReached),
                'drop_off_rate' => 100.0 - $this->rate($totalRead, $totalDelivered),
            ],
            [
                'key' => 'replied',
                'label' => 'Customer Replies',
                'count' => $totalReplied,
                'conversion_rate' => $this->rate($totalReplied, $totalRead),
                'overall_rate' => $this->rate($totalReplied, $totalReached),
                'drop_off_rate' => 100.0 - $this->rate($totalReplied, $totalRead),
            ],
            [
                'key' => 'leads',
                'label' => 'CRM Leads',
                'count' => $totalLeads,
                'conversion_rate' => $this->rate($totalLeads, $totalReplied),
                'overall_rate' => $this->rate($totalLeads, $totalReached),
                'drop_off_rate' => 100.0 - $this->rate($totalLeads, $totalReplied),
            ],
            [
                'key' => 'won',
                'label' => 'Won Customers',
                'count' => $totalWon,
                'conversion_rate' => $this->rate($totalWon, $totalLeads),
                'overall_rate' => $this->rate($totalWon, $totalReached),
                'drop_off_rate' => 100.0 - $this->rate($totalWon, $totalLeads),
            ],
        ];

        return [
            'stages' => $stages,
            'summary' => [
                'total_reached' => $totalReached,
                'total_delivered' => $totalDelivered,
                'total_read' => $totalRead,
                'total_replied' => $totalReplied,
                'total_leads' => $totalLeads,
                'total_won' => $totalWon,
                'delivery_rate' => $this->rate($totalDelivered, $totalReached),
                'read_rate' => $this->rate($totalRead, $totalDelivered),
                'reply_rate' => $this->rate($totalReplied, $totalRead),
                'lead_conversion_rate' => $this->rate($totalLeads, $totalReplied),
                'win_rate' => $this->rate($totalWon, $totalLeads),
            ],
        ];
    }

    protected function rate(int $num, int $den): float
    {
        if ($den <= 0) {
            return 0.0;
        }

        return round(($num / $den) * 100, 2);
    }
}
