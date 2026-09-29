<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An entry in a clinic's procedure catalogue: what can be done and billed
 * (code, name, category, typical duration). Prices live in
 * `ClinicalProcedurePrice`, clinic-wide or per branch.
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
        'clinic_id',
        'code',
        'name',
        'description',
        'category',
        'default_duration_minutes',
        'clinical_complexity',
        'requires_tooth',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'default_duration_minutes' => 'integer',
            'requires_tooth' => 'boolean',
            'is_active' => 'boolean',
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
}
