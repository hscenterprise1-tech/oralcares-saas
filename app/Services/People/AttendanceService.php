<?php

namespace App\Services\People;

use App\AttendanceSource;
use App\AttendanceStatus;
use App\LeaveRequestStatus;
use App\Models\CompanyAttendanceCorrection;
use App\Models\CompanyAttendanceRecord;
use App\Models\CompanyEmployee;
use App\Models\CompanyLeaveRequestDay;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Recording who was at work.
 *
 * Two things carry this class.
 *
 * The first is timezone. A working day belongs to the *employee's* calendar,
 * not the server's: a check-in at 23:40 in Kolkata and one at 19:10 in London
 * are the same instant on different dates. Every date here is resolved in the
 * employee's own timezone, and the timezone in force is snapshotted onto the
 * record so moving somebody between offices later cannot re-date their history.
 *
 * The second is that nothing is inferred. There is no grace period, no expected
 * start time, no overtime and no deduction — none of that is established
 * anywhere in this system, and a constant in this file would quietly become
 * company policy. What is stored is what somebody recorded.
 */
class AttendanceService
{
    public function __construct(protected WorkingCalendarService $calendar) {}

    /** The employee's local date for an instant, defaulting to now. */
    public function localDate(CompanyEmployee $employee, ?Carbon $at = null): Carbon
    {
        return ($at ?? now())->copy()->setTimezone($employee->resolvedTimezone())->startOfDay();
    }

    /**
     * Start someone's day.
     *
     * Idempotent on the day: checking in twice keeps the first arrival, because
     * the second is a duplicate click far more often than it is a correction —
     * and a real correction goes through `correct()`, which leaves a trail.
     */
    public function checkIn(CompanyEmployee $employee, ?User $actor = null, ?Carbon $at = null, ?AttendanceSource $source = null): CompanyAttendanceRecord
    {
        $moment = $at ?? now();
        $workDate = $this->localDate($employee, $moment);

        $record = $this->recordFor($employee, $workDate);

        if ($record->check_in_at !== null) {
            return $record;
        }

        $record->fill([
            'check_in_at' => $moment,
            'status' => AttendanceStatus::Present,
            'timezone' => $employee->resolvedTimezone(),
            'source' => $source ?? AttendanceSource::Employee,
            'recorded_by' => $actor?->id,
        ])->save();

        return $record->fresh();
    }

    /**
     * End someone's day.
     *
     * Refuses a check-out with no check-in rather than inventing an arrival
     * time to subtract from.
     */
    public function checkOut(CompanyEmployee $employee, ?User $actor = null, ?Carbon $at = null): CompanyAttendanceRecord
    {
        $moment = $at ?? now();
        $workDate = $this->localDate($employee, $moment);

        $record = CompanyAttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $workDate->toDateString())
            ->first();

        if (! $record || $record->check_in_at === null) {
            throw new InvalidArgumentException('There is no check-in on this day to check out from.');
        }

        if ($moment->lessThan($record->check_in_at)) {
            throw new InvalidArgumentException('A check-out cannot be earlier than the check-in.');
        }

        $record->fill([
            'check_out_at' => $moment,
            'worked_minutes' => (int) round($record->check_in_at->diffInMinutes($moment)),
            'recorded_by' => $actor?->id ?? $record->recorded_by,
        ])->save();

        return $record->fresh();
    }

    /**
     * Mark a day directly, without timestamps.
     *
     * How a manager or HR records an absence, and the only way a day becomes
     * anything other than what the employee logged themselves.
     */
    public function mark(
        CompanyEmployee $employee,
        Carbon $workDate,
        AttendanceStatus $status,
        AttendanceSource $source,
        ?User $actor = null,
        ?string $notes = null,
    ): CompanyAttendanceRecord {
        $record = $this->recordFor($employee, $workDate);

        $record->fill([
            'status' => $status,
            'source' => $source,
            'notes' => $notes,
            'timezone' => $record->timezone ?: $employee->resolvedTimezone(),
            'recorded_by' => $actor?->id,
        ])->save();

        return $record->fresh();
    }

    /**
     * Change a record that already exists, and say why.
     *
     * Every changed field becomes its own correction row. A manager fixing a
     * forgotten check-out is ordinary; doing it invisibly is not.
     *
     * @param  array<string, mixed>  $changes
     */
    public function correct(CompanyAttendanceRecord $record, array $changes, string $reason, ?User $actor = null): CompanyAttendanceRecord
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A correction needs a reason.');
        }

        return DB::transaction(function () use ($record, $changes, $reason, $actor) {
            foreach ($changes as $field => $new) {
                $old = $record->getAttribute($field);

                $oldValue = $old instanceof Carbon ? $old->toIso8601String() : ($old?->value ?? $old);
                $newValue = $new instanceof Carbon ? $new->toIso8601String() : ($new?->value ?? $new);

                if ((string) $oldValue === (string) $newValue) {
                    continue;
                }

                $record->setAttribute($field, $new);

                CompanyAttendanceCorrection::create([
                    'attendance_record_id' => $record->id,
                    'field' => $field,
                    'old_value' => $oldValue === null ? null : (string) $oldValue,
                    'new_value' => $newValue === null ? null : (string) $newValue,
                    'reason' => $reason,
                    'corrected_by' => $actor?->id,
                ]);
            }

            /* Worked time follows the timestamps rather than being carried over. */
            if ($record->check_in_at && $record->check_out_at) {
                $record->worked_minutes = (int) round($record->check_in_at->diffInMinutes($record->check_out_at));
            }

            $record->save();

            return $record->fresh('corrections');
        });
    }

    /**
     * One employee's month, day by day.
     *
     * Days with no record are still returned — an empty day is a fact, and a
     * month that silently skipped them would read as though nothing was ever
     * missed. Holidays and weekly offs come from the calendar; where the
     * calendar is unconfigured they are simply absent rather than guessed.
     *
     * @return array<string, mixed>
     */
    public function monthlySummary(CompanyEmployee $employee, int $year, int $month): array
    {
        $timezone = $employee->resolvedTimezone();
        $start = Carbon::create($year, $month, 1, 0, 0, 0, $timezone)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $records = CompanyAttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn (CompanyAttendanceRecord $record) => $record->work_date->toDateString());

        $leaveDays = CompanyLeaveRequestDay::query()
            ->whereBetween('leave_date', [$start->toDateString(), $end->toDateString()])
            ->whereHas('request', fn ($query) => $query
                ->where('employee_id', $employee->id)
                ->where('status', LeaveRequestStatus::Approved->value))
            ->get()
            ->keyBy(fn (CompanyLeaveRequestDay $day) => $day->leave_date->toDateString());

        $holidays = $this->calendar->holidaysBetween($start, $end);

        $days = [];
        $counts = ['present' => 0, 'absent' => 0, 'partial' => 0, 'on_leave' => 0, 'holiday' => 0, 'weekly_off' => 0, 'unrecorded' => 0];
        $minutes = 0;

        for ($date = $start->copy(); $date->lessThanOrEqualTo($end); $date->addDay()) {
            $key = $date->toDateString();
            $record = $records->get($key);
            $isWorking = $this->calendar->isWorkingDay($date);

            /*
             * Precedence, and why: a recorded day wins because a person said
             * so; approved leave next, because it was approved before the day
             * arrived; then the calendar. Nothing is inferred beyond that — an
             * unrecorded working day is "unrecorded", not "absent".
             */
            $status = match (true) {
                $record !== null => $record->status,
                $leaveDays->has($key) => AttendanceStatus::OnLeave,
                $holidays->has($key) => AttendanceStatus::Holiday,
                $isWorking === false => AttendanceStatus::WeeklyOff,
                default => null,
            };

            $counts[$status?->value ?? 'unrecorded']++;
            $minutes += (int) ($record?->worked_minutes ?? 0);

            $days[] = [
                'date' => $key,
                'day_of_week' => $date->dayOfWeek,
                'status' => $status?->value,
                'status_label' => $status?->label() ?? 'Not recorded',
                'check_in_at' => $record?->check_in_at?->setTimezone($timezone)->format('H:i'),
                'check_out_at' => $record?->check_out_at?->setTimezone($timezone)->format('H:i'),
                'worked_minutes' => $record?->worked_minutes,
                'source' => $record?->source->value,
                'notes' => $record?->notes,
                'holiday' => $holidays->get($key)?->name,
                'leave_portion' => $leaveDays->get($key)?->portion->value,
                'has_corrections' => $record ? $record->corrections()->exists() : false,
                'record_id' => $record?->id,
            ];
        }

        return [
            'year' => $year,
            'month' => $month,
            'label' => $start->format('F Y'),
            'timezone' => $timezone,
            'calendar_configured' => $this->calendar->isConfigured(),
            'days' => $days,
            'counts' => $counts,
            'worked_minutes' => $minutes,
        ];
    }

    /**
     * Today across the whole company.
     *
     * @return array<string, mixed>
     */
    public function dailySummary(?Carbon $date = null): array
    {
        $date = ($date ?? now())->copy()->startOfDay();

        $records = CompanyAttendanceRecord::query()
            ->with(['employee.user:id,name', 'employee.department:id,name'])
            ->whereDate('work_date', $date->toDateString())
            ->get();

        $headcount = CompanyEmployee::query()->count();
        $counts = [];

        foreach (AttendanceStatus::cases() as $status) {
            $counts[$status->value] = $records->where('status', $status)->count();
        }

        return [
            'date' => $date->toDateString(),
            'label' => $date->format('D, j M Y'),
            'headcount' => $headcount,
            'recorded' => $records->count(),
            'unrecorded' => max(0, $headcount - $records->count()),
            'counts' => $counts,
            'checked_in' => $records->filter(fn (CompanyAttendanceRecord $r) => $r->isOpen())->count(),
            'holiday' => $this->calendar->holidayOn($date)?->name,
            'is_working_day' => $this->calendar->isWorkingDay($date),
            'calendar_configured' => $this->calendar->isConfigured(),
        ];
    }

    /**
     * Find or start the day's row without saving a half-built one.
     *
     * The lookup uses `whereDate` rather than matching the attribute: the
     * column is cast to a date, so a plain equality on a `Y-m-d` string binds a
     * full timestamp, misses the existing row and creates a second one — which
     * the unique index on (employee, date) then refuses.
     */
    protected function recordFor(CompanyEmployee $employee, Carbon $workDate): CompanyAttendanceRecord
    {
        $existing = CompanyAttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $workDate->toDateString())
            ->first();

        return $existing ?? new CompanyAttendanceRecord([
            'employee_id' => $employee->id,
            'work_date' => $workDate->toDateString(),
        ]);
    }
}
