<?php

namespace App\Models;

use App\QueueItemStatus;
use App\VisitPriority;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClinicQueueItem extends Model
{
    protected $fillable = [
        'clinic_queue_id',
        'encounter_id',
        'patient_visit_id',
        'doctor_id',
        'appointment_id',
        'case_number',
        'position',
        'priority',
        'checked_in_at',
        'called_at',
        'started_at',
        'completed_at',
        'status',
        'current_floor',
        'current_department',
    ];

    protected function casts(): array
    {
        return [
            'priority' => VisitPriority::class,
            'status' => QueueItemStatus::class,
            'checked_in_at' => 'datetime',
            'called_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(ClinicQueue::class, 'clinic_queue_id');
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'encounter_id');
    }

    public function patientVisit(): BelongsTo
    {
        return $this->belongsTo(PatientVisit::class, 'patient_visit_id');
    }

    /**
     * The doctor the patient is waiting for; null while "any doctor".
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'doctor_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(QueueHistory::class, 'clinic_queue_item_id');
    }
}
