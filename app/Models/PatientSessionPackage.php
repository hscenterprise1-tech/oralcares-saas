<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use App\Traits\InheritsClinicOwnership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A prepaid package of treatment sessions bought by a patient. Each session
 * is punched against it (`SessionPackageUsage`); it is exhausted when every
 * session is used, and unusable after its validity date.
 */
class PatientSessionPackage extends Model
{
    use ClinicScoped, InheritsClinicOwnership;

    public const ACTIVE = 'active';

    public const EXHAUSTED = 'exhausted';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'patient_treatment_id',
        'renews_package_id',
        'clinic_id',
        'branch_id',
        'patient_id',
        'clinical_procedure_id',
        'name',
        'sessions_total',
        'valid_until',
        'status',
        'patient_billing_id',
        'sold_by',
    ];

    protected function casts(): array
    {
        return [
            'sessions_total' => 'integer',
            'sessions_used' => 'integer',
            'sessions_adjusted' => 'integer',
            'valid_until' => 'date',
        ];
    }

    /**
     * Remaining sessions from the cached ledger totals, for display. Anything
     * that consumes reads the ledger itself (`SessionPackages::balance()`).
     */
    public function remaining(): int
    {
        return max(0, $this->sessions_total + (int) $this->sessions_adjusted - $this->sessions_used);
    }

    public function renews(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renews_package_id');
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(PatientTreatment::class, 'patient_treatment_id');
    }

    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->endOfDay()->isPast();
    }

    public function usages(): HasMany
    {
        return $this->hasMany(SessionPackageUsage::class, 'package_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PatientBilling::class, 'patient_billing_id');
    }
}
