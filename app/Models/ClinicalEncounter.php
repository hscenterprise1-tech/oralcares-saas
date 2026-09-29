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
        'provider_id',
        'doctor_id',
        'encounter_number',
        'encounter_type',
        'status',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => VisitStatus::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
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

    public function provider(): BelongsTo
    {
        return $this->belongsTo(DoctorProfile::class, 'provider_id');
    }

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
}
