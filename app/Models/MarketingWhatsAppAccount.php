<?php

namespace App\Models;

use App\WhatsAppNumberPurpose;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

class MarketingWhatsAppAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'marketing_whatsapp_accounts';

    protected $fillable = [
        'organization_id',
        'provider',
        'purpose',
        'label',
        'business_account_id',
        'phone_number_id',
        'display_phone_number',
        'verified_name',
        'access_token_encrypted',
        'webhook_verify_token_encrypted',
        'status',
        'connection_status',
        'last_connected_at',
        'last_webhook_at',
        'metadata',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    /**
     * Every screen that shows a line wants its human name, so it travels with
     * the model rather than being rebuilt in each controller.
     */
    protected $appends = ['display_name'];

    /*
     * The column has this default too, but a database default only appears
     * after a refresh — code reading the purpose straight after
     * creating one would otherwise get null and have to guess. Same answer in
     * memory and on disk.
     */
    protected $attributes = [
        'purpose' => 'marketing',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => WhatsAppNumberPurpose::class,
            'metadata' => 'array',
            'last_connected_at' => 'datetime',
            'last_webhook_at' => 'datetime',
        ];
    }

    /**
     * Encrypt Access Token automatically when setting.
     */
    public function setAccessTokenAttribute(?string $value): void
    {
        if (! empty($value)) {
            $this->attributes['access_token_encrypted'] = Crypt::encryptString($value);
        } else {
            $this->attributes['access_token_encrypted'] = null;
        }
    }

    /**
     * Decrypt Access Token securely.
     */
    public function getAccessTokenAttribute(): ?string
    {
        if (empty($this->attributes['access_token_encrypted'])) {
            return null;
        }

        try {
            return Crypt::decryptString($this->attributes['access_token_encrypted']);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Encrypt Webhook Verify Token automatically when setting.
     */
    public function setWebhookVerifyTokenAttribute(?string $value): void
    {
        if (! empty($value)) {
            $this->attributes['webhook_verify_token_encrypted'] = Crypt::encryptString($value);
        } else {
            $this->attributes['webhook_verify_token_encrypted'] = null;
        }
    }

    /**
     * Decrypt Webhook Verify Token securely.
     */
    public function getWebhookVerifyTokenAttribute(): ?string
    {
        if (empty($this->attributes['webhook_verify_token_encrypted'])) {
            return null;
        }

        try {
            return Crypt::decryptString($this->attributes['webhook_verify_token_encrypted']);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Safe masked token representation for frontend (neither raw token is ever exposed).
     */
    public function getMaskedTokenAttribute(): string
    {
        if (empty($this->attributes['access_token_encrypted'])) {
            return 'Not Configured';
        }

        return '••••••••••••••••';
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(MarketingWhatsAppConversation::class, 'whatsapp_account_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MarketingWhatsAppMessage::class, 'whatsapp_account_id');
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(MarketingWhatsAppWebhookEvent::class, 'whatsapp_account_id');
    }

    public function tenantOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * What to call this number in front of a person.
     *
     * The label if someone set one, then whatever Meta verified, then the number
     * itself. A reader should never be shown an empty string where a line name
     * belongs, and "9876543210" is a worse answer than "Main sales line" but a
     * far better one than nothing.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->label
            ?: ($this->verified_name ?: ($this->display_phone_number ?: 'Unnamed line'));
    }

    /**
     * The number to send from for a given purpose.
     *
     * Falls back to the marketing line, then to any connected number, because a
     * message that cannot resolve a sender is a message that does not go — and
     * silently not sending is the worst of the three outcomes.
     */
    public static function forPurpose(int $organizationId, WhatsAppNumberPurpose $purpose): ?self
    {
        $scoped = fn () => static::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'connected');

        return $scoped()->where('purpose', $purpose->value)->first()
            ?? $scoped()->where('purpose', WhatsAppNumberPurpose::Marketing->value)->first()
            ?? $scoped()->first();
    }

    public function scopeForPurposeValue($query, string $purpose)
    {
        return $query->where('purpose', $purpose);
    }
}
