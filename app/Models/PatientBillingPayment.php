<?php

namespace App\Models;

use App\PaymentMethod;
use App\Traits\ClinicScoped;
use App\Traits\InheritsBillOwnership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money received against a clinic bill, with a receipt number from the
 * branch's financial-year series. Written by `PaymentService` only.
 */
class PatientBillingPayment extends Model
{
    use ClinicScoped, InheritsBillOwnership;

    protected $fillable = [
        'patient_billing_id',
        'clinic_id',
        'branch_id',
        'receipt_number',
        'payment_method',
        'amount',
        'transaction_reference',
        'paid_at',
        'notes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'payment_method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function billing(): BelongsTo
    {
        return $this->belongsTo(PatientBilling::class, 'patient_billing_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'recorded_by');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PatientBillingRefund::class);
    }
}
