<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MarketingWhatsAppTemplate extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'marketing_whatsapp_templates';

    protected $fillable = [
        'organization_id',
        'whatsapp_account_id',
        'provider_template_id',
        'name',
        'language',
        'category',
        'status',
        'body',
        'header_type',
        'header_content',
        'footer',
        'buttons',
        'variables',
        'provider_payload',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'variables' => 'array',
        'buttons' => 'array',
        'provider_payload' => 'array',
    ];

    /**
     * Quick-reply button labels only (skips URL/phone buttons, which aren't tappable replies).
     *
     * @return array<int, string>
     */
    public function getQuickReplyLabelsAttribute(): array
    {
        return collect($this->buttons ?? [])
            ->filter(fn ($b) => ($b['type'] ?? null) === 'QUICK_REPLY')
            ->pluck('text')
            ->values()
            ->all();
    }

    public function whatsappAccount(): BelongsTo
    {
        return $this->belongsTo(MarketingWhatsAppAccount::class, 'whatsapp_account_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
