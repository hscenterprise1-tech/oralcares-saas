<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingAutomationExecutionStep extends Model
{
    use HasFactory;

    protected $table = 'marketing_automation_execution_steps';

    protected $fillable = [
        'execution_id',
        'automation_step_id',
        'step_type',
        'status',
        'attempts',
        'input_payload',
        'output_payload',
        'error_message',
        'scheduled_at',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'input_payload' => 'array',
            'output_payload' => 'array',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(MarketingAutomationExecution::class, 'execution_id');
    }
}
