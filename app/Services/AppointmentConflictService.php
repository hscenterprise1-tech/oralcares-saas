<?php

namespace App\Services;

use App\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\ClinicEmployee;
use App\Models\ClinicOperatingHour;
use App\Models\DoctorAvailability;
use App\Models\Leave;
use Carbon\Carbon;

/**
 * Purpose-built for `Appointment` rather than the existing
 * `ScheduleValidationService` — that service is centered on the `Resource`
 * model (chairs/operatories via the dead `resources`/`shifts`/
 * `resource_allocations` tables), which this project deliberately isn't
 * reviving for doctor scheduling (see `ClinicRoom`, already live, for the
 * room/chair side). This service reuses the same day-of-week convention
 * and `ClinicOperatingHour`/`Leave` read pattern `ScheduleValidationService`
 * already established, applied directly against `Appointment` instead.
 */
class AppointmentConflictService
{
    /**
     * Returns a human-readable reason the slot can't be booked, or null if
     * it's free. Checked in cheapest-first order so a closed clinic or a
     * doctor on leave never needs the appointment-overlap query at all.
     */
    public function checkConflict(
        Branch $branch,
        int $practitionerId,
        Carbon $start,
        int $durationMinutes,
        ?int $ignoreAppointmentId = null
    ): ?string {
        $end = $start->copy()->addMinutes($durationMinutes);

        if ($reason = $this->checkOperatingHours($branch, $start, $end)) {
            return $reason;
        }

        if ($this->isOnApprovedLeave($practitionerId, $start)) {
            return 'This doctor is on approved leave that day.';
        }

        if ($reason = $this->checkDoctorAvailability($practitionerId, $start, $end)) {
            return $reason;
        }

        if ($this->hasOverlappingAppointment($practitionerId, $start, $end, $ignoreAppointmentId)) {
            return 'This doctor already has another appointment at that time.';
        }

        return null;
    }

    private function checkOperatingHours(Branch $branch, Carbon $start, Carbon $end): ?string
    {
        // PHP's `date('w')` / Carbon's `dayOfWeek`, 0 = Sunday — the same
        // convention `ClinicOperatingHour` and the old (unwired)
        // `ScheduleValidationService::hasConflict()` already use.
        $operatingHour = ClinicOperatingHour::where('branch_id', $branch->id)
            ->where('day_of_week', $start->dayOfWeek)
            ->first();

        if (! $operatingHour) {
            return null; // No hours configured for this branch/day — nothing to enforce.
        }

        if ($operatingHour->is_closed) {
            return 'The clinic is closed on this day.';
        }

        if ($start->format('H:i:s') < $operatingHour->open_time || $end->format('H:i:s') > $operatingHour->close_time) {
            return 'This time is outside the clinic\'s operating hours.';
        }

        return null;
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
     * Only enforced when the doctor actually has availability rows for that
     * day of week — a doctor with no schedule configured yet is treated as
     * "not yet set up", not "always unavailable".
     */
    private function checkDoctorAvailability(int $practitionerId, Carbon $start, Carbon $end): ?string
    {
        $employee = ClinicEmployee::withoutGlobalScopes()->with('practitionerProfile')->find($practitionerId);
        $doctorProfileId = $employee?->practitionerProfile?->id;

        if (! $doctorProfileId) {
            return null;
        }

        // `DoctorAvailability.day_of_week` is 1=Monday..7=Sunday — different
        // from Carbon's 0=Sunday..6=Saturday used everywhere else here.
        $availabilityDayOfWeek = $start->dayOfWeek === 0 ? 7 : $start->dayOfWeek;

        $rowsForDay = DoctorAvailability::where('doctor_profile_id', $doctorProfileId)
            ->where('day_of_week', $availabilityDayOfWeek)
            ->get();

        if ($rowsForDay->isEmpty()) {
            return null;
        }

        $fitsAnAvailableWindow = $rowsForDay->contains(
            fn (DoctorAvailability $row) => $row->is_available
                && $start->format('H:i:s') >= $row->start_time
                && $end->format('H:i:s') <= $row->end_time
        );

        return $fitsAnAvailableWindow ? null : 'This doctor is not available at this time.';
    }

    /**
     * Overlap is computed in PHP against just that doctor's appointments for
     * that single day (a naturally small, bounded set) rather than in SQL —
     * `duration_minutes` has no end-time column to compare against
     * portably across MySQL and SQLite without a driver-specific expression.
     */
    private function hasOverlappingAppointment(int $practitionerId, Carbon $start, Carbon $end, ?int $ignoreAppointmentId): bool
    {
        return Appointment::withoutGlobalScopes()
            ->where('practitioner_id', $practitionerId)
            ->whereDate('scheduled_at', $start->toDateString())
            ->whereNotIn('status', [AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value])
            ->when($ignoreAppointmentId, fn ($q) => $q->where('id', '!=', $ignoreAppointmentId))
            ->get(['id', 'scheduled_at', 'duration_minutes'])
            ->contains(function (Appointment $existing) use ($start, $end) {
                $existingStart = $existing->scheduled_at;
                $existingEnd = $existingStart->copy()->addMinutes($existing->duration_minutes ?? 15);

                return $existingStart->lt($end) && $existingEnd->gt($start);
            });
    }
}
