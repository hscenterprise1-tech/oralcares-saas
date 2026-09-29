<?php

namespace App\Services\Reception;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\DoctorAvailability;
use App\Models\Leave;
use App\Support\ClinicClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Who can see patients at a branch right now, from real data only:
 * active employees with a practitioner profile, approved `Leave` for today,
 * and today's `DoctorAvailability` windows (the same sources
 * `AppointmentConflictService` enforces bookings against).
 *
 * `shift` is one of on_shift | off_shift | not_set | on_leave. "not_set"
 * means the clinic has not entered a schedule for this doctor today — shown
 * as such, never guessed as available or unavailable.
 */
class DoctorRoster
{
    public function __construct(protected PatientFlowConfigService $flowConfig) {}

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

        // `DoctorAvailability.day_of_week` is 1=Monday..7=Sunday.
        $windows = DoctorAvailability::whereIn('doctor_profile_id', $employees->pluck('practitionerProfile.id')->filter())
            ->where('day_of_week', $now->dayOfWeekIso)
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch->id))
            ->orderBy('start_time')
            ->get()
            ->groupBy('doctor_profile_id');

        return $employees->map(function (ClinicEmployee $employee) use ($onLeave, $windows, $now, $roomsEnabled, $branch) {
            [$shift, $label] = in_array($employee->id, $onLeave, true)
                ? ['on_leave', 'On leave today']
                : $this->shiftAt($windows->get($employee->practitionerProfile?->id, collect()), $now);

            $room = $roomsEnabled
                ? $employee->rooms->first(fn ($room) => $room->is_active && (int) $room->branch_id === $branch->id)?->name
                : null;

            return ['employee' => $employee, 'shift' => $shift, 'shift_label' => $label, 'room' => $room];
        });
    }

    /**
     * @param  Collection<int, DoctorAvailability>  $windows
     * @return array{0: string, 1: string}
     */
    private function shiftAt(Collection $windows, CarbonImmutable $now): array
    {
        if ($windows->isEmpty()) {
            return ['not_set', 'No shift set'];
        }

        $time = $now->format('H:i:s');
        $available = $windows->filter(fn (DoctorAvailability $window) => $window->is_available);

        $current = $available->first(fn (DoctorAvailability $window) => $time >= $window->start_time && $time < $window->end_time);
        if ($current) {
            return ['on_shift', 'Until '.substr($current->end_time, 0, 5)];
        }

        $next = $available->first(fn (DoctorAvailability $window) => $window->start_time > $time);

        return ['off_shift', $next ? 'Back at '.substr($next->start_time, 0, 5) : 'Done for today'];
    }
}
