<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A patient's enrolment in one of the clinic's specialties. Written only
 * through `Services\Patient\PatientSpecialties`.
 */
class PatientSpecialty extends Model
{
    use ClinicScoped;

    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    public const DISCHARGED = 'discharged';

    /** How the enrolment came about. */
    public const SOURCES = ['registration', 'encounter', 'treatment', 'manual', 'backfill'];

    protected $fillable = [
        'clinic_id',
        'patient_id',
        'clinic_specialty_id',
        'status',
        'source',
        'enrolled_at',
        'enrolled_by',
        'last_activity_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'enrolled_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function clinicSpecialty(): BelongsTo
    {
        return $this->belongsTo(ClinicSpecialty::class);
    }
}
