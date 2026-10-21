<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How an employee is paid from a date: monthly, daily, hourly or per
 * session, with an optional commission and per-session rate. A change is a
 * new row, so earlier pay stays explainable. Visible only with `payroll.view`.
 */
class ClinicSalaryStructure extends Model
{
    use ClinicScoped;

    public const TYPES = ['monthly', 'daily', 'hourly', 'per_session'];

    protected $fillable = ['clinic_id', 'clinic_employee_id', 'effective_from', 'salary_type', 'base_amount', 'commission_percent', 'per_session_rate', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'base_amount' => 'decimal:2', 'commission_percent' => 'decimal:2', 'per_session_rate' => 'decimal:2'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'clinic_employee_id');
    }
}
