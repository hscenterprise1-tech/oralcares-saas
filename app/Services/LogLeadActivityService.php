<?php

namespace App\Services;

use App\LeadActivityType;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;

class LogLeadActivityService
{
    /**
     * Log an activity timeline event on a lead.
     */
    public function execute(
        Lead $lead,
        LeadActivityType $type,
        string $description,
        ?User $actor = null,
        ?array $properties = null
    ): LeadActivity {
        return LeadActivity::create([
            'lead_id' => $lead->id,
            'user_id' => $actor?->id,
            'type' => $type,
            'description' => $description,
            'properties' => $properties,
        ]);
    }
}
