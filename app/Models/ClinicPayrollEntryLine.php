<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A line of a pay entry: positive adds to pay, negative takes away. */
class ClinicPayrollEntryLine extends Model
{
    public const KINDS = ['base', 'session_pay', 'commission', 'incentive', 'allowance', 'deduction', 'unpaid_leave', 'advance_recovery'];

    /** Lines a person may add by hand; the rest are worked out. */
    public const MANUAL = ['incentive', 'allowance', 'deduction', 'advance_recovery'];

    protected $fillable = ['payroll_entry_id', 'clinic_id', 'kind', 'description', 'amount', 'automatic', 'salary_advance_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'automatic' => 'boolean'];
    }
}
