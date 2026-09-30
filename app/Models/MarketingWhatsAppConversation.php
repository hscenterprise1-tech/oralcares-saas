<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingWhatsAppConversation extends Model
{
    use HasFactory;

    protected $table = 'marketing_whatsapp_conversations';

    protected $fillable = [
        'organization_id',
        'whatsapp_account_id',
        'contact_id',
        'phone',
        'normalized_phone',
        'status',
        'last_message_at',
        'last_inbound_at',
        'last_outbound_at',
        'unread_count',
        'assigned_user_id',
        'crm_lead_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'last_outbound_at' => 'datetime',
        ];
    }

    public function crmLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'crm_lead_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(MarketingContact::class, 'contact_id');
    }

    public function whatsappAccount(): BelongsTo
    {
        return $this->belongsTo(MarketingWhatsAppAccount::class, 'whatsapp_account_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MarketingWhatsAppMessage::class, 'conversation_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * The customer's last message is still unanswered — the same condition
     * backs both the "Needs reply" filter and its count, so the number on
     * the tab can never disagree with what filtering by it shows.
     */
    public function scopeNeedsReply(Builder $query): Builder
    {
        return $query->whereNotNull('last_inbound_at')
            ->where(function (Builder $q) {
                $q->whereNull('last_outbound_at')
                    ->orWhereColumn('last_inbound_at', '>', 'last_outbound_at');
            });
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (empty($term)) {
            return $query;
        }

        $clean = trim($term);

        return $query->where(function (Builder $q) use ($clean) {
            $q->where('phone', 'like', "%{$clean}%")
                ->orWhere('normalized_phone', 'like', "%{$clean}%")
                ->orWhereHas('contact', function (Builder $cq) use ($clean) {
                    $cq->where('name', 'like', "%{$clean}%")
                        ->orWhere('organization', 'like', "%{$clean}%")
                        ->orWhere('city', 'like', "%{$clean}%");
                });
        });
    }
}
