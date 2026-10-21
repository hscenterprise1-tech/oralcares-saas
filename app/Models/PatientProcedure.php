<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\ClinicScoped;
use App\Traits\InheritsClinicOwnership;
use App\Traits\OrganizationScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A procedure recorded against a patient in an encounter: planned, done or
 * cancelled. The thing that was done, not the catalogue entry describing it
 * (that is `ClinicalProcedure`).
 *
 * Still organisation-scoped, as it was before the catalogue/performed split;
 * moving it to clinic ownership is part of the encounter-family work.
 */
class PatientProcedure extends Model
{
    use Auditable, ClinicScoped, InheritsClinicOwnership, OrganizationScoped;

    protected $fillable = [
        'patient_treatment_id',
        'patient_treatment_stage_id',
        'price_version_id',
        'charged_amount',
        'clinic_id',
        'organization_id',
        'encounter_id',
        'patient_id',
        'procedure_master_id',
        'clinical_procedure_id',
        'code',
        'name',
        'quantity',
        'description',
        'details',
        'status',
        'performed_by',
        'performed_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'quantity' => 'integer',
            'performed_at' => 'datetime',
            'charged_amount' => 'decimal:2',
        ];
    }

    /** The amount charged is fixed when the procedure is done; it is never re-priced. */
    protected function performUpdate(Builder $query)
    {
        if ($this->isDirty(['charged_amount', 'price_version_id']) && ($this->getRawOriginal('charged_amount') !== null || $this->getRawOriginal('price_version_id') !== null)) {
            throw new LogicException('A performed procedure keeps the amount it was charged at.');
        }

        return parent::performUpdate($query);
    }

    public function patientTreatment(): BelongsTo
    {
        return $this->belongsTo(PatientTreatment::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'encounter_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(ProcedureMaster::class, 'procedure_master_id');
    }

    /** The clinic's own catalogue service this procedure is (and is billed as). */
    public function service(): BelongsTo
    {
        return $this->belongsTo(ClinicalProcedure::class, 'clinical_procedure_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
