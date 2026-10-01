<?php

namespace App\Services;

use App\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\ClinicOperatingHour;
use App\Models\Leave;
use App\Models\PractitionerSchedule;
use App\Services\Appointments\AppointmentSettingsService;
use App\Services\Staff\PractitionerScheduleService;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * The one place a booking is checked: every create, move, resize and
 * recurring occurrence goes through `checkConflict()`, and the free-slot
 * search applies the same rules.
 *
 * Rules, cheapest first:
 *   holiday           a date the clinic marked closed (Appointment settings)
 *   branch hours      `clinic_operating_hours`; with none set, the clinic's
 *                     saved working days and day window, if it saved any
 *   break             the clinic's daily break
 *   leave             the provider's approved leave
 *   provider hours    the provider's `practitioner_schedules` at this branch
 *                     (a provider with none is "not set up", not unavailable)
 *   double booking    the provider's other live appointments, plus buffer
 *   room / chair      the room's other live appointments, plus buffer
 */
class AppointmentConflictService
{
    public function __construct(
        protected PractitionerScheduleService $schedules,
        protected AppointmentSettingsService $settings,
    ) {}

    /**
     * Returns a human-readable reason the slot can't be booked, or null if
     * it's free.
     */
    public function checkConflict(
        Branch $branch,
        int $practitionerId,
        CarbonInterface $start,
        int $durationMinutes,
        ?int $ignoreAppointmentId = null,
        ?int $roomId = null,
    ): ?string {
        $start = Carbon::instance($start);
        $end = $start->copy()->addMinutes($durationMinutes);
        $settings = $this->settings->resolve($branch->clinic);

        if ($end->toDateString() !== $start->toDateString() && $end->format('H:i:s') !== '00:00:00') {
            return 'An appointment must start and end on the same day.';
        }

        if (in_array($start->toDateString(), $settings['holidays'], true)) {
            return 'The clinic is closed for a holiday on this day.';
        }

        if ($reason = $this->checkOperatingHours($branch, $start, $end, $settings)) {
            return $reason;
        }

        if ($settings['break_start'] && $start->format('H:i') < $settings['break_end'] && $end->format('H:i') > $settings['break_start']) {
            return "This time overlaps the clinic's break ({$settings['break_start']}–{$settings['break_end']}).";
        }

        if ($this->isOnApprovedLeave($practitionerId, $start)) {
            return 'This provider is on approved leave that day.';
        }

        if ($reason = $this->checkDoctorAvailability($branch, $practitionerId, $start, $end)) {
            return $reason;
        }

        $buffer = $settings['buffer_minutes'];

        if ($this->overlapsLiveAppointment('practitioner_id', $practitionerId, $start, $end, $buffer, $ignoreAppointmentId)) {
            return 'This provider already has another appointment at that time.';
        }

        if ($roomId && $this->overlapsLiveAppointment('room_id', $roomId, $start, $end, $buffer, $ignoreAppointmentId)) {
            return 'This room is already booked at that time.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function checkOperatingHours(Branch $branch, Carbon $start, Carbon $end, array $settings): ?string
    {
        // 0 = Sunday, as `ClinicOperatingHour` stores it.
        $operatingHour = ClinicOperatingHour::where('branch_id', $branch->id)
            ->where('day_of_week', $start->dayOfWeek)
            ->first();

        if ($operatingHour) {
            if ($operatingHour->is_closed) {
                return 'The clinic is closed on this day.';
            }

            if ($start->format('H:i:s') < $operatingHour->open_time || $this->endClock($end) > $operatingHour->close_time) {
                return 'This time is outside the clinic\'s operating hours.';
            }

            return null;
        }

        // No hours for this branch and day: the clinic's own working days and
        // day window apply, but only once it has saved them.
        if ($branch->clinic?->appointment_config === null) {
            return null;
        }

        if (! in_array($start->dayOfWeek, $settings['working_days'], true)) {
            return 'The clinic is closed on this day.';
        }

        if ($start->format('H:i') < $settings['day_start'] || substr($this->endClock($end), 0, 5) > $settings['day_end']) {
            return 'This time is outside the clinic\'s working hours.';
        }

        return null;
    }

    /**
     * An end at midnight compares as the end of the day, not its start.
     */
    private function endClock(Carbon $end): string
    {
        return $end->format('H:i:s') === '00:00:00' ? '24:00:00' : $end->format('H:i:s');
    }

    private function isOnApprovedLeave(int $practitionerId, Carbon $start): bool
    {
        return Leave::where('employee_id', $practitionerId)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $start->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->exists();
    }

    /**
     * Only enforced when the provider has windows for that day of week at
     * this branch: a provider with no schedule yet is "not set up", not
     * "always unavailable".
     */
    private function checkDoctorAvailability(Branch $branch, int $practitionerId, Carbon $start, Carbon $end): ?string
    {
        $rowsForDay = $this->schedules
            ->windowsOn($branch->clinic, [$practitionerId], $start, $branch)
            ->get($practitionerId, collect());

        if ($rowsForDay->isEmpty()) {
            return null;
        }

        $endClock = $this->endClock($end);
        $fitsAnAvailableWindow = $rowsForDay->contains(
            fn (PractitionerSchedule $row) => $row->is_available
                && $start->format('H:i:s') >= $row->start_time
                && $endClock <= $row->end_time
        );

        if (! $fitsAnAvailableWindow) {
            return 'This provider is not working at this time.';
        }

        $blocked = $rowsForDay->contains(
            fn (PractitionerSchedule $row) => ! $row->is_available
                && $start->format('H:i:s') < $row->end_time
                && $endClock > $row->start_time
        );

        return $blocked ? 'This provider is unavailable at this time.' : null;
    }

    /**
     * Overlap is computed in PHP against that provider's (or room's)
     * appointments on that one day, a small bounded set read by index.
     * Cancelled and no-show appointments free their slot.
     */
    /**
     * Whether a room or chair is free for the time, with the clinic's buffer.
     */
    public function roomIsFree(Branch $branch, int $roomId, CarbonInterface $start, int $durationMinutes, ?int $ignoreAppointmentId = null): bool
    {
        $start = Carbon::instance($start);
        $buffer = $this->settings->resolve($branch->clinic)['buffer_minutes'];

        return ! $this->overlapsLiveAppointment('room_id', $roomId, $start, $start->copy()->addMinutes($durationMinutes), $buffer, $ignoreAppointmentId);
    }

    private function overlapsLiveAppointment(string $column, int $id, Carbon $start, Carbon $end, int $buffer, ?int $ignoreAppointmentId): bool
    {
        return Appointment::withoutGlobalScopes()
            ->where($column, $id)
            ->whereBetween('scheduled_at', [$start->copy()->startOfDay(), $start->copy()->endOfDay()])
            ->whereNotIn('status', [AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value])
            ->when($ignoreAppointmentId, fn ($q) => $q->where('id', '!=', $ignoreAppointmentId))
            ->get(['id', 'scheduled_at', 'duration_minutes'])
            ->contains(function (Appointment $existing) use ($start, $end, $buffer) {
                $existingStart = $existing->scheduled_at->copy()->subMinutes($buffer);
                $existingEnd = $existing->scheduled_at->copy()->addMinutes(($existing->duration_minutes ?? 15) + $buffer);

                return $existingStart->lt($end) && $existingEnd->gt($start);
            });
    }
}
