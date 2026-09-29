<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingAutomationEvent extends Model
{
    use HasFactory;

    protected $table = 'marketing_automation_events';

    protected $fillable = [
        'organization_id',
        'event_key',
        'event_type',
        'contact_id',
        'conversation_id',
        'lead_id',
        'campaign_id',
        'payload',
        'occurred_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
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

    public function executions(): HasMany
    {
        return $this->hasMany(MarketingAutomationExecution::class, 'trigger_event_id');
    }
}
