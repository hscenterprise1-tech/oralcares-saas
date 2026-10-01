<?php

namespace App\Services\Appointments;

use App\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicOperatingHour;
use App\Models\Leave;
use App\Models\PractitionerSchedule;
use App\Support\ClinicClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * When providers can be booked at a branch, computed on the server from real
 * data: branch hours and holidays, the clinic's break, each provider's
 * schedule and approved leave, and the appointments already booked.
 *
 * Every method reads a bounded date range with a fixed number of queries,
 * whatever the number of providers: hours (one), schedules (one), leave
 * (one) and appointments (one, plus one for a room). The rules match
 * `AppointmentConflictService`, which still re-checks every booking.
 *
 * Times are clinic wall-clock values, as appointments store them.
 */
class AppointmentAvailabilityService
{
    /** The longest range a calendar view may load (a 6-week month grid). */
    public const MAX_RANGE_DAYS = 42;

    /** The furthest ahead "find next available" looks. */
    public const MAX_SEARCH_DAYS = 31;

    public function __construct(
        protected AppointmentSettingsService $settings,
        protected AppointmentResourceResolver $resources,
    ) {}

    /**
     * The providers bookable at this branch: active staff with a practitioner
     * profile, placed at the branch (no placement means every branch).
     *
     * @return Collection<int, ClinicEmployee>
     */
    public function providers(Clinic $clinic, Branch $branch): Collection
    {
        return ClinicEmployee::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->where('status', 'active')
            ->whereHas('practitionerProfile', fn ($query) => $query->withoutGlobalScopes())
            ->where(fn ($query) => $query
                ->whereDoesntHave('branches')
                ->orWhereHas('branches', fn ($branches) => $branches->whereKey($branch->id)))
            ->with(['practitionerProfile' => fn ($query) => $query->withoutGlobalScopes()])
            ->orderBy('name')
            ->limit(200)
            ->get();
    }

    /**
     * Whether the branch is open on each date, and between which times.
     *
     * @return array<string, array{open: bool, reason: string|null, start: string, end: string, enforced: bool}>
     */
    public function branchDays(Clinic $clinic, Branch $branch, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $settings = $this->settings->resolve($clinic);
        $hours = ClinicOperatingHour::where('branch_id', $branch->id)->get()->keyBy('day_of_week');
        $configured = $clinic->appointment_config !== null;
        $days = [];

        foreach ($this->dates($from, $to) as $date) {
            $key = $date->toDateString();
            $row = $hours->get($date->dayOfWeek);

            $days[$key] = match (true) {
                in_array($key, $settings['holidays'], true) => ['open' => false, 'reason' => 'Holiday', 'start' => $settings['day_start'], 'end' => $settings['day_end'], 'enforced' => true],
                $row !== null && $row->is_closed => ['open' => false, 'reason' => 'Closed', 'start' => $settings['day_start'], 'end' => $settings['day_end'], 'enforced' => true],
                $row !== null => ['open' => true, 'reason' => null, 'start' => substr($row->open_time, 0, 5), 'end' => substr($row->close_time, 0, 5), 'enforced' => true],
                ! in_array($date->dayOfWeek, $settings['working_days'], true) => ['open' => false, 'reason' => 'Closed', 'start' => $settings['day_start'], 'end' => $settings['day_end'], 'enforced' => $configured],
                default => ['open' => true, 'reason' => null, 'start' => $settings['day_start'], 'end' => $settings['day_end'], 'enforced' => $configured],
            };
        }

        return $days;
    }

    /**
     * Each provider's working windows on each date, as minutes from midnight,
     * with the clinic's break taken out.
     *
     * `state` is working | leave | off | closed. `scheduled` says whether the
     * windows come from the provider's own schedule or, with none set, from
     * the branch's day.
     *
     * @param  iterable<int>  $providerIds
     * @return array<string, array<int, array{state: string, scheduled: bool, windows: list<array{0: int, 1: int}>}>>
     */
    public function providerPlans(Clinic $clinic, Branch $branch, iterable $providerIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = collect($providerIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $settings = $this->settings->resolve($clinic);
        $days = $this->branchDays($clinic, $branch, $from, $to);

        $schedules = PractitionerSchedule::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->where('branch_id', $branch->id)
            ->whereIn('clinic_employee_id', $ids)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $to->toDateString()))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $from->toDateString()))
            ->orderBy('start_time')
            ->get()
            ->groupBy('clinic_employee_id');

        $leaves = Leave::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->whereIn('employee_id', $ids)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->get(['employee_id', 'start_date', 'end_date'])
            ->groupBy('employee_id');

        $break = $settings['break_start'] ? [$this->minutes($settings['break_start']), $this->minutes($settings['break_end'])] : null;
        $plans = [];

        foreach ($days as $key => $day) {
            $date = CarbonImmutable::parse($key);

            foreach ($ids as $id) {
                if (! $day['open']) {
                    $plans[$key][$id] = ['state' => 'closed', 'scheduled' => false, 'windows' => []];

                    continue;
                }

                $onLeave = $leaves->get($id, collect())->contains(
                    fn (Leave $leave) => $leave->start_date->toDateString() <= $key && $leave->end_date->toDateString() >= $key
                );
                if ($onLeave) {
                    $plans[$key][$id] = ['state' => 'leave', 'scheduled' => false, 'windows' => []];

                    continue;
                }

                $rows = $schedules->get($id, collect())->filter(fn (PractitionerSchedule $row) => (int) $row->day_of_week === $date->dayOfWeek
                    && ($row->effective_from === null || $row->effective_from->toDateString() <= $key)
                    && ($row->effective_until === null || $row->effective_until->toDateString() >= $key));

                $branchWindow = [[$this->minutes($day['start']), $this->minutes($day['end'])]];

                if ($rows->isEmpty()) {
                    $windows = $branchWindow;
                    $scheduled = false;
                } else {
                    $windows = $rows->where('is_available', true)
                        ->map(fn (PractitionerSchedule $row) => [$this->minutes($row->start_time), $this->minutes($row->end_time)])
                        ->values()->all();
                    foreach ($rows->where('is_available', false) as $blocked) {
                        $windows = $this->subtract($windows, [$this->minutes($blocked->start_time), $this->minutes($blocked->end_time)]);
                    }
                    $windows = $this->intersect($windows, $branchWindow[0]);
                    $scheduled = true;
                }

                if ($break) {
                    $windows = $this->subtract($windows, $break);
                }

                $plans[$key][$id] = ['state' => $windows === [] ? 'off' : 'working', 'scheduled' => $scheduled, 'windows' => $windows];
            }
        }

        return $plans;
    }

    /**
     * Bookable start times, earliest first, for any of the given providers.
     * A provider with assigned rooms is only free when one of them is too.
     * `$ignoreAppointmentId` leaves out the appointment being moved.
     *
     * @param  list<int>  $providerIds
     * @return list<array{provider_id: int, start: string, end: string}>
     */
    public function freeSlots(
        Clinic $clinic,
        Branch $branch,
        array $providerIds,
        CarbonImmutable $from,
        int $days,
        int $durationMinutes,
        int $limit = 24,
        ?int $perProvider = null,
        ?string $period = null,
        ?int $ignoreAppointmentId = null,
    ): array {
        if ($providerIds === [] || $durationMinutes < 5) {
            return [];
        }

        $settings = $this->settings->resolve($clinic);
        $days = max(1, min($days, self::MAX_SEARCH_DAYS));
        $from = $from->startOfDay();
        $to = $from->addDays($days - 1);
        $now = ClinicClock::now();
        $step = $settings['slot_minutes'];
        $buffer = $settings['buffer_minutes'];

        $plans = $this->providerPlans($clinic, $branch, $providerIds, $from, $to);
        $busy = $this->busyIntervals('practitioner_id', $providerIds, $from, $to, $buffer, $ignoreAppointmentId);
        $providerRooms = $this->resources->roomsFor($clinic, $branch, $providerIds);
        $roomIds = array_values(array_unique(array_merge([], ...array_values($providerRooms))));
        $roomBusy = $roomIds === [] ? [] : $this->busyIntervals('room_id', $roomIds, $from, $to, $buffer, $ignoreAppointmentId);
        [$periodStart, $periodEnd] = match ($period) {
            'morning' => [0, 12 * 60],
            'afternoon' => [12 * 60, 17 * 60],
            'evening' => [17 * 60, 24 * 60],
            default => [0, 24 * 60],
        };

        $slots = [];
        $found = [];

        foreach ($plans as $key => $byProvider) {
            $date = CarbonImmutable::parse($key);
            $earliest = $key === $now->toDateString() ? (int) ceil(($now->hour * 60 + $now->minute + 1) / $step) * $step : 0;
            $dayCandidates = [];

            foreach ($byProvider as $providerId => $plan) {
                foreach ($plan['windows'] as [$windowStart, $windowEnd]) {
                    $first = (int) ceil(max($windowStart, $earliest, $periodStart) / $step) * $step;

                    for ($start = $first; $start + $durationMinutes <= $windowEnd && $start < $periodEnd; $start += $step) {
                        $interval = [$start, $start + $durationMinutes];
                        if ($this->clashes($busy[$providerId][$key] ?? [], $interval)) {
                            continue;
                        }
                        $rooms = $providerRooms[$providerId] ?? [];
                        if ($rooms !== [] && ! collect($rooms)->contains(fn (int $roomId) => ! $this->clashes($roomBusy[$roomId][$key] ?? [], $interval))) {
                            continue;
                        }
                        $dayCandidates[] = [$start, $providerId];
                    }
                }
            }

            usort($dayCandidates, fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

            foreach ($dayCandidates as [$start, $providerId]) {
                if ($perProvider !== null && ($found[$providerId] ?? 0) >= $perProvider) {
                    continue;
                }
                $found[$providerId] = ($found[$providerId] ?? 0) + 1;
                $begins = $date->addMinutes($start);
                $slots[] = [
                    'provider_id' => (int) $providerId,
                    'start' => $begins->format('Y-m-d\TH:i:s'),
                    'end' => $begins->addMinutes($durationMinutes)->format('Y-m-d\TH:i:s'),
                ];

                if (count($slots) >= $limit) {
                    return $slots;
                }
            }
        }

        return $slots;
    }

    /**
     * Each provider's state right now and their next free slot, for the
     * calendar's side panel.
     *
     * @param  Collection<int, ClinicEmployee>  $providers
     * @return list<array{id: int, name: string, specialty: string|null, state: string, label: string, booked_today: int, next_slot: string|null}>
     */
    public function providerStatuses(Clinic $clinic, Branch $branch, Collection $providers, int $durationMinutes): array
    {
        if ($providers->isEmpty()) {
            return [];
        }

        $now = ClinicClock::now();
        $today = $now->startOfDay();
        $ids = $providers->pluck('id')->map(fn ($id) => (int) $id)->all();
        $plans = $this->providerPlans($clinic, $branch, $ids, $today, $today)[$today->toDateString()] ?? [];
        $minute = $now->hour * 60 + $now->minute;

        $todays = Appointment::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->where('branch_id', $branch->id)
            ->whereIn('practitioner_id', $ids)
            ->whereBetween('scheduled_at', [$today, $today->endOfDay()])
            ->whereNotIn('status', [AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value])
            ->get(['id', 'practitioner_id', 'scheduled_at', 'duration_minutes', 'status'])
            ->groupBy('practitioner_id');

        $next = collect($this->freeSlots($clinic, $branch, $ids, $today, 14, $durationMinutes, limit: count($ids), perProvider: 1))
            ->keyBy('provider_id');

        return $providers->map(function (ClinicEmployee $provider) use ($plans, $todays, $minute, $next) {
            $plan = $plans[$provider->id] ?? ['state' => 'off', 'windows' => []];
            $appointments = $todays->get($provider->id, collect());
            $inSession = $appointments->first(function (Appointment $appointment) use ($minute) {
                $start = $appointment->scheduled_at->hour * 60 + $appointment->scheduled_at->minute;

                return $start <= $minute && $minute < $start + ($appointment->duration_minutes ?? 15)
                    && $appointment->status !== AppointmentStatus::Completed;
            });
            $workingNow = collect($plan['windows'])->contains(fn (array $window) => $window[0] <= $minute && $minute < $window[1]);

            [$state, $label] = match (true) {
                $plan['state'] === 'leave' => ['on_leave', 'On leave today'],
                $plan['state'] === 'closed' => ['closed', 'Branch closed today'],
                $inSession !== null => ['busy', 'With a patient until '.$inSession->scheduled_at->copy()->addMinutes($inSession->duration_minutes ?? 15)->format('H:i')],
                $workingNow => ['available', 'Available now'],
                collect($plan['windows'])->contains(fn (array $window) => $window[0] > $minute) => ['off_shift', 'Starts at '.$this->clock(collect($plan['windows'])->first(fn (array $window) => $window[0] > $minute)[0])],
                default => ['off_shift', 'Not working now'],
            };

            return [
                'id' => (int) $provider->id,
                'name' => $provider->name,
                'specialty' => $provider->practitionerProfile?->specialty,
                'state' => $state,
                'label' => $label,
                'booked_today' => $appointments->count(),
                'next_slot' => $next->get($provider->id)['start'] ?? null,
            ];
        })->values()->all();
    }

    /**
     * Which providers are on approved leave on which dates in the range, as
     * "providerId|Y-m-d" keys. One query.
     *
     * @param  iterable<int>  $providerIds
     * @return array<string, true>
     */
    public function leaveDays(Clinic $clinic, iterable $providerIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = collect($providerIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $days = [];
        Leave::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->whereIn('employee_id', $ids)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->get(['employee_id', 'start_date', 'end_date'])
            ->each(function (Leave $leave) use (&$days, $from, $to) {
                $day = CarbonImmutable::instance($leave->start_date)->max($from->startOfDay());
                $last = CarbonImmutable::instance($leave->end_date)->min($to->startOfDay());
                for (; $day->lte($last) && count($days) < 5000; $day = $day->addDay()) {
                    $days[$leave->employee_id.'|'.$day->toDateString()] = true;
                }
            });

        return $days;
    }

    /**
     * Upcoming appointments at the branch whose provider has since gone on
     * approved leave that day: for Reception to move, never moved silently.
     *
     * @return Collection<int, Appointment>
     */
    public function appointmentsOnLeave(Clinic $clinic, Branch $branch, int $days = 30, int $limit = 50): Collection
    {
        $from = ClinicClock::today();
        $to = $from->addDays($days);
        $leaveDays = $this->leaveDays($clinic, $this->providers($clinic, $branch)->pluck('id'), $from, $to);

        if ($leaveDays === []) {
            return collect();
        }

        $providerIds = collect(array_keys($leaveDays))->map(fn (string $key) => (int) explode('|', $key)[0])->unique()->values();

        return Appointment::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->where('branch_id', $branch->id)
            ->whereIn('practitioner_id', $providerIds)
            ->whereBetween('scheduled_at', [$from, $to->endOfDay()])
            ->whereIn('status', array_map(fn (AppointmentStatus $status) => $status->value, AppointmentStatus::checkable()))
            ->with(['patient:id,first_name,last_name,name,uhid,phone', 'practitioner:id,name', 'service:id,name'])
            ->orderBy('scheduled_at')
            ->limit(500)
            ->get()
            ->filter(fn (Appointment $appointment) => isset($leaveDays[$appointment->practitioner_id.'|'.$appointment->scheduled_at->toDateString()]))
            ->take($limit)
            ->values();
    }

    /**
     * Live appointments by owner and date, as minute intervals widened by the
     * buffer on both sides.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, list<array{0: int, 1: int}>>>
     */
    private function busyIntervals(string $column, array $ids, CarbonImmutable $from, CarbonImmutable $to, int $buffer, ?int $ignoreAppointmentId = null): array
    {
        $busy = [];

        Appointment::withoutGlobalScopes()
            ->whereIn($column, $ids)
            ->whereBetween('scheduled_at', [$from->startOfDay(), $to->endOfDay()])
            ->whereNotIn('status', [AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value])
            ->when($ignoreAppointmentId, fn ($q, $id) => $q->whereKeyNot($id))
            ->get(['id', $column, 'scheduled_at', 'duration_minutes'])
            ->each(function (Appointment $appointment) use (&$busy, $column, $buffer) {
                $start = $appointment->scheduled_at->hour * 60 + $appointment->scheduled_at->minute;
                $busy[(int) $appointment->{$column}][$appointment->scheduled_at->toDateString()][] = [
                    $start - $buffer,
                    $start + ($appointment->duration_minutes ?? 15) + $buffer,
                ];
            });

        return $busy;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $intervals
     * @param  array{0: int, 1: int}  $interval
     */
    private function clashes(array $intervals, array $interval): bool
    {
        foreach ($intervals as [$start, $end]) {
            if ($start < $interval[1] && $end > $interval[0]) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $windows
     * @param  array{0: int, 1: int}  $cut
     * @return list<array{0: int, 1: int}>
     */
    private function subtract(array $windows, array $cut): array
    {
        $result = [];
        foreach ($windows as [$start, $end]) {
            if ($cut[1] <= $start || $cut[0] >= $end) {
                $result[] = [$start, $end];

                continue;
            }
            if ($cut[0] > $start) {
                $result[] = [$start, $cut[0]];
            }
            if ($cut[1] < $end) {
                $result[] = [$cut[1], $end];
            }
        }

        return $result;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $windows
     * @param  array{0: int, 1: int}  $bounds
     * @return list<array{0: int, 1: int}>
     */
    private function intersect(array $windows, array $bounds): array
    {
        return collect($windows)
            ->map(fn (array $window) => [max($window[0], $bounds[0]), min($window[1], $bounds[1])])
            ->filter(fn (array $window) => $window[1] > $window[0])
            ->values()->all();
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function dates(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $dates = [];
        for ($date = $from->startOfDay(); $date->lte($to) && count($dates) < self::MAX_RANGE_DAYS; $date = $date->addDay()) {
            $dates[] = $date;
        }

        return $dates;
    }

    private function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }

    private function clock(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
