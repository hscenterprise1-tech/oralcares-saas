<?php

namespace App\Services\Reception;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\Leave;
use App\Models\PractitionerSchedule;
use App\Services\Staff\PractitionerScheduleService;
use App\Support\ClinicClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Who can see patients at a branch right now, from real data only:
 * active employees with a practitioner profile, approved `Leave` for today,
 * and today's `PractitionerSchedule` windows at this branch (the same sources
 * `AppointmentConflictService` enforces bookings against).
 *
 * `shift` is one of on_shift | off_shift | not_set | on_leave. "not_set"
 * means the clinic has not entered a schedule for this doctor today — shown
 * as such, never guessed as available or unavailable.
 */
class DoctorRoster
{
    public function __construct(
        protected PatientFlowConfigService $flowConfig,
        protected PractitionerScheduleService $schedules,
    ) {}

    /**
     * @return Collection<int, array{employee: ClinicEmployee, shift: string, shift_label: string, room: string|null}>
     */
    public function forBranch(Clinic $clinic, Branch $branch, ?CarbonImmutable $now = null): Collection
    {
        $now ??= ClinicClock::now();
        $roomsEnabled = $this->flowConfig->roomsEnabled($clinic);

        $employees = ClinicEmployee::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->where('status', 'active')
            ->whereHas('practitionerProfile')
            ->with(['practitionerProfile', 'branches:id', ...($roomsEnabled ? ['rooms'] : [])])
            ->orderBy('name')
            ->get()
            ->filter(fn (ClinicEmployee $employee) => $employee->branches->isEmpty() || $employee->branches->contains('id', $branch->id))
            ->values();

        $onLeave = Leave::whereIn('employee_id', $employees->pluck('id'))
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $now->toDateString())
            ->whereDate('end_date', '>=', $now->toDateString())
            ->pluck('employee_id')
            ->all();

        $windows = $this->schedules->windowsOn($clinic, $employees->pluck('id'), $now, $branch);

        return $employees->map(function (ClinicEmployee $employee) use ($onLeave, $windows, $now, $roomsEnabled, $branch) {
            [$shift, $label] = in_array($employee->id, $onLeave, true)
                ? ['on_leave', 'On leave today']
                : $this->shiftAt($windows->get($employee->id, collect()), $now);

            $room = $roomsEnabled
                ? $employee->rooms->first(fn ($room) => $room->is_active && (int) $room->branch_id === $branch->id)?->name
                : null;

            return ['employee' => $employee, 'shift' => $shift, 'shift_label' => $label, 'room' => $room];
        });
    }

    /**
     * @param  Collection<int, PractitionerSchedule>  $windows
     * @return array{0: string, 1: string}
     */
    private function shiftAt(Collection $windows, CarbonImmutable $now): array
    {
        if ($windows->isEmpty()) {
            return ['not_set', 'No shift set'];
        }

        $time = $now->format('H:i:s');
        $available = $windows->filter(fn (PractitionerSchedule $window) => $window->is_available);

        $current = $available->first(fn (PractitionerSchedule $window) => $time >= $window->start_time && $time < $window->end_time);
        if ($current) {
            return ['on_shift', 'Until '.substr($current->end_time, 0, 5)];
        }

        $next = $available->first(fn (PractitionerSchedule $window) => $window->start_time > $time);

        return ['off_shift', $next ? 'Back at '.substr($next->start_time, 0, 5) : 'Done for today'];
    }
}
