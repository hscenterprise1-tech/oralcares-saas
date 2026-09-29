<?php

namespace App\Models;

use App\Services\Marketing\MarketingAutomationEventService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingCampaignRecipient extends Model
{
    use HasFactory;

    protected $table = 'marketing_campaign_recipients';

    protected $fillable = [
        'campaign_id',
        'contact_id',
        'phone',
        'normalized_phone',
        'status',
        'personalized_payload',
        'provider_message_id',
        'failure_reason',
        'queued_at',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
        'replied_at',
        'crm_lead_id',
    ];

    protected $casts = [
        'personalized_payload' => 'array',
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'failed_at' => 'datetime',
        'replied_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'campaign_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(MarketingContact::class, 'contact_id');
    }

    public function markSent(string $providerMessageId): void
    {
        $this->update([
            'status' => 'sent',
            'provider_message_id' => $providerMessageId,
            'sent_at' => now(),
        ]);

        $this->dispatchCampaignEvent('campaign.sent');
    }

    public function markDelivered(): void
    {
        if (in_array($this->status, ['sent', 'queued', 'pending'])) {
            $this->update([
                'status' => 'delivered',
                'delivered_at' => now(),
            ]);

            $this->dispatchCampaignEvent('campaign.delivered');
        }
    }

    public function markRead(): void
    {
        if (in_array($this->status, ['sent', 'delivered', 'queued', 'pending'])) {
            $this->update([
                'status' => 'read',
                'read_at' => now(),
            ]);

            $this->dispatchCampaignEvent('campaign.read');
        }
    }

    public function markFailed(string $reason): void
    {
        if ($this->status !== 'read') {
            $this->update([
                'status' => 'failed',
                'failure_reason' => $reason,
                'failed_at' => now(),
            ]);
        }
    }

    public function markReplied(): void
    {
        $this->update([
            'status' => 'replied',
            'replied_at' => now(),
        ]);

        $this->dispatchCampaignEvent('campaign.replied');
    }

    protected function dispatchCampaignEvent(string $eventType): void
    {
        $campaign = $this->campaign;
        if ($campaign && $campaign->organization_id) {
            app(MarketingAutomationEventService::class)->dispatchTriggerEvent(
                organizationId: $campaign->organization_id,
                eventType: $eventType,
                eventKey: "campaign:{$campaign->id}:recip:{$this->id}:{$eventType}",
                payload: ['campaign_id' => $campaign->id, 'recipient_id' => $this->id],
                contact: $this->contact,
                campaign: $campaign
            );
        }
    }
}
