<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingSettings extends Model
{
    use HasFactory;

    protected $table = 'marketing_settings';

    protected $fillable = [
        'organization_id',
        'default_whatsapp_account_id',
        'max_daily_messages',
        'hourly_dispatch_rate',
        'quiet_hours_enabled',
        'quiet_hours_start',
        'quiet_hours_end',
        'emergency_freeze_enabled',
        'opt_out_keywords',
        'auto_suppress_on_failures',
        'max_consecutive_failures',
    ];

    protected $casts = [
        'quiet_hours_enabled' => 'boolean',
        'emergency_freeze_enabled' => 'boolean',
        'auto_suppress_on_failures' => 'boolean',
        'opt_out_keywords' => 'array',
        'max_daily_messages' => 'integer',
        'hourly_dispatch_rate' => 'integer',
        'max_consecutive_failures' => 'integer',
    ];

    public function tenantOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function defaultWhatsappAccount(): BelongsTo
    {
        return $this->belongsTo(MarketingWhatsAppAccount::class, 'default_whatsapp_account_id');
    }
}
