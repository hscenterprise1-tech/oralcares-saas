<?php

namespace App\Models;

use App\Support\Specialty\SpecialtyEntitlements;
use App\Traits\Auditable;
use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An entry in a clinic's procedure catalogue: what can be done and billed
 * (code, name, category, specialty, typical duration, whether it can be
 * billed or booked on its own). Prices are immutable `CatalogPriceVersion`
 * rows, clinic-wide or per branch; the old `ClinicalProcedurePrice` table is
 * kept in step until it is retired.
 *
 * Billing lines and treatment records point here through
 * `clinical_procedure_id`. A procedure actually recorded against a patient is
 * a `PatientProcedure`, a separate record.
 */
class ClinicalProcedure extends Model
{
    use Auditable, ClinicScoped;

    protected $table = 'clinical_procedures';

    protected $fillable = [
        'specialty_key',
        'is_billable',
        'is_bookable',
        'metadata',
        'clinic_id',
        'code',
        'name',
        'description',
        'category',
        'default_duration_minutes',
        'clinical_complexity',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'default_duration_minutes' => 'integer',
            'requires_tooth' => 'boolean',
            'is_active' => 'boolean',
            'is_billable' => 'boolean',
            'is_bookable' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ClinicalProcedurePrice::class, 'clinical_procedure_id');
    }

    /**
     * Services the clinic's plan lets it use: the shared ones, and those of
     * the specialties it has bought (`SpecialtyEntitlements`).
     */
    public function scopeWithinPlan(Builder $query, Clinic $clinic): Builder
    {
        $entitled = app(SpecialtyEntitlements::class)->entitled($clinic);

        return $entitled === null ? $query : $query->where(fn (Builder $own) => $own->whereNull('specialty_key')->orWhereIn('specialty_key', $entitled));
    }
}
