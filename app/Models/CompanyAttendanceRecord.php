<?php

namespace App\Models;

use App\AttendanceSource;
use App\AttendanceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One person, one day.
 *
 * `work_date` is the employee's *local* calendar day, not the server's. A
 * check-in at 23:40 in Kolkata and one at 19:10 in London are the same instant
 * on different dates, so the date is resolved in their own timezone and the
 * timezone in force is snapshotted alongside it — moving somebody between
 * offices later must not silently re-date everything they ever logged.
 *
 * @property AttendanceStatus $status
 * @property AttendanceSource $source
 */
class CompanyAttendanceRecord extends Model
{
    protected $table = 'company_attendance_records';

    protected $fillable = [
        'employee_id', 'work_date', 'status', 'check_in_at', 'check_out_at',
        'timezone', 'worked_minutes', 'source', 'notes', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'status' => AttendanceStatus::class,
            'source' => AttendanceSource::class,
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'worked_minutes' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(CompanyEmployee::class, 'employee_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** Every correction made to this day, oldest first. */
    public function corrections(): HasMany
    {
        return $this->hasMany(CompanyAttendanceCorrection::class, 'attendance_record_id')->orderBy('id');
    }

    /** @param  Builder<self>  $query */
    public function scopeBetween(Builder $query, string $from, string $to): void
    {
        $query->whereBetween('work_date', [$from, $to]);
    }

    /** Whether somebody is checked in and has not checked out. */
    public function isOpen(): bool
    {
        return $this->check_in_at !== null && $this->check_out_at === null;
    }
}
