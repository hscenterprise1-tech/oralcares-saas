<?php

namespace App\Services\Marketing\Analytics;

use App\LeadStatus;
use App\Models\Lead;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use Illuminate\Support\Facades\DB;

class CampaignAnalyticsService
{
    /**
     * Get campaign performance directory table data.
     *
     * Batched into 3 fixed queries regardless of campaign count — previously
     * this looped per matched campaign, calling `calculateMetrics()` (1
     * groupBy query) plus 2 lead-count queries per row: 66 queries for 21
     * campaigns, measured. `calculateMetrics()` itself is untouched and
     * still the per-campaign source of truth for Campaign Results; the
     * cumulative-status math below is intentionally the same definitions,
     * just computed from one grouped result set instead of once per row.
     *
     * Also scopes recipient/lead activity to the selected Analytics period
     * (previously this filtered campaigns by their own `created_at` but then
     * showed each one's all-time totals — disagreeing with the KPI band and
     * funnel, which both filter by recipient/lead activity in-period). A
     * campaign with no activity in the selected period is simply not shown,
     * consistent with the page's existing "No campaign has run in this
     * period" empty state.
     */
    public function getCampaignPerformanceTable(int $orgId, MarketingAnalyticsFilter $filter): array
    {
        $campaigns = MarketingCampaign::query()
            ->where('organization_id', $orgId)
            ->when($filter->campaignId, fn ($q) => $q->where('id', $filter->campaignId))
            ->when($filter->audienceId, fn ($q) => $q->where('audience_id', $filter->audienceId))
            ->when($filter->whatsappAccountId, fn ($q) => $q->where('whatsapp_account_id', $filter->whatsappAccountId))
            ->with(['audience', 'whatsappAccount'])
            ->latest()
            ->get();

        if ($campaigns->isEmpty()) {
            return [];
        }

        $campaignIds = $campaigns->pluck('id')->all();

        $recipientCounts = MarketingCampaignRecipient::query()
            ->whereIn('campaign_id', $campaignIds)
            ->when($filter->startDate && $filter->endDate, fn ($q) => $q->whereBetween('created_at', [$filter->startDate, $filter->endDate]))
            ->selectRaw('campaign_id, status, COUNT(*) as total')
            ->groupBy('campaign_id', 'status')
            ->get()
            ->groupBy('campaign_id');

        // Plain query builder, not the Eloquent model — Lead::status is cast
        // to the LeadStatus enum, which pluck() can't use as an array key
        // ("Illegal offset type"). A raw grouped count has no use for that
        // cast, or for anything else the model adds.
        $leadCounts = DB::table('leads')
            ->where('organization_id', $orgId)
            ->whereIn('marketing_campaign_id', $campaignIds)
            ->when($filter->startDate && $filter->endDate, fn ($q) => $q->whereBetween('created_at', [$filter->startDate, $filter->endDate]))
            ->selectRaw('marketing_campaign_id, status, COUNT(*) as total')
            ->groupBy('marketing_campaign_id', 'status')
            ->get()
            ->groupBy('marketing_campaign_id');

        $rows = [];
        foreach ($campaigns as $camp) {
            $statusCounts = ($recipientCounts->get($camp->id) ?? collect())->pluck('total', 'status');
            $sum = fn (string ...$statuses) => array_sum(array_map(fn ($s) => (int) ($statusCounts[$s] ?? 0), $statuses));

            $total = (int) $statusCounts->sum();
            $delivered = $sum('delivered', 'read', 'replied');
            $read = $sum('read', 'replied');
            $replied = $sum('replied');
            $sent = $sum('sent', 'delivered', 'read', 'replied');
            $failed = $sum('failed');

            $leadStatusCounts = ($leadCounts->get($camp->id) ?? collect())->pluck('total', 'status');
            $leadsCount = (int) $leadStatusCounts->sum();
            $wonCount = (int) ($leadStatusCounts[LeadStatus::Won->value] ?? 0);

            if ($total === 0 && $leadsCount === 0) {
                continue;
            }

            $rows[] = [
                'id' => $camp->id,
                'name' => $camp->name,
                'status' => $camp->status,
                'audience_name' => $camp->audience?->name ?? 'Direct List',
                'recipients' => $total,
                'sent' => $sent,
                'delivered' => $delivered,
                'read' => $read,
                'replies' => $replied,
                'failed' => $failed,
                'leads' => $leadsCount,
                'won' => $wonCount,
                'delivery_rate' => $total > 0 ? round(($delivered / $total) * 100, 1) : 0,
                'read_rate' => $delivered > 0 ? round(($read / $delivered) * 100, 1) : 0,
                'reply_rate' => $read > 0 ? round(($replied / $read) * 100, 1) : 0,
                'win_rate' => $leadsCount > 0 ? round(($wonCount / $leadsCount) * 100, 1) : 0,
                'created_at' => $camp->created_at?->toIso8601String(),
            ];
        }

        return $rows;
    }

    /**
     * Get detailed analytics for a single campaign.
     */
    public function getCampaignDetailAnalytics(MarketingCampaign $campaign): array
    {
        $metrics = $campaign->calculateMetrics();
        [$total, $queued, $sent, $delivered, $read, $replies, $failed, $skipped] = [
            $metrics['total'], $metrics['queued'], $metrics['sent'], $metrics['delivered'],
            $metrics['read'], $metrics['replied'], $metrics['failed'], $metrics['skipped'],
        ];

        $leadsQuery = Lead::where('organization_id', $campaign->organization_id)
            ->where('marketing_campaign_id', $campaign->id);
        $leadsCount = (clone $leadsQuery)->count();
        $wonCount = (clone $leadsQuery)->where('status', LeadStatus::Won)->count();

        // Time-series breakdown by date
        $timeSeries = MarketingCampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status IN ('delivered','read','replied') THEN 1 ELSE 0 END) as delivered"),
                DB::raw("SUM(CASE WHEN status IN ('read','replied') THEN 1 ELSE 0 END) as read_count"),
                DB::raw("SUM(CASE WHEN status = 'replied' THEN 1 ELSE 0 END) as replies")
            )
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get();

        return [
            'summary' => [
                'total' => $total,
                'queued' => $queued,
                'sent' => $sent,
                'delivered' => $delivered,
                'read' => $read,
                'replies' => $replies,
                'failed' => $failed,
                'skipped' => $skipped,
                'leads' => $leadsCount,
                'won' => $wonCount,
            ],
            'rates' => [
                'delivery_rate' => $total > 0 ? round(($delivered / $total) * 100, 2) : 0,
                'read_rate' => $delivered > 0 ? round(($read / $delivered) * 100, 2) : 0,
                'reply_rate' => $read > 0 ? round(($replies / $read) * 100, 2) : 0,
                'lead_conversion' => $replies > 0 ? round(($leadsCount / $replies) * 100, 2) : 0,
                'win_rate' => $leadsCount > 0 ? round(($wonCount / $leadsCount) * 100, 2) : 0,
            ],
            'time_series' => $timeSeries,
        ];
    }
}
