<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientDentalChart extends Model
{
    use ClinicScoped, HasFactory;

    protected $fillable = [
        'clinic_id',
        'patient_id',
        'tooth_number',
        'status',
        'notes',
        'updated_by_doctor_id',
    ];

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'updated_by_doctor_id');
    }
}
