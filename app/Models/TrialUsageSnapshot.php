<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One night's measurement of what a trial tenant did.
 *
 * Read-only by design. There is no controller, request or service that writes
 * these other than the nightly job, because the value of a usage figure is
 * entirely in nobody having been able to adjust it.
 *
 * Every column is an aggregate. Nothing here can hold a patient name, a
 * clinical note or the text of a message.
 */
class TrialUsageSnapshot extends Model
{
    protected $fillable = [
        'trial_id',
        'clinic_id',
        'organization_id',
        'captured_at',
        'users_total',
        'users_active_7d',
        'logins_7d',
        'last_login_at',
        'patients_count',
        'appointments_count',
        'appointments_7d',
        'encounters_count',
        'prescriptions_count',
        'invoices_count',
        'branches_count',
        'storage_bytes',
        'storage_limit_mb',
        'modules_enabled',
        'modules_used',
        'onboarding_readiness',
        'onboarding_checks',
        'whatsapp_sent_count',
        'whatsapp_quota',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'last_login_at' => 'datetime',
            'modules_enabled' => 'array',
            'modules_used' => 'array',
            'onboarding_checks' => 'array',
        ];
    }

    public function trial(): BelongsTo
    {
        return $this->belongsTo(Trial::class);
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Storage used as a share of what the plan sells.
     *
     * Null rather than zero when there is no limit to compare against — an
     * unknown proportion and a nil one are not the same claim.
     */
    public function storagePercent(): ?float
    {
        if (! $this->storage_limit_mb) {
            return null;
        }

        return round($this->storage_bytes / ($this->storage_limit_mb * 1024 * 1024) * 100, 2);
    }
}
