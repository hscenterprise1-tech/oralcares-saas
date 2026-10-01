<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recurring booking. Its occurrences are ordinary appointments.
 */
class AppointmentSeries extends Model
{
    use ClinicScoped;

    public const FREQUENCIES = ['daily', 'weekly', 'fortnightly', 'monthly'];

    protected $table = 'appointment_series';

    protected $fillable = [
        'clinic_id',
        'branch_id',
        'patient_id',
        'practitioner_id',
        'service_id',
        'frequency',
        'occurrences',
        'starts_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'occurrences' => 'integer',
        ];
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'appointment_series_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
