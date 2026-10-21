<?php

namespace App\Services\Onboarding\Sections;

use App\Models\ClinicOperatingHour;
use App\Services\Appointments\AppointmentSettingsService;
use App\Services\Onboarding\OnboardingContext;
use Illuminate\Validation\ValidationException;

/**
 * Opening hours of the primary branch and the clinic's holidays. Hours go
 * to `clinic_operating_hours` (what the calendar reads per branch);
 * holidays and the working week go to the appointment settings.
 */
class WorkingHoursSection extends AbstractSection
{
    public function __construct(protected AppointmentSettingsService $appointments) {}

    public function key(): string
    {
        return 'working_hours';
    }

    public function title(OnboardingContext $context): string
    {
        return 'Working hours & holidays';
    }

    public function description(OnboardingContext $context): string
    {
        return 'When the clinic is open. Appointments are only offered inside these hours.';
    }

    public function initial(OnboardingContext $context): array
    {
        $rows = $context->branch
            ? ClinicOperatingHour::withoutGlobalScopes()->where('branch_id', $context->branch->id)->get()->keyBy('day_of_week')
            : collect();

        $days = collect(range(0, 6))->map(function (int $day) use ($rows) {
            $row = $rows->get($day);

            return [
                'day' => $day,
                'open' => $row ? ! $row->is_closed : $day !== 0,
                'opens' => $row && ! $row->is_closed ? substr((string) $row->open_time, 0, 5) : '09:00',
                'closes' => $row && ! $row->is_closed ? substr((string) $row->close_time, 0, 5) : '19:00',
            ];
        })->all();

        return [
            'days' => $days,
            'holidays' => $this->appointments->resolve($context->clinic)['holidays'],
        ];
    }

    public function rules(OnboardingContext $context): array
    {
        return [
            'days' => ['required', 'array', 'size:7'],
            'days.*.day' => ['required', 'integer', 'between:0,6', 'distinct'],
            'days.*.open' => ['required', 'boolean'],
            'days.*.opens' => ['required_if:days.*.open,true', 'nullable', 'date_format:H:i'],
            'days.*.closes' => ['required_if:days.*.open,true', 'nullable', 'date_format:H:i'],
            'holidays' => ['present', 'array', 'max:60'],
            'holidays.*' => ['date_format:Y-m-d', 'distinct'],
        ];
    }

    public function apply(OnboardingContext $context, array $data): void
    {
        $open = collect($data['days'])->filter(fn (array $day) => $day['open']);

        if ($open->isEmpty()) {
            throw ValidationException::withMessages(['days' => 'Open the clinic on at least one day.']);
        }

        foreach ($open as $day) {
            if ($day['closes'] <= $day['opens']) {
                throw ValidationException::withMessages(["days.{$day['day']}" => 'Closing time must be after opening time.']);
            }
        }

        foreach ($data['days'] as $day) {
            ClinicOperatingHour::withoutGlobalScopes()->updateOrCreate(
                ['clinic_id' => $context->clinic->id, 'branch_id' => $context->branch->id, 'day_of_week' => $day['day']],
                $day['open']
                    ? ['open_time' => $day['opens'].':00', 'close_time' => $day['closes'].':00', 'is_closed' => false]
                    : ['open_time' => '00:00:00', 'close_time' => '00:00:00', 'is_closed' => true],
            );
        }

        $settings = $this->appointments->resolve($context->clinic);
        $this->appointments->update($context->clinic, [
            ...$settings,
            'working_days' => $open->pluck('day')->map(fn ($day) => (int) $day)->sort()->values()->all(),
            'day_start' => $open->min('opens'),
            'day_end' => $open->max('closes'),
            'holidays' => collect($data['holidays'])->sort()->values()->all(),
        ], $context->actor);
    }

    public function summary(OnboardingContext $context): array
    {
        $names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $days = collect($this->initial($context)['days'])->filter(fn (array $day) => $day['open']);
        $holidays = count($this->appointments->resolve($context->clinic)['holidays']);

        return array_values(array_filter([
            $days->map(fn (array $day) => "{$names[$day['day']]} {$day['opens']}–{$day['closes']}")->implode(', '),
            $holidays ? "{$holidays} holiday(s) marked" : null,
        ]));
    }
}
