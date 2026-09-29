<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingAutomationExecution extends Model
{
    use HasFactory;

    protected $table = 'marketing_automation_executions';

    protected $fillable = [
        'organization_id',
        'automation_id',
        'contact_id',
        'conversation_id',
        'lead_id',
        'campaign_id',
        'trigger_event_id',
        'status',
        'current_step_id',
        'started_at',
        'completed_at',
        'stopped_at',
        'stop_reason',
        'last_error',
        'next_run_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'stopped_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(MarketingAutomation::class, 'automation_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(MarketingContact::class, 'contact_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(MarketingWhatsAppConversation::class, 'conversation_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'campaign_id');
    }

    public function triggerEvent(): BelongsTo
    {
        return $this->belongsTo(MarketingAutomationEvent::class, 'trigger_event_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(MarketingAutomationExecutionStep::class, 'execution_id');
    }
}
