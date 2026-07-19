<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ClinicalEncounter extends Model
{
    use ClinicScoped;

    protected $fillable = [
        'clinic_id',
        'patient_id',
        'branch_id',
        'doctor_id',
        'encounter_number',
        'status',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
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

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'doctor_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ClinicalNote::class, 'encounter_id');
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
}
