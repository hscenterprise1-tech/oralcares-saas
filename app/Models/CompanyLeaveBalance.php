<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What one person has, and has taken, of one leave type in one year.
 *
 * Every column here is the sum of recorded entries — nothing accrues on its
 * own. No rate, frequency or cap is encoded anywhere, because none has been
 * decided; when one is, it writes entries and these totals follow.
 */
class CompanyLeaveBalance extends Model
{
    protected $table = 'company_leave_balances';

    protected $fillable = [
        'employee_id', 'leave_type_id', 'period_year',
        'opening_balance', 'accrued', 'carried_forward', 'adjusted', 'used',
    ];

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'opening_balance' => 'decimal:2',
            'accrued' => 'decimal:2',
            'carried_forward' => 'decimal:2',
            'adjusted' => 'decimal:2',
            'used' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(CompanyEmployee::class, 'employee_id');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(CompanyLeaveType::class, 'leave_type_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CompanyLeaveBalanceEntry::class, 'leave_balance_id')->orderBy('id');
    }

    /** Everything granted, before anything was taken. */
    public function entitled(): float
    {
        return round(
            (float) $this->opening_balance
            + (float) $this->accrued
            + (float) $this->carried_forward
            + (float) $this->adjusted,
            2
        );
    }

    /**
     * What is left.
     *
     * Allowed to go negative. Refusing a request that exceeds the balance would
     * be a policy — some companies allow it, some do not — and nothing here has
     * decided which. An overdrawn balance is reported, not prevented.
     */
    public function available(): float
    {
        return round($this->entitled() - (float) $this->used, 2);
    }
}
