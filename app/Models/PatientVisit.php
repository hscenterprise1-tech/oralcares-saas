<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use App\VisitRegistrationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PatientVisit extends Model
{
    use ClinicScoped, HasFactory;

    /**
     * Visit states in which the patient was seen: the consultation began
     * (`QueueStateMachine` writes in_progress on start, completed on finish).
     * Checked in, waiting, not present, cancelled or left unseen are not.
     */
    public const SEEN_STATUSES = ['in_progress', 'completed'];

    /**
     * Visit states check-in may pick up again instead of opening a new visit:
     * a visit made at registration that never reached the queue. A visit that
     * was queued, seen, finished or abandoned is history and is never reopened.
     */
    public const REUSABLE_STATUSES = ['registered'];

    protected $fillable = [
        'clinic_id',
        'branch_id',
        'case_id',
        'patient_id',
        'appointment_id',
        'case_number_id',
        'department_id',
        'doctor_id',
        'clinic_specialty_id',
        'practice_area_key',
        'visit_type_key',
        'visit_reason',
        'visit_reason_key',
        'intake_vitals',
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
            'intake_vitals' => 'array',
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

    public function case(): BelongsTo
    {
        return $this->belongsTo(PatientCase::class, 'case_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
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

    /**
     * Visits at which the patient was actually seen: a seen state, or a
     * consultation opened on the visit. Never inferred from billing, the
     * registration date, the queue or treatment progress.
     *
     * @param  Builder<PatientVisit>  $query
     */
    public function scopeAttended(Builder $query): void
    {
        $query->where(fn (Builder $seen) => $seen->whereIn('patient_visits.visit_status', self::SEEN_STATUSES)
            ->orWhereExists(fn ($encounter) => $encounter->selectRaw('1')->from('clinical_encounters')
                ->whereColumn('clinical_encounters.patient_visit_id', 'patient_visits.id')
                ->whereColumn('clinical_encounters.clinic_id', 'patient_visits.clinic_id')));
    }

    /**
     * New for a patient never yet seen at this clinic, Repeat once they
     * have been — whatever came before that they never attended.
     */
    public static function classifyNext(int $clinicId, int $patientId): VisitRegistrationType
    {
        return self::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->where('patient_id', $patientId)->attended()->exists()
            ? VisitRegistrationType::Repeat
            : VisitRegistrationType::New;
    }
}
