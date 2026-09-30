<?php

namespace App\Models;

use App\LeaveRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Somebody asking for time off.
 *
 * The request carries the totals; `days` carries the shape of it, one row per
 * calendar day. That split is what lets a week off be four full days and a half
 * without the total becoming a lie.
 *
 * @property LeaveRequestStatus $status
 */
class CompanyLeaveRequest extends Model
{
    protected $table = 'company_leave_requests';

    protected $fillable = [
        'employee_id', 'leave_type_id', 'start_date', 'end_date',
        'total_days', 'total_part_day_hours', 'reason', 'status', 'submitted_at',
        'decided_by', 'decided_at', 'decided_via', 'decision_note',
        'cancelled_by', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'total_days' => 'decimal:2',
            'total_part_day_hours' => 'decimal:2',
            'status' => LeaveRequestStatus::class,
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'cancelled_at' => 'datetime',
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

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** The day-by-day breakdown, in date order. */
    public function days(): HasMany
    {
        return $this->hasMany(CompanyLeaveRequestDay::class, 'leave_request_id')->orderBy('leave_date');
    }

    /** Every balance movement this request caused. */
    public function balanceEntries(): HasMany
    {
        return $this->hasMany(CompanyLeaveBalanceEntry::class, 'leave_request_id');
    }

    /** @param  Builder<self>  $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', LeaveRequestStatus::Pending->value);
    }

    /** The calendar year the balance for this request belongs to. */
    public function periodYear(): int
    {
        return (int) $this->start_date->format('Y');
    }
}
