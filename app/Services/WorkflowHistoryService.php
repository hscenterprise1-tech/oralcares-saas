<?php

namespace App\Services;

use App\Models\ClinicEmployee;
use App\Models\WorkflowHistory;
use App\Models\WorkflowRun;
use App\Models\WorkflowStage;
use App\WorkflowActionType;

class WorkflowHistoryService
{
    /**
     * Log a workflow status movement history entry.
     */
    public function logHistory(
        WorkflowRun $run,
        ?WorkflowStage $from,
        ?WorkflowStage $to,
        ?ClinicEmployee $performer = null,
        WorkflowActionType $action = WorkflowActionType::Move,
        ?string $reason = null
    ): WorkflowHistory {
        return WorkflowHistory::create([
            'workflow_run_id' => $run->id,
            'from_stage_id' => $from?->id,
            'to_stage_id' => $to?->id,
            'performed_by' => $performer?->id,
            'action_type' => $action,
            'reason' => $reason,
        ]);
    }
}
