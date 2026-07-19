<?php

namespace App\Services;

use App\Models\CompanyEmployee;
use App\Models\CompanyOpportunity;
use App\Models\CompanyTrial;
use App\Models\CompanyTrialExtension;
use App\OpportunityStage;
use App\TrialStatus;
use Illuminate\Support\Facades\DB;

class TrialService
{
    public function __construct(
        protected SalesOpportunityService $opportunityService,
        protected SalesActivityService $activityService
    ) {}

    /**
     * Start/Create a new trial.
     */
    public function createTrial(CompanyOpportunity $opportunity, array $trialData, CompanyEmployee $owner): CompanyTrial
    {
        return DB::transaction(function () use ($opportunity, $trialData, $owner) {
            $trial = $opportunity->trials()->create(array_merge($trialData, [
                'status' => TrialStatus::Active,
                'trial_owner_id' => $owner->id,
            ]));

            $this->opportunityService->transitionStage(
                $opportunity,
                OpportunityStage::TrialActive,
                $owner,
                'Trial started. Valid until '.$trial->end_date->toDateString()
            );

            return $trial;
        });
    }

    /**
     * Extend an active trial.
     */
    public function extendTrial(
        CompanyTrial $trial,
        string $newEndDate,
        string $reason,
        CompanyEmployee $actor
    ): CompanyTrialExtension {
        return DB::transaction(function () use ($trial, $newEndDate, $reason, $actor) {
            $previousEndDate = $trial->end_date;
            $trial->end_date = $newEndDate;
            $trial->status = TrialStatus::Extended;
            $trial->save();

            $extension = $trial->extensions()->create([
                'previous_end_date' => $previousEndDate,
                'new_end_date' => $newEndDate,
                'reason' => $reason,
                'extended_by_id' => $actor->id,
            ]);

            $this->activityService->logActivity(
                $trial->opportunity,
                'Trial Extended',
                "Trial extended from {$previousEndDate->toDateString()} to {$newEndDate}. Reason: {$reason}",
                $actor
            );

            return $extension;
        });
    }
}
