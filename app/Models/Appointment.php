<?php

namespace App\Models;

use App\AppointmentSource;
use App\AppointmentStatus;
use App\Traits\Auditable;
use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Appointment extends Model
{
    use Auditable, ClinicScoped, HasFactory;

    protected $fillable = [
        'organization_id',
        'clinic_id',
        'branch_id',
        'room_id',
        'service_id',
        'appointment_series_id',
        'patient_id',
        'prospect_name',
        'prospect_phone',
        'practitioner_id',
        'provider_id',
        'scheduled_at',
        'duration_minutes',
        'status',
        'appointment_type',
        'source',
        'notes',
        'confirmed_at',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'rescheduled_at',
        'reschedule_count',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'duration_minutes' => 'integer',
            'status' => AppointmentStatus::class,
            'source' => AppointmentSource::class,
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'rescheduled_at' => 'datetime',
            'reschedule_count' => 'integer',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(ClinicRoom::class);
    }

    /**
     * The service booked, from the clinic's procedure catalogue.
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(ClinicalProcedure::class, 'service_id');
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(AppointmentSeries::class, 'appointment_series_id');
    }

    /**
     * The appointment's end, from its start and duration.
     */
    public function endsAt(): ?Carbon
    {
        return $this->scheduled_at?->copy()->addMinutes($this->duration_minutes ?? 15);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * The healthcare practitioner / provider assigned to the appointment.
     */
    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'practitioner_id');
    }

    /**
     * Backward-compatible provider relation.
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }

    /**
     * The logged-in staff/user who created the appointment record.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The logged-in staff/user who last modified the appointment record.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The Reception queue item this appointment was checked in as.
     */
    public function queueItem(): HasOne
    {
        return $this->hasOne(ClinicQueueItem::class)->latestOfMany();
    }

    public function queueEntry(): HasOne
    {
        return $this->hasOne(QueueEntry::class);
    }
}
