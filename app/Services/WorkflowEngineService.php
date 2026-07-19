<?php

namespace App\Services;

use App\Events\WorkflowCancelled;
use App\Events\WorkflowCompleted;
use App\Events\WorkflowStageChanged;
use App\Events\WorkflowStarted;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Models\WorkflowStage;
use App\WorkflowActionType;
use Illuminate\Support\Facades\DB;

class WorkflowEngineService
{
    public function __construct(
        protected WorkflowTransitionService $transitionService,
        protected WorkflowHistoryService $historyService
    ) {}

    /**
     * Start a new workflow orchestrator instance for an encounter.
     */
    public function startWorkflow(
        ClinicalEncounter $encounter,
        WorkflowDefinition $def,
        ?ClinicEmployee $performer = null
    ): WorkflowRun {
        return DB::transaction(function () use ($encounter, $def, $performer) {
            $firstStage = $def->stages()->orderBy('position')->firstOrFail();

            $run = WorkflowRun::create([
                'clinic_id' => $encounter->clinic_id,
                'branch_id' => $encounter->branch_id,
                'workflow_definition_id' => $def->id,
                'encounter_id' => $encounter->id,
                'current_stage_id' => $firstStage->id,
                'status' => 'active',
            ]);

            $this->historyService->logHistory(
                $run,
                null,
                $firstStage,
                $performer,
                WorkflowActionType::Move,
                'Workflow initiated.'
            );

            event(new WorkflowStarted($run));

            return $run;
        });
    }

    /**
     * Transition a running case to the target stage, checking validation and evaluation rules.
     */
    public function transitionTo(
        WorkflowRun $run,
        WorkflowStage $toStage,
        ?ClinicEmployee $performer = null,
        ?string $reason = null,
        array $context = []
    ): void {
        DB::transaction(function () use ($run, $toStage, $performer, $reason, $context) {
            if ($run->status !== 'active') {
                throw new \InvalidArgumentException('Cannot transition a inactive workflow run.');
            }

            if (! $this->transitionService->canTransition($run, $toStage, $context)) {
                throw new \InvalidArgumentException('Transition criteria check failed.');
            }

            $fromStage = $run->currentStage;

            $run->update(['current_stage_id' => $toStage->id]);

            $this->historyService->logHistory(
                $run,
                $fromStage,
                $toStage,
                $performer,
                WorkflowActionType::Move,
                $reason
            );

            event(new WorkflowStageChanged($run));
        });
    }

    /**
     * Complete the active workflow.
     */
    public function completeWorkflow(WorkflowRun $run, ?ClinicEmployee $performer = null): void
    {
        DB::transaction(function () use ($run, $performer) {
            $run->update(['status' => 'completed']);

            $this->historyService->logHistory(
                $run,
                $run->currentStage,
                null,
                $performer,
                WorkflowActionType::Complete,
                'Workflow completed.'
            );

            event(new WorkflowCompleted($run));
        });
    }

    /**
     * Cancel the active workflow run.
     */
    public function cancelWorkflow(
        WorkflowRun $run,
        ?ClinicEmployee $performer = null,
        ?string $reason = null
    ): void {
        DB::transaction(function () use ($run, $performer, $reason) {
            $run->update(['status' => 'cancelled']);

            $this->historyService->logHistory(
                $run,
                $run->currentStage,
                null,
                $performer,
                WorkflowActionType::Cancel,
                $reason
            );

            event(new WorkflowCancelled($run));
        });
    }
}
