<?php

namespace App\Services;

use App\Models\CompanyEmployee;
use App\Models\CompanyOpportunity;
use App\OpportunityStage;
use Illuminate\Support\Facades\DB;

class SalesOpportunityService
{
    public function __construct(
        protected SalesActivityService $activityService
    ) {}

    /**
     * Create a new sales opportunity.
     */
    public function createOpportunity(array $data, CompanyEmployee $employee): CompanyOpportunity
    {
        return DB::transaction(function () use ($data, $employee) {
            $opportunity = CompanyOpportunity::create(array_merge($data, [
                'stage' => OpportunityStage::New,
                'assigned_id' => $data['assigned_id'] ?? $employee->id,
            ]));

            $this->activityService->logActivity(
                $opportunity,
                'Opportunity Created',
                'Opportunity titled "'.$opportunity->title.'" created by '.$employee->designation,
                $employee
            );

            return $opportunity;
        });
    }

    /**
     * Transition the stage of the opportunity.
     */
    public function transitionStage(
        CompanyOpportunity $opportunity,
        OpportunityStage|string $newStage,
        ?CompanyEmployee $employee = null,
        ?string $notes = null
    ): void {
        $stageEnum = $newStage instanceof OpportunityStage ? $newStage : OpportunityStage::from($newStage);

        $oldStage = $opportunity->stage;
        $opportunity->stage = $stageEnum;

        if (in_array($stageEnum, [OpportunityStage::Won, OpportunityStage::Lost])) {
            $opportunity->closed_at = now();
        }

        $opportunity->save();

        $this->activityService->logActivity(
            $opportunity,
            'Stage Transitioned',
            "Opportunity transitioned from stage {$oldStage->value} to {$stageEnum->value}. ".($notes ?? ''),
            $employee
        );
    }
}
