<?php

namespace App\Services\CRM;

use App\Models\Lead;
use App\Models\User;

class LeadManagementService
{
    public function __construct(protected LeadScoringEngine $scoringEngine) {}

    public function createLead(array $data): Lead
    {
        $lead = Lead::create($data);
        $this->updateLeadScores($lead);

        return $lead->fresh();
    }

    public function updateLead(Lead $lead, array $data): Lead
    {
        $lead->update($data);
        $this->updateLeadScores($lead);

        return $lead->fresh();
    }

    public function assignLead(Lead $lead, User $user): Lead
    {
        $lead->update(['assigned_user_id' => $user->id]);

        return $lead->fresh();
    }

    public function updateLeadScores(Lead $lead): void
    {
        $lead->updateQuietly([
            'automated_score' => $this->scoringEngine->calculateTotalScore($lead),
            'next_best_action' => $this->scoringEngine->determineNextBestAction($lead),
        ]);
    }
}
