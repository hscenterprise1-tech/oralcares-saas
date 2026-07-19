<?php

namespace App\Models;

use App\BillingStatus;
use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PatientBilling extends Model
{
    use ClinicScoped;

    protected $fillable = [
        'clinic_id',
        'patient_id',
        'encounter_id',
        'branch_id',
        'doctor_id',
        'billing_number',
        'status',
        'total_amount',
        'discount_amount',
        'final_amount',
        'outstanding_balance',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => BillingStatus::class,
            'total_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'final_amount' => 'decimal:2',
            'outstanding_balance' => 'decimal:2',
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

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'encounter_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'doctor_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'updated_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PatientBillingItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PatientBillingPayment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PatientBillingRefund::class);
    }
}
