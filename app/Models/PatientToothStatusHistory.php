<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientToothStatusHistory extends Model
{
    protected $table = 'patient_tooth_status_histories';

    protected $fillable = [
        'patient_id',
        'tooth_number',
        'status',
        'notes',
        'treatment_record_id',
        'updated_by_doctor_id',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function treatmentRecord(): BelongsTo
    {
        return $this->belongsTo(TreatmentRecord::class, 'treatment_record_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'updated_by_doctor_id');
    }
}
