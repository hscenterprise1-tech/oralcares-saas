<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\ClinicScoped;
use App\Traits\InheritsClinicOwnership;
use App\Traits\OrganizationScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicalDiagnosis extends Model
{
    use Auditable, ClinicScoped, InheritsClinicOwnership, OrganizationScoped;

    protected $fillable = [
        'organization_id',
        'clinic_id',
        'encounter_id',
        'patient_id',
        'diagnosis_master_id',
        'coding_system',
        'code',
        'description',
        'details',
        'status',
        'diagnosed_by',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
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
        return $this->belongsTo(DiagnosisMaster::class, 'diagnosis_master_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diagnosed_by');
    }
}
