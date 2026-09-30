<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class MarketingAiSetting extends Model
{
    use HasFactory;

    protected $table = 'marketing_ai_settings';

    protected $fillable = [
        'organization_id',
        'provider',
        'api_key_encrypted',
        'image_model',
        'text_model',
    ];

    public function setApiKeyAttribute(?string $value): void
    {
        $this->attributes['api_key_encrypted'] = empty($value) ? null : Crypt::encryptString($value);
    }

    public function getApiKeyAttribute(): ?string
    {
        if (empty($this->attributes['api_key_encrypted'])) {
            return null;
        }

        try {
            return Crypt::decryptString($this->attributes['api_key_encrypted']);
        } catch (\Throwable) {
            return null;
        }
    }

    public function getMaskedKeyAttribute(): string
    {
        return empty($this->attributes['api_key_encrypted']) ? 'Not Configured' : '••••••••••••••••';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->attributes['api_key_encrypted']);
    }
}
