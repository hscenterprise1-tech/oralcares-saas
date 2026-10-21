<?php

namespace App\Models;

use App\PaymentMethod;
use App\Traits\ClinicScoped;
use App\Traits\InheritsBillOwnership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money returned against a clinic bill, with a refund number from the
 * branch's financial-year series. Written by `PaymentService` only.
 */
class PatientBillingRefund extends Model
{
    use ClinicScoped, InheritsBillOwnership;

    protected $fillable = [
        'patient_billing_id',
        'patient_billing_payment_id',
        'clinic_id',
        'branch_id',
        'refund_number',
        'amount',
        'payment_method',
        'reason',
        'refunded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
        ];
    }

    public function billing(): BelongsTo
    {
        return $this->belongsTo(PatientBilling::class, 'patient_billing_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(PatientBillingPayment::class, 'patient_billing_payment_id');
    }

    public function refunder(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'refunded_by');
    }
}
