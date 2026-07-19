<?php

namespace App\Events;

use App\Models\WorkflowRun;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkflowStageChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public WorkflowRun $workflowRun
    ) {}
}
