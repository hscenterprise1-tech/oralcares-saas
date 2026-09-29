<?php

namespace App\Http\Controllers\Company\People;

use App\Http\Controllers\Controller;
use App\Models\CompanyHoliday;
use App\Services\People\WorkingCalendarService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Which days this company works, and which it is closed.
 *
 * Both are configuration. Nothing here defaults the working week to Monday to
 * Friday — an unconfigured pattern reports itself unconfigured and attendance
 * says so rather than measuring people against an office week nobody declared.
 *
 * Holidays are kept for past years. One that has been and gone is what makes an
 * old attendance record readable.
 */
class WorkingCalendarController extends Controller
{
    public function __construct(protected WorkingCalendarService $calendar) {}

    public function index(Request $request): Response
    {
        Gate::authorize('view-attendance');

        $year = (int) ($request->integer('year') ?: now()->year);

        $holidays = CompanyHoliday::query()
            ->with('creator:id,name')
            ->forYear($year)
            ->orderBy('holiday_date')
            ->get()
            ->map(fn (CompanyHoliday $holiday) => [
                'id' => $holiday->id,
                'date' => $holiday->holiday_date->toDateString(),
                'label' => $holiday->holiday_date->format('D, j M Y'),
                'name' => $holiday->name,
                'description' => $holiday->description,
                'is_optional' => $holiday->is_optional,
                'is_past' => $holiday->holiday_date->isPast(),
                'created_by' => $holiday->creator?->name,
            ])
            ->values();

        return Inertia::render('Company/People/WorkingCalendar', [
            'year' => $year,
            /*
             * Years derived in PHP rather than with `YEAR()`, which is MySQL's
             * and does not exist in SQLite — the test database. The set is a
             * handful of rows either way.
             */
            'years' => CompanyHoliday::query()
                ->pluck('holiday_date')
                ->map(fn ($date) => (int) $date->format('Y'))
                ->push(now()->year)
                ->unique()
                ->sortDesc()
                ->values(),
            'pattern' => $this->calendar->weeklyPattern(),
            'configured' => $this->calendar->isConfigured(),
            'holidays' => $holidays,
            'can' => ['manage' => Gate::allows('manage-attendance')],
        ]);
    }

    /**
     * Set the whole week at once.
     *
     * All seven days together, so a half-saved pattern cannot exist.
     */
    public function updatePattern(Request $request)
    {
        Gate::authorize('manage-attendance');

        $validated = $request->validate([
            'pattern' => ['required', 'array', 'size:7'],
            'pattern.*' => ['required', 'boolean'],
        ]);

        $this->calendar->setWeeklyPattern($validated['pattern'], $request->user());

        return back()->with('success', 'Working week saved.');
    }

    public function storeHoliday(Request $request)
    {
        Gate::authorize('manage-attendance');

        $validated = $request->validate([
            'holiday_date' => ['required', 'date', Rule::unique('company_holidays', 'holiday_date')],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_optional' => ['boolean'],
        ]);

        CompanyHoliday::create($validated + ['created_by' => $request->user()?->id]);

        return back()->with('success', "{$validated['name']} added to the calendar.");
    }

    /**
     * Remove a holiday.
     *
     * Only a future one. A holiday that has already happened is history that
     * attendance records were written against, and deleting it would change
     * what those days meant.
     */
    public function destroyHoliday(Request $request, CompanyHoliday $holiday)
    {
        Gate::authorize('manage-attendance');

        if ($holiday->holiday_date->isPast()) {
            return back()->withErrors([
                'holiday' => 'A holiday that has already passed cannot be removed — attendance was recorded against it.',
            ]);
        }

        $holiday->delete();

        return back()->with('success', 'Holiday removed.');
    }
}
