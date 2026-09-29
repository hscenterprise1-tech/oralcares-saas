<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

class MarketingInstagramAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'marketing_instagram_accounts';

    protected $fillable = [
        'organization_id',
        'instagram_business_account_id',
        'facebook_page_id',
        'username',
        'access_token_encrypted',
        'logo_path',
        'buffer_target',
        'auto_fill_enabled',
        'status',
        'last_connected_at',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'last_connected_at' => 'datetime',
            'auto_fill_enabled' => 'boolean',
        ];
    }

    public function setAccessTokenAttribute(?string $value): void
    {
        $this->attributes['access_token_encrypted'] = empty($value) ? null : Crypt::encryptString($value);
    }

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

    public function getMaskedTokenAttribute(): string
    {
        return empty($this->attributes['access_token_encrypted']) ? 'Not Configured' : '••••••••••••••••';
    }

    public function posts(): HasMany
    {
        return $this->hasMany(MarketingInstagramPost::class, 'instagram_account_id');
    }

    public function tenantOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
