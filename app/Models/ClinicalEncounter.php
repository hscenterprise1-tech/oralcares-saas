<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\ClinicScoped;
use App\Traits\InheritsClinicOwnership;
use App\Traits\OrganizationScoped;
use App\VisitStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ClinicalEncounter extends Model
{
    use Auditable, ClinicScoped, HasFactory, InheritsClinicOwnership, OrganizationScoped;

    protected $fillable = [
        'organization_id',
        'clinic_id',
        'patient_id',
        'branch_id',
        'doctor_id',
        'patient_visit_id',
        'encounter_number',
        'encounter_type',
        'specialty',
        'status',
        'started_at',
        'ended_at',
        'chief_complaint',
        'history',
        'examination',
        'vitals',
        'follow_up_on',
        'follow_up_note',
        'lock_version',
        'last_autosaved_at',
        'finalized_at',
        'finalized_by',
        'signature_hash',
        'cancellation_reason',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    /** The narrative sections a doctor writes, in reading order. */
    public const NARRATIVE = ['chief_complaint', 'history', 'examination', 'follow_up_note'];

    protected function casts(): array
    {
        return [
            'status' => VisitStatus::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'vitals' => 'array',
            'follow_up_on' => 'date',
            'lock_version' => 'integer',
            'last_autosaved_at' => 'datetime',
            'finalized_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * Voided ("entered in error") by an owner-approved request: the signed
     * record stays readable, marked, and leaves every clinical summary.
     */
    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /** Signed: the record no longer changes, it only gains addenda. */
    public function isFinalized(): bool
    {
        return $this->finalized_at !== null;
    }

    /** Open for writing: started, not signed, not cancelled. */
    public function isEditable(): bool
    {
        return ! $this->isFinalized() && $this->status !== VisitStatus::Cancelled;
    }

    protected function performInsert(Builder $query)
    {
        $this->resolveClinicOwnership();

        if (empty($this->organization_id) && ! empty($this->clinic_id)) {
            $clinic = Clinic::find($this->clinic_id);
            if ($clinic && ! empty($clinic->organization_id)) {
                $this->organization_id = $clinic->organization_id;
            }
        }

        return parent::performInsert($query);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The practitioner responsible for this encounter: the single canonical
     * attribution, a `clinic_employees` row. Professional details (registration,
     * specialties) are its `practitionerProfile`. The old `provider_id` column
     * (to `doctor_profiles`) is no longer written or read.
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'doctor_id');
    }

    public function clinicalNotes(): HasMany
    {
        return $this->hasMany(ClinicalNote::class, 'encounter_id');
    }

    public function notes(): HasMany
    {
        return $this->clinicalNotes();
    }

    public function treatments(): HasMany
    {
        return $this->hasMany(TreatmentRecord::class, 'encounter_id');
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class, 'encounter_id');
    }

    public function xrayRecords(): HasMany
    {
        return $this->hasMany(XrayRecord::class, 'encounter_id');
    }

    public function labOrders(): HasMany
    {
        return $this->hasMany(LabOrder::class, 'encounter_id');
    }

    public function billingReference(): HasOne
    {
        return $this->hasOne(BillingReference::class, 'encounter_id');
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(ClinicalDiagnosis::class, 'encounter_id');
    }

    public function procedures(): HasMany
    {
        return $this->hasMany(PatientProcedure::class, 'encounter_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ClinicalOrder::class, 'encounter_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AttachedDocument::class, 'encounter_id');
    }

    public function addenda(): HasMany
    {
        return $this->hasMany(ClinicalEncounterAddendum::class, 'encounter_id');
    }

    public function consultations(): HasMany
    {
        return $this->hasMany(ClinicalConsultation::class, 'encounter_id');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(PatientVisit::class, 'patient_visit_id');
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'finalized_by');
    }

    public function bills(): HasMany
    {
        return $this->hasMany(PatientBilling::class, 'encounter_id');
    }
}
