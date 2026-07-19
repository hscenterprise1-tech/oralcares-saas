<?php

namespace App\Models;

use App\WorkflowActionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'workflow_run_id',
        'from_stage_id',
        'to_stage_id',
        'performed_by',
        'action_type',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'action_type' => WorkflowActionType::class,
        ];
    }

    public function workflowRun(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class);
    }

    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'from_stage_id');
    }

    public function toStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'to_stage_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'performed_by');
    }
}
