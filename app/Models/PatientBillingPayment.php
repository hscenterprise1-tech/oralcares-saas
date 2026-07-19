<?php

namespace App\Models;

use App\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientBillingPayment extends Model
{
    protected $fillable = [
        'patient_billing_id',
        'payment_method',
        'amount',
        'transaction_reference',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'payment_method' => PaymentMethod::class,
            'amount' => 'decimal:2',
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
}
