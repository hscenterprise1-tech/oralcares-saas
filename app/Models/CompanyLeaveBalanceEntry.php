<?php

namespace App\Models;

use App\LeaveBalanceEntryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement in a leave balance.
 *
 * The ledger is the point. Accrual and carry-forward exist as recorded events
 * rather than as a formula, so the policy that eventually produces them can be
 * decided later without rewriting the balances that came before it.
 *
 * @property LeaveBalanceEntryType $entry_type
 */
class CompanyLeaveBalanceEntry extends Model
{
    protected $table = 'company_leave_balance_entries';

    protected $fillable = [
        'leave_balance_id', 'entry_type', 'days', 'effective_date',
        'reason', 'leave_request_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_type' => LeaveBalanceEntryType::class,
            'days' => 'decimal:2',
            'effective_date' => 'date',
        ];
    }

    public function balance(): BelongsTo
    {
        return $this->belongsTo(CompanyLeaveBalance::class, 'leave_balance_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(CompanyLeaveRequest::class, 'leave_request_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
