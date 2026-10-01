<?php

namespace App\Services\Staff;

use App\Models\Branch;
use App\Models\ClinicEmployee;
use App\Models\PractitionerSchedule;
use App\Services\Security\SecurityAudit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reads and saves a practitioner's weekly working hours at one branch.
 *
 * A day is a list of shifts ("09:00–13:00, 15:00–19:00"); the gaps between
 * shifts are the breaks and a day with no shifts is a day off. Saving
 * replaces the open-ended weekly pattern for that branch only; dated
 * windows (effective from/until) are left alone. The appointment engine
 * reads these rows through `PractitionerScheduleService`.
 */
class PractitionerScheduleEditor
{
    public function __construct(protected SecurityAudit $audit) {}

    /**
     * Shifts per branch and weekday (0 = Sunday).
     *
     * @return array<int, array<int, list<array{start: string, end: string}>>>
     */
    public function weekly(ClinicEmployee $employee): array
    {
        return PractitionerSchedule::withoutGlobalScopes()
            ->where('clinic_id', $employee->clinic_id)
            ->where('clinic_employee_id', $employee->id)
            ->where('is_available', true)
            ->whereNull('effective_from')
            ->whereNull('effective_until')
            ->orderBy('start_time')
            ->get(['branch_id', 'day_of_week', 'start_time', 'end_time'])
            ->groupBy('branch_id')
            ->map(fn (Collection $rows) => $rows->groupBy('day_of_week')->map(fn (Collection $day) => $day->map(fn ($row) => [
                'start' => substr($row->start_time, 0, 5),
                'end' => substr($row->end_time, 0, 5),
            ])->values()->all())->all())
            ->all();
    }

    /**
     * @param  array<int|string, list<array{start: string, end: string}>>  $days  weekday => shifts
     */
    public function save(ClinicEmployee $employee, Branch $branch, array $days): void
    {
        $rows = [];

        foreach ($days as $day => $shifts) {
            $day = (int) $day;
            if ($day < 0 || $day > 6) {
                throw ValidationException::withMessages(['days' => 'Unknown weekday.']);
            }

            $sorted = collect($shifts)->sortBy('start')->values();
            foreach ($sorted as $index => $shift) {
                if ($shift['end'] <= $shift['start']) {
                    throw ValidationException::withMessages(["days.{$day}" => 'Each shift must end after it starts.']);
                }
                if ($index > 0 && $shift['start'] < $sorted[$index - 1]['end']) {
                    throw ValidationException::withMessages(["days.{$day}" => 'Shifts on the same day must not overlap.']);
                }
                $rows[] = [
                    'clinic_id' => $employee->clinic_id,
                    'branch_id' => $branch->id,
                    'clinic_employee_id' => $employee->id,
                    'day_of_week' => $day,
                    'start_time' => $shift['start'].':00',
                    'end_time' => $shift['end'].':00',
                    'is_available' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        DB::transaction(function () use ($employee, $branch, $rows) {
            PractitionerSchedule::withoutGlobalScopes()
                ->where('clinic_id', $employee->clinic_id)
                ->where('clinic_employee_id', $employee->id)
                ->where('branch_id', $branch->id)
                ->whereNull('effective_from')
                ->whereNull('effective_until')
                ->delete();

            if ($rows !== []) {
                PractitionerSchedule::withoutGlobalScopes()->insert($rows);
            }
        });

        $this->audit->record('practitioner.schedule_updated', $employee, ['branch_id' => $branch->id, 'shifts' => count($rows)]);
    }
}
