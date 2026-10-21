<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use App\Traits\InheritsClinicOwnership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One implant at one site, from planning through placement,
 * osseointegration, abutment and crown. Written through
 * `Dental\DentalSpecialtyRecords`; voided (never deleted) by approval.
 */
class ImplantRecord extends Model
{
    use ClinicScoped, InheritsClinicOwnership;

    public const STATUSES = ['planned', 'placed', 'integrating', 'restored', 'failed', 'removed'];

    public const INTEGRATION = ['healing', 'integrated', 'failed'];

    public const CROWN = ['none', 'provisional', 'final'];

    protected $fillable = [
        'patient_treatment_id',
        'clinic_id', 'patient_id', 'encounter_id', 'last_encounter_id', 'treatment_record_id', 'tooth_number', 'status', 'system',
        'diameter_mm', 'length_mm', 'lot_number', 'placed_on', 'insertion_torque_ncm', 'integration_status', 'abutment', 'abutment_on',
        'crown_status', 'crown_on', 'review_on', 'complications', 'notes', 'created_by', 'voided_at', 'voided_by', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'tooth_number' => 'integer',
            'diameter_mm' => 'float',
            'length_mm' => 'float',
            'insertion_torque_ncm' => 'integer',
            'placed_on' => 'date',
            'abutment_on' => 'date',
            'crown_on' => 'date',
            'review_on' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'created_by');
    }
}
