<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use App\Traits\InheritsBillOwnership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dated part of a bill's payment plan. Written by `BillInstalments` only.
 */
class PatientBillingInstalment extends Model
{
    use ClinicScoped, InheritsBillOwnership;

    protected $fillable = [
        'patient_billing_id',
        'clinic_id',
        'branch_id',
        'sequence',
        'due_on',
        'amount',
        'paid_amount',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    public function billing(): BelongsTo
    {
        return $this->belongsTo(PatientBilling::class, 'patient_billing_id');
    }

    /** What is still to pay on this instalment, in rupees. */
    public function remaining(): float
    {
        return max(0, round((float) $this->amount - (float) $this->paid_amount, 2));
    }
}
