<?php

namespace App\Services\People;

use App\Models\CompanyHoliday;
use App\Models\CompanyWorkingDay;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which days this company works.
 *
 * The one thing this class refuses to do is guess. Nothing in this system says
 * a company works Monday to Friday, so an unconfigured calendar reports itself
 * unconfigured and every surface says so — it does not quietly assume an office
 * week and then let attendance be measured against it.
 *
 * Holidays are kept for past years too. A holiday that has been and gone is
 * what makes an old attendance record readable.
 */
class WorkingCalendarService
{
    /** Sunday … Saturday, matching Carbon's `dayOfWeek`. */
    public const DAY_NAMES = CompanyWorkingDay::NAMES;

    /**
     * Whether anybody has said which days are working days.
     *
     * Until they have, `isWorkingDay()` cannot answer and says so.
     */
    public function isConfigured(): bool
    {
        return CompanyWorkingDay::query()->exists();
    }

    /**
     * The weekly pattern, always seven rows, in week order.
     *
     * Days nobody has configured come back as null rather than as `true`, so a
     * partially configured week cannot masquerade as a complete one.
     *
     * @return list<array{day_of_week: int, name: string, is_working: bool|null}>
     */
    public function weeklyPattern(): array
    {
        $configured = CompanyWorkingDay::query()->get()->keyBy('day_of_week');

        return collect(range(0, 6))
            ->map(fn (int $day) => [
                'day_of_week' => $day,
                'name' => self::DAY_NAMES[$day],
                'is_working' => $configured->has($day) ? (bool) $configured[$day]->is_working : null,
            ])
            ->all();
    }

    /**
     * Set the whole week at once.
     *
     * Replacing the pattern rather than patching one day at a time keeps the
     * seven rows consistent — a half-saved week is the state this class exists
     * to make impossible.
     *
     * @param  array<int, bool>  $pattern  day_of_week => is_working
     */
    public function setWeeklyPattern(array $pattern, ?User $actor = null): void
    {
        foreach ($pattern as $day => $isWorking) {
            CompanyWorkingDay::updateOrCreate(
                ['day_of_week' => (int) $day],
                ['is_working' => (bool) $isWorking, 'updated_by' => $actor?->id],
            );
        }
    }

    /**
     * Whether a given date is a working day.
     *
     * Null when the week has not been configured — the honest answer, and the
     * reason attendance reports "calendar not configured" rather than marking
     * everybody absent on a Sunday nobody declared.
     */
    public function isWorkingDay(Carbon $date): ?bool
    {
        if (! $this->isConfigured()) {
            return null;
        }

        if ($this->holidayOn($date) !== null) {
            return false;
        }

        $day = CompanyWorkingDay::query()->where('day_of_week', $date->dayOfWeek)->first();

        return $day ? (bool) $day->is_working : null;
    }

    /** The holiday falling on a date, if there is one. */
    public function holidayOn(Carbon $date): ?CompanyHoliday
    {
        return CompanyHoliday::query()->whereDate('holiday_date', $date->toDateString())->first();
    }

    /**
     * Holidays within a window, keyed by `Y-m-d`.
     *
     * @return Collection<string, CompanyHoliday>
     */
    public function holidaysBetween(Carbon $from, Carbon $to): Collection
    {
        return CompanyHoliday::query()
            ->whereBetween('holiday_date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->keyBy(fn (CompanyHoliday $holiday) => $holiday->holiday_date->toDateString());
    }

    /**
     * How many working days fall in a window.
     *
     * Null when the calendar is unconfigured, for the same reason as above.
     */
    public function workingDaysBetween(Carbon $from, Carbon $to): ?int
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $count = 0;

        for ($date = $from->copy()->startOfDay(); $date->lessThanOrEqualTo($to); $date->addDay()) {
            if ($this->isWorkingDay($date) === true) {
                $count++;
            }
        }

        return $count;
    }
}
