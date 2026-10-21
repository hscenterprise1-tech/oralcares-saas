<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use App\Traits\InheritsClinicOwnership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An orthodontic course of treatment: arches, appliance, phase, and its
 * adjustment visits. Written through `Dental\DentalSpecialtyRecords`.
 */
class OrthodonticCase extends Model
{
    use ClinicScoped, InheritsClinicOwnership;

    public const APPLIANCES = ['fixed_metal' => 'Fixed — metal brackets', 'fixed_ceramic' => 'Fixed — ceramic brackets', 'lingual' => 'Lingual brackets', 'aligners' => 'Clear aligners', 'removable' => 'Removable appliance', 'functional' => 'Functional appliance', 'retainer' => 'Retainer'];

    public const PHASES = ['planning', 'levelling', 'working', 'finishing', 'retention', 'completed'];

    protected $fillable = [
        'clinic_id', 'patient_id', 'encounter_id', 'status', 'arches', 'appliance', 'bracket_system', 'phase', 'diagnosis', 'started_on',
        'estimated_months', 'bracketed_teeth', 'notes', 'created_by', 'completed_at', 'voided_at', 'voided_by', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'started_on' => 'date',
            'estimated_months' => 'integer',
            'bracketed_teeth' => 'array',
            'completed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function visits(): HasMany
    {
        return $this->hasMany(OrthodonticVisit::class)->latest('id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'created_by');
    }
}
