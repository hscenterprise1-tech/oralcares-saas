<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change somebody made to an attendance record, kept.
 *
 * A manager fixing a forgotten check-out is normal; doing it invisibly is not.
 * One row per field changed, with who did it and why.
 */
class CompanyAttendanceCorrection extends Model
{
    protected $table = 'company_attendance_corrections';

    protected $fillable = [
        'attendance_record_id', 'field', 'old_value', 'new_value', 'reason', 'corrected_by',
    ];

    public function record(): BelongsTo
    {
        return $this->belongsTo(CompanyAttendanceRecord::class, 'attendance_record_id');
    }

    public function correcter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }
}
