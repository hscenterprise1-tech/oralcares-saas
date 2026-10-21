<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a clinic owner decides about patient WhatsApp: on or off, Common
 * (OralCares' number) or Own (the clinic's number, activated by OralCares),
 * which automations run, reminder timing and the opt-in policy. The
 * technical connection is never configured here.
 */
class ClinicWhatsAppSettings extends Model
{
    use ClinicScoped;

    public const COMMON = 'common';

    public const OWN = 'own';

    protected $table = 'clinic_whatsapp_settings';

    protected $fillable = ['clinic_id', 'enabled', 'mode', 'own_requested_at', 'own_request_note', 'automations', 'reminder_lead_hours', 'require_opt_in', 'updated_by'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'automations' => 'array',
            'require_opt_in' => 'boolean',
            'own_requested_at' => 'datetime',
            'reminder_lead_hours' => 'integer',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function automationEnabled(string $key): bool
    {
        return (bool) (($this->automations ?? [])[$key] ?? false);
    }
}
