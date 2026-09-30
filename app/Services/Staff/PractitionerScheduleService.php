<?php

namespace App\Services\Staff;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\PractitionerSchedule;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Reads practitioner schedules: the one source for "when does this doctor
 * work at this branch". One query for any number of doctors, so rosters and
 * calendars never fetch schedules doctor by doctor.
 */
class PractitionerScheduleService
{
    /**
     * The windows on the given date for each staff member, keyed by
     * `clinic_employees.id` and ordered by start time. Windows at other
     * branches are left out when a branch is given.
     *
     * @param  iterable<int>  $employeeIds
     * @return Collection<int, Collection<int, PractitionerSchedule>>
     */
    public function windowsOn(Clinic $clinic, iterable $employeeIds, CarbonInterface $date, ?Branch $branch = null): Collection
    {
        $ids = collect($employeeIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $day = $date->toDateString();

        // The clinic is filtered explicitly, so this also works outside a
        // web request (queue jobs, console) where the tenant scope is off.
        return PractitionerSchedule::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->whereIn('clinic_employee_id', $ids)
            ->where('day_of_week', $date->dayOfWeek)
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $day))
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $day))
            ->orderBy('start_time')
            ->get()
            ->groupBy('clinic_employee_id');
    }
}
