<?php

namespace App\Services\Marketing\Analytics;

use App\LeadStatus;
use App\Models\Lead;

class MarketingAttributionService
{
    /**
     * Get Lead Source Performance breakdown aligned with canonical Lead::sources().
     *
     * Same audience/WhatsApp-account scoping through marketing_campaign_id
     * as MarketingFunnelService and MarketingAnalyticsService — without it,
     * selecting an audience or account filter would change the KPI band and
     * funnel but leave this section showing the unfiltered organization-wide
     * numbers, which is exactly the "filter that only changes one section"
     * this page must not have.
     */
    public function getLeadSourceAttribution(int $orgId, MarketingAnalyticsFilter $filter): array
    {
        $canonicalSources = Lead::sources();
        $sourcesData = [];

        foreach ($canonicalSources as $key => $label) {
            $query = Lead::query()
                ->where('organization_id', $orgId)
                ->where('source', $key)
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

            $totalLeads = (clone $query)->count();
            $wonLeads = (clone $query)->where('status', LeadStatus::Won)->count();
            $lostLeads = (clone $query)->where('status', LeadStatus::Lost)->count();
            $winRate = $totalLeads > 0 ? round(($wonLeads / $totalLeads) * 100, 1) : 0;

            if ($totalLeads > 0 || $key === 'whatsapp') {
                $sourcesData[] = [
                    'source_key' => $key,
                    'source_label' => $label,
                    'leads' => $totalLeads,
                    'won' => $wonLeads,
                    'lost' => $lostLeads,
                    'win_rate' => $winRate,
                ];
            }
        }

        return $sourcesData;
    }
}
