<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A specialty the clinic practises. Written only through
 * `Services\Patient\ClinicSpecialties`, which also keeps the legacy
 * `clinics.specialties` JSON in step as a read cache.
 */
class ClinicSpecialty extends Model
{
    use ClinicScoped;

    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    protected $fillable = [
        'clinic_id',
        'healthcare_specialty_master_id',
        'specialty_key',
        'status',
        'activated_at',
        'deactivated_at',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(HealthcareSpecialtyMaster::class, 'healthcare_specialty_master_id');
    }

    public function patientSpecialties(): HasMany
    {
        return $this->hasMany(PatientSpecialty::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
