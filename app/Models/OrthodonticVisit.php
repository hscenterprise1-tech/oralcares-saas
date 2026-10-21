<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One adjustment visit of an orthodontic case, made in a consultation. */
class OrthodonticVisit extends Model
{
    use ClinicScoped;

    protected $fillable = [
        'orthodontic_case_id', 'clinic_id', 'encounter_id', 'phase', 'upper_wire', 'lower_wire', 'adjustments', 'elastics', 'notes',
        'next_visit_weeks', 'recorded_by',
    ];

    protected function casts(): array
    {
        return ['next_visit_weeks' => 'integer'];
    }

    public function orthodonticCase(): BelongsTo
    {
        return $this->belongsTo(OrthodonticCase::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'recorded_by');
    }
}
