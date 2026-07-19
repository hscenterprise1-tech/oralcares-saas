<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientBillingRefund extends Model
{
    protected $fillable = [
        'patient_billing_id',
        'amount',
        'reason',
        'refunded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function billing(): BelongsTo
    {
        return $this->belongsTo(PatientBilling::class, 'patient_billing_id');
    }

    public function refunder(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'refunded_by');
    }
}
