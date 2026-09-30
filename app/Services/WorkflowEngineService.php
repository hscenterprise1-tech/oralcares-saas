<?php

namespace App\Services;

use App\Events\WorkflowCancelled;
use App\Events\WorkflowCompleted;
use App\Events\WorkflowStageChanged;
use App\Events\WorkflowStarted;
use App\Models\Branch;
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
        $this->assertDefinitionFitsEncounter($encounter, $def);

        return DB::transaction(function () use ($encounter, $def, $performer) {
            $firstStage = $def->stages()->orderBy('position')->first();

            if (! $firstStage) {
                throw new \InvalidArgumentException(
                    "Workflow definition {$def->id} has no stages, so there is nothing to start."
                );
            }

            /*
             * `encounter_id` is unique on the runs table. Without this the
             * second start surfaced as a raw integrity constraint violation,
             * which no caller could tell apart from a database fault.
             */
            if (WorkflowRun::where('encounter_id', $encounter->id)->exists()) {
                throw new \InvalidArgumentException(
                    "Encounter {$encounter->id} already has a workflow run."
                );
            }

            $run = WorkflowRun::create([
                /*
                 * From the definition, not the encounter.
                 *
                 * `clinical_encounters.clinic_id` was dropped when encounters
                 * became organisation-scoped; reading it here produced null
                 * against a NOT NULL column, so every start failed. The
                 * definition is the only row that still records a clinic, and a
                 * run belongs to the workflow it is running.
                 */
                'clinic_id' => $def->clinic_id,
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
            $this->assertActive($run, 'transition');

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
        $this->assertActive($run, 'complete');

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
        $this->assertActive($run, 'cancel');

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

    /**
     * Refuse a definition that does not belong where the encounter is.
     *
     * None of this was checked. One clinic's definition could be started on
     * another clinic's encounter, and a definition switched off still ran,
     * because `is_active` was written by the definition service and read by
     * nothing.
     */
    protected function assertDefinitionFitsEncounter(ClinicalEncounter $encounter, WorkflowDefinition $def): void
    {
        if (! $def->is_active) {
            throw new \InvalidArgumentException(
                "Workflow definition {$def->id} is not active and cannot be started."
            );
        }

        /*
         * Read without the organisation scope: this runs from HQ and from a
         * tenant request alike, and the check must give the same answer in both.
         */
        $branch = Branch::withoutOrganizationScope()->find($encounter->branch_id);

        if (! $branch) {
            throw new \InvalidArgumentException(
                "Encounter {$encounter->id} has no branch, so its clinic cannot be established."
            );
        }

        if ((int) $branch->clinic_id !== (int) $def->clinic_id) {
            throw new \InvalidArgumentException(
                "Workflow definition {$def->id} belongs to another clinic than encounter {$encounter->id}."
            );
        }

        /* A branch-scoped definition runs only in its own branch. */
        if ($def->branch_id !== null && (int) $def->branch_id !== (int) $encounter->branch_id) {
            throw new \InvalidArgumentException(
                "Workflow definition {$def->id} is scoped to another branch than encounter {$encounter->id}."
            );
        }
    }

    /**
     * A run that has finished stays finished.
     *
     * Only `transitionTo` checked this, so a completed run could be completed
     * again or cancelled afterwards — writing another history row and firing the
     * event a second time each time it happened.
     */
    protected function assertActive(WorkflowRun $run, string $action): void
    {
        if ($run->status !== 'active') {
            throw new \InvalidArgumentException(
                "Cannot {$action} workflow run {$run->id}: it is already {$run->status}."
            );
        }
    }
}
