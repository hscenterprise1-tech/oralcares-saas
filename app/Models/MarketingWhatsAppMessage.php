<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingWhatsAppMessage extends Model
{
    use HasFactory;

    protected $table = 'marketing_whatsapp_messages';

    protected $fillable = [
        'organization_id',
        'whatsapp_account_id',
        'conversation_id',
        'contact_id',
        'provider_message_id',
        'direction',
        'message_type',
        'body',
        'template_name',
        'status',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
        'failure_reason',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(MarketingWhatsAppConversation::class, 'conversation_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(MarketingContact::class, 'contact_id');
    }

    public function whatsappAccount(): BelongsTo
    {
        return $this->belongsTo(MarketingWhatsAppAccount::class, 'whatsapp_account_id');
    }
}
