<?php

namespace App\Models;

use App\PayrollPeriodStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A payroll run, and the trail of who moved it.
 *
 * Replaces the old arrangement where a month string was the entire workflow and
 * writing a row meant paying it. Each transition stamps an actor and a time,
 * because the question an audit asks is not what was paid but who decided it.
 */
class PayrollPeriod extends Model
{
    protected $table = 'company_payroll_periods';

    protected $fillable = [
        'code', 'label', 'period_start', 'period_end', 'status',
        'total_gross', 'total_deductions', 'total_net', 'entry_count',
        'created_by', 'calculated_at', 'calculated_by', 'submitted_at', 'submitted_by',
        'approved_at', 'approved_by', 'locked_at', 'locked_by', 'paid_at', 'paid_by',
        'payment_reference', 'payment_notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => PayrollPeriodStatus::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'total_gross' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'total_net' => 'decimal:2',
            'entry_count' => 'integer',
            'calculated_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'locked_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class, 'period_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Whether this run's money may still change. */
    public function isMutable(): bool
    {
        return $this->status->isMutable();
    }
}
