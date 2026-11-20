<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PatientCase extends Model
{
    use ClinicScoped, HasFactory;

    /** Under care. */
    public const ACTIVE = 'active';

    /** The treating practitioner (or, for physiotherapy, the discharge) says the care is complete; it awaits authorised closure. */
    public const COMPLETION_RECOMMENDED = 'completion_recommended';

    /** Closed by an authorised member; kept with its whole history, and may be reopened. */
    public const CLOSED = 'closed';

    /** Still the patient's open episode: new visits join it, and it shows as active. */
    public const OPEN = [self::ACTIVE, self::COMPLETION_RECOMMENDED];

    protected $fillable = [
        'clinic_id',
        'branch_id',
        'patient_id',
        'case_number',
        'specialty',
        'title',
        'chief_complaint',
        'primary_diagnosis',
        'lead_practitioner_id',
        'referring_doctor',
        'status',
        'opened_at',
        'closed_at',
        'discharge_summary',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function leadPractitioner(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'lead_practitioner_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(PatientVisit::class, 'case_id');
    }
}
