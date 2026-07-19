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
