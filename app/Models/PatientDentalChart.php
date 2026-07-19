<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientDentalChart extends Model
{
    protected $fillable = [
        'patient_id',
        'tooth_number',
        'status',
        'notes',
        'updated_by_doctor_id',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'updated_by_doctor_id');
    }
}
