<?php

namespace App\Services;

use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;

class WorkflowTransitionService
{
    public function __construct(
        protected WorkflowRuleService $ruleService
    ) {}

    /**
     * Map a transition step.
     */
    public function createTransition(
        WorkflowDefinition $def,
        WorkflowStage $from,
        WorkflowStage $to,
        ?array $rules = null,
        ?array $validationRules = null
    ): WorkflowTransition {
        /*
         * Both stages have to be this definition's own.
         *
         * Unchecked, a transition could name a stage from another definition —
         * another tenant's included — producing a wiring that reads valid and
         * can never fire, because `canTransition` only ever looks for rows
         * matching the run's own definition.
         */
        foreach (['from' => $from, 'to' => $to] as $side => $stage) {
            if ((int) $stage->workflow_definition_id !== (int) $def->id) {
                throw new \InvalidArgumentException(
                    "The {$side} stage {$stage->id} belongs to another workflow definition."
                );
            }
        }

        return WorkflowTransition::create([
            'workflow_definition_id' => $def->id,
            'from_stage_id' => $from->id,
            'to_stage_id' => $to->id,
            'rules' => $rules,
            'validation_rules' => $validationRules,
        ]);
    }

    /**
     * Determine if an active workflow run can transition to the target stage.
     */
    public function canTransition(WorkflowRun $run, WorkflowStage $toStage, array $context = []): bool
    {
        // 1. Fetch matching transition definition
        $transition = WorkflowTransition::where('workflow_definition_id', $run->workflow_definition_id)
            ->where('from_stage_id', $run->current_stage_id)
            ->where('to_stage_id', $toStage->id)
            ->first();

        if (! $transition) {
            return false;
        }

        // 2. Evaluate criteria rules
        if ($transition->rules) {
            foreach ($transition->rules as $key => $val) {
                if (! $this->ruleService->evaluateRule([$key => $val], $context)) {
                    return false;
                }
            }
        }

        return true;
    }
}
