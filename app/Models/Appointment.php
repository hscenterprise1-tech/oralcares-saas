<?php

namespace App\Models;

use App\AppointmentStatus;
use App\Traits\Auditable;
use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends Model
{
    use Auditable, ClinicScoped, HasFactory;

    protected $fillable = [
        'organization_id',
        'clinic_id',
        'branch_id',
        'room_id',
        'patient_id',
        'practitioner_id',
        'provider_id',
        'scheduled_at',
        'duration_minutes',
        'status',
        'appointment_type',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'duration_minutes' => 'integer',
            'status' => AppointmentStatus::class,
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

    public function queueEntry(): HasOne
    {
        return $this->hasOne(QueueEntry::class);
    }
}
