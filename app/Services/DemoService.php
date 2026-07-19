<?php

namespace App\Services;

use App\DemoOutcome;
use App\Models\CompanyDemo;
use App\Models\CompanyEmployee;
use App\Models\CompanyOpportunity;
use App\OpportunityStage;
use Illuminate\Support\Facades\DB;

class DemoService
{
    public function __construct(
        protected SalesOpportunityService $opportunityService,
        protected SalesActivityService $activityService
    ) {}

    /**
     * Schedule a new demo.
     */
    public function scheduleDemo(CompanyOpportunity $opportunity, array $demoData, CompanyEmployee $scheduler): CompanyDemo
    {
        return DB::transaction(function () use ($opportunity, $demoData, $scheduler) {
            $demo = $opportunity->demos()->create(array_merge($demoData, [
                'outcome' => DemoOutcome::Scheduled,
            ]));

            $this->opportunityService->transitionStage(
                $opportunity,
                OpportunityStage::DemoScheduled,
                $scheduler,
                'Demo scheduled on '.$demo->scheduled_at->toDayDateTimeString()
            );

            return $demo;
        });
    }

    /**
     * Complete or update outcome of a demo.
     */
    public function completeDemo(
        CompanyDemo $demo,
        DemoOutcome|string $outcome,
        ?string $feedback = null,
        ?CompanyEmployee $presenter = null
    ): void {
        DB::transaction(function () use ($demo, $outcome, $feedback, $presenter) {
            $outcomeEnum = $outcome instanceof DemoOutcome ? $outcome : DemoOutcome::from($outcome);

            $demo->outcome = $outcomeEnum;
            if ($feedback) {
                $demo->feedback = $feedback;
            }
            $demo->save();

            $eventDescription = "Demo marked as {$outcomeEnum->value}. Presenter feedback: ".($feedback ?? 'None');

            if ($outcomeEnum === DemoOutcome::Completed) {
                $this->opportunityService->transitionStage(
                    $demo->opportunity,
                    OpportunityStage::DemoCompleted,
                    $presenter,
                    $eventDescription
                );
            } else {
                $this->activityService->logActivity(
                    $demo->opportunity,
                    'Demo Outcome Updated',
                    $eventDescription,
                    $presenter
                );
            }
        });
    }
}
