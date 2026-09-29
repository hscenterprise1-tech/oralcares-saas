<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail for the Transfer feature — one row per doctor reassignment.
 * `Patient.current_practitioner_id` always holds the latest state; this
 * table exists purely so "who was this patient assigned to, and when, and
 * by whom" can be answered later.
 */
class PatientDoctorAssignment extends Model
{
    protected $fillable = [
        'patient_id',
        'clinic_id',
        'from_practitioner_id',
        'to_practitioner_id',
        'assigned_by',
        'reason',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function fromPractitioner(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'from_practitioner_id');
    }

    public function toPractitioner(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'to_practitioner_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
