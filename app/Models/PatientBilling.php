<?php

namespace App\Models;

use App\BillingStatus;
use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A clinic bill (invoice) — the canonical clinic billing record (ADR-001 §G).
 *
 * Owned by a clinic and issued by a branch, whose financial-year series
 * numbers it. Amounts are kept by `App\Services\BillingService` and
 * `PaymentService` only: `total_amount` is the gross of its lines,
 * `final_amount` what the patient owes after discounts and tax, and
 * `outstanding_balance` what remains after payments and refunds.
 */
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
        'financial_year',
        'status',
        'total_amount',
        'discount_amount',
        'tax_amount',
        'final_amount',
        'paid_amount',
        'refunded_amount',
        'adjusted_amount',
        'outstanding_balance',
        'notes',
        'issued_at',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => BillingStatus::class,
            'total_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'final_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'adjusted_amount' => 'decimal:2',
            'outstanding_balance' => 'decimal:2',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
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

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'cancelled_by');
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

    public function isCancelled(): bool
    {
        return $this->status === BillingStatus::Cancelled;
    }
}
