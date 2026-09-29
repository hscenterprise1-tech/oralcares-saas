<?php

namespace App\Services\CRM;

use App\Models\Lead;

class LeadScoringService
{
    /**
     * Calculate the health score of a lead (0-100) based on data completeness and activity.
     */
    public function calculateScore(Lead $lead): int
    {
        $score = 0;

        // 1. Contact Information Completeness (Max 30)
        if (! empty($lead->email)) {
            $score += 10;
        }
        if (! empty($lead->phone)) {
            $score += 10;
        }
        if (! empty($lead->contact_name)) {
            $score += 10;
        }

        // 2. Organization Information (Max 30)
        if (! empty($lead->organization_type)) {
            $score += 5;
        }
        if (! empty($lead->organization_size)) {
            $score += 5;
        }
        if (! empty($lead->expected_users) && $lead->expected_users > 0) {
            $score += 10;
        }
        if (! empty($lead->expected_mrr) && $lead->expected_mrr > 0) {
            $score += 10;
        }

        // 3. Sales Progression & Qualification (Max 20)
        if ($lead->probability_to_close > 50) {
            $score += 10;
        } elseif ($lead->probability_to_close > 20) {
            $score += 5;
        }

        if (! empty($lead->expected_go_live_date)) {
            $score += 10;
        }

        // 4. Activity Recency (Max 20)
        $latestActivity = $lead->activities()->latest()->first();
        if ($latestActivity) {
            $daysSinceActivity = $latestActivity->created_at->diffInDays(now());
            if ($daysSinceActivity <= 7) {
                $score += 20;
            } elseif ($daysSinceActivity <= 14) {
                $score += 10;
            } elseif ($daysSinceActivity <= 30) {
                $score += 5;
            }
        }

        return min($score, 100); // Cap at 100
    }
}
