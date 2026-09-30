<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingWhatsAppWebhookEvent extends Model
{
    use HasFactory;

    protected $table = 'marketing_whatsapp_webhook_events';

    protected $fillable = [
        'organization_id',
        'whatsapp_account_id',
        'provider',
        'provider_event_id',
        'event_type',
        'payload',
        'signature_result',
        'processed_at',
        'processing_status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function whatsappAccount(): BelongsTo
    {
        return $this->belongsTo(MarketingWhatsAppAccount::class, 'whatsapp_account_id');
    }
}
