<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use App\Traits\InheritsClinicOwnership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One periodontal charting, made in a consultation. Its teeth hold the
 * six-site measurements; the exam holds the whole-mouth figures and the
 * diagnosis. Written through `Dental\PeriodontalRecord`.
 */
class PeriodontalExam extends Model
{
    use ClinicScoped, InheritsClinicOwnership;

    protected $fillable = [
        'clinic_id',
        'patient_id',
        'encounter_id',
        'examined_by',
        'teeth_examined',
        'bop_percent',
        'plaque_percent',
        'mean_probing_depth',
        'sites_4mm_plus',
        'sites_6mm_plus',
        'stage',
        'grade',
        'extent',
        'diagnosis',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'teeth_examined' => 'integer',
            'bop_percent' => 'float',
            'plaque_percent' => 'float',
            'mean_probing_depth' => 'float',
            'sites_4mm_plus' => 'integer',
            'sites_6mm_plus' => 'integer',
        ];
    }

    public function teeth(): HasMany
    {
        return $this->hasMany(PeriodontalTooth::class)->orderBy('tooth_number');
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'encounter_id');
    }

    public function examiner(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'examined_by');
    }
}
