<?php

namespace App\Services\CRM;

use App\Models\Lead;

class LeadScoringEngine
{
    /**
     * Calculate and return demographic/profile score for lead.
     */
    public function calculateDemographicScore(Lead $lead): int
    {
        $score = 0;
        if (! empty($lead->email)) {
            $score += 15;
        }
        if (! empty($lead->phone)) {
            $score += 15;
        }
        if (! empty($lead->company_name)) {
            $score += 20;
        }

        return $score;
    }

    /**
     * Calculate and return engagement score for lead.
     */
    public function calculateEngagementScore(Lead $lead): int
    {
        return min(100, $lead->activities()->count() * 10);
    }

    /**
     * Calculate total score / health score.
     */
    public function calculateTotalScore(Lead $lead): int
    {
        return min(100, $this->calculateDemographicScore($lead) + $this->calculateEngagementScore($lead));
    }

    public function calculateHealthScore(Lead $lead): int
    {
        return $this->calculateTotalScore($lead);
    }

    public function determineNextBestAction(Lead $lead): string
    {
        if (empty($lead->assigned_user_id)) {
            return 'Assign lead to sales representative';
        }
        if ($lead->activities()->count() === 0) {
            return 'Schedule discovery call';
        }

        return 'Follow up on open opportunity';
    }
}
