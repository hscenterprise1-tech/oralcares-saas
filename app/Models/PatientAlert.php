<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use App\Traits\InheritsClinicOwnership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientAlert extends Model
{
    use ClinicScoped, InheritsClinicOwnership;

    protected $fillable = [
        'clinic_id',
        'patient_id',
        'type',
        'description',
        'severity',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
