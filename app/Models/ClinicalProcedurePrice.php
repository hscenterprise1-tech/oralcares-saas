<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a clinic charges for a catalogue procedure. A row with no branch is the
 * clinic-wide price; a row with a branch overrides it at that branch.
 */
class ClinicalProcedurePrice extends Model
{
    use ClinicScoped;

    protected $fillable = [
        'clinic_id',
        'branch_id',
        'clinical_procedure_id',
        'price',
        'duration_minutes',
        'is_available',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_available' => 'boolean',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(ClinicalProcedure::class, 'clinical_procedure_id');
    }
}
