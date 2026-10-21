<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;

/**
 * A concession or write-off against an issued bill's balance, with its
 * reason. Written by `PaymentService::recordAdjustment()` only; the bill's
 * lines are never edited.
 */
class PatientBillingAdjustment extends Model
{
    use ClinicScoped;

    protected $fillable = ['patient_billing_id', 'clinic_id', 'branch_id', 'amount', 'reason', 'adjusted_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
