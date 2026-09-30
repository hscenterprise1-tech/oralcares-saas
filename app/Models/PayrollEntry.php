<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One employee's pay for one run — a snapshot taken at calculation.
 *
 * The name, code and every figure are copied rather than joined, so a later
 * raise, rename or component change cannot rewrite what a closed run says.
 */
class PayrollEntry extends Model
{
    protected $table = 'company_payroll_entries';

    protected $fillable = [
        'period_id', 'employee_id', 'compensation_id',
        'employee_code', 'employee_name',
        'base_salary', 'gross_amount', 'deduction_amount', 'net_amount',
    ];

    protected function casts(): array
    {
        return [
            'base_salary' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'deduction_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'period_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(CompanyEmployee::class, 'employee_id');
    }

    /**
     * The snapshotted lines, in the order they were written.
     *
     * Ordered explicitly: left to the database, the index on (entry_id, type)
     * decides, and a payslip lists its deductions above the salary they come
     * out of.
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PayrollEntryLine::class, 'entry_id')->orderBy('id');
    }
}
