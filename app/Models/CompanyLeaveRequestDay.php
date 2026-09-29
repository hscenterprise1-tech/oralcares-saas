<?php

namespace App\Models;

use App\LeaveDayPortion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One calendar day of a leave request.
 *
 * `day_value` is what the balance reads: 1 for a full day, 0.5 for a half, and
 * **0 for a part day** — hours are recorded instead, because converting them
 * into a fraction of a day needs a standard working day that nothing in this
 * system establishes.
 *
 * @property LeaveDayPortion $portion
 */
class CompanyLeaveRequestDay extends Model
{
    protected $table = 'company_leave_request_days';

    protected $fillable = ['leave_request_id', 'leave_date', 'portion', 'half_period', 'hours', 'day_value'];

    protected function casts(): array
    {
        return [
            'leave_date' => 'date',
            'portion' => LeaveDayPortion::class,
            'hours' => 'decimal:2',
            'day_value' => 'decimal:2',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(CompanyLeaveRequest::class, 'leave_request_id');
    }
}
