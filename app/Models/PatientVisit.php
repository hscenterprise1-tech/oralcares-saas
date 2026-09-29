<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use App\VisitRegistrationType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PatientVisit extends Model
{
    use ClinicScoped, HasFactory;

    protected $fillable = [
        'clinic_id',
        'branch_id',
        'patient_id',
        'case_number_id',
        'department_id',
        'doctor_id',
        'registration_type',
        'visit_date',
        'queue_status',
        'visit_status',
        'registration_fee_collected',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'registration_type' => VisitRegistrationType::class,
            'visit_date' => 'date',
            'registration_fee_collected' => 'boolean',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

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

    public function department(): BelongsTo
    {
        return $this->belongsTo(ClinicDepartment::class, 'department_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'doctor_id');
    }

    /**
     * The patient's case number for the period this visit falls in — shared
     * by every visit in that period, not issued per visit.
     */
    public function caseNumber(): BelongsTo
    {
        return $this->belongsTo(CaseNumber::class);
    }

    public function queueItem(): HasOne
    {
        return $this->hasOne(ClinicQueueItem::class, 'patient_visit_id');
    }

    public function encounter(): HasOne
    {
        return $this->hasOne(ClinicalEncounter::class, 'patient_visit_id');
    }
}
