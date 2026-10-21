<?php

namespace App\Models;

use App\Contracts\WhatsAppCredentials;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A WhatsApp business number OralCares operates for clinics.
 *
 * `company_shared`: OralCares' common number, serving every clinic in Common
 * mode (no clinic). `clinic_owned`: one clinic's own number, configured and
 * activated by OralCares staff for that clinic only. Credentials are
 * encrypted and never leave the company side.
 */
class WhatsAppConnection extends Model implements WhatsAppCredentials
{
    public const SHARED = 'company_shared';

    public const OWNED = 'clinic_owned';

    public const ACTIVE = 'active';

    protected $table = 'whatsapp_connections';

    protected $fillable = [
        'mode', 'clinic_id', 'label', 'display_phone_number', 'phone_number_id', 'business_account_id',
        'access_token', 'webhook_verify_token', 'status', 'health_message', 'last_checked_at', 'activated_at', 'activated_by',
    ];

    protected $hidden = ['access_token', 'webhook_verify_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'webhook_verify_token' => 'encrypted',
            'last_checked_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(WhatsAppTemplate::class, 'whatsapp_connection_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }

    public function isShared(): bool
    {
        return $this->mode === self::SHARED;
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function whatsAppAccessToken(): ?string
    {
        return $this->access_token;
    }

    public function whatsAppPhoneNumberId(): ?string
    {
        return $this->phone_number_id;
    }

    public function whatsAppBusinessAccountId(): ?string
    {
        return $this->business_account_id;
    }

    public function whatsAppLogKey(): string
    {
        return 'clinic-connection:'.$this->id;
    }

    /** The number, with all but the last four digits hidden. */
    public function maskedNumber(): ?string
    {
        $number = (string) $this->display_phone_number;

        return $number === '' ? null : str_repeat('•', max(0, strlen($number) - 4)).substr($number, -4);
    }
}
