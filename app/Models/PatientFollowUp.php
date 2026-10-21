<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use App\Traits\InheritsClinicOwnership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A review the patient is due for: asked for when a consultation is signed,
 * or added from Patient 360. Due → booked (an appointment) → completed, or
 * cancelled. Written through `Patient\FollowUps`.
 */
class PatientFollowUp extends Model
{
    use ClinicScoped, InheritsClinicOwnership;

    public const DUE = 'due';

    public const BOOKED = 'booked';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    /** How urgent, stored as a rank so the worklist pages by it. */
    public const PRIORITIES = ['normal' => 0, 'high' => 1, 'urgent' => 2];

    public function priorityLabel(): string
    {
        return array_search((int) $this->priority, self::PRIORITIES, true) ?: 'normal';
    }

    protected $fillable = [
        'patient_treatment_id',
        'clinic_id',
        'branch_id',
        'patient_id',
        'encounter_id',
        'practitioner_id',
        'due_on',
        'reason',
        'priority',
        'notes',
        'status',
        'appointment_id',
        'completed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::DUE, self::BOOKED], true);
    }

    /** The treatment this follow-up belongs to, if any. */
    public function patientTreatment(): BelongsTo
    {
        return $this->belongsTo(PatientTreatment::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'encounter_id');
    }

    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'practitioner_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
