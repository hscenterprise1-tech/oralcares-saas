<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One employee's pay for a period: its lines add up to gross, deductions and net. */
class ClinicPayrollEntry extends Model
{
    use ClinicScoped;

    protected $fillable = ['clinic_id', 'payroll_period_id', 'clinic_employee_id', 'salary_structure_id', 'salary_type', 'units', 'gross', 'deductions', 'net', 'status', 'payslip_number', 'paid_at', 'payment_method', 'reference'];

    protected function casts(): array
    {
        return ['units' => 'decimal:2', 'gross' => 'decimal:2', 'deductions' => 'decimal:2', 'net' => 'decimal:2', 'paid_at' => 'datetime'];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(ClinicPayrollPeriod::class, 'payroll_period_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'clinic_employee_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ClinicPayrollEntryLine::class, 'payroll_entry_id');
    }
}
