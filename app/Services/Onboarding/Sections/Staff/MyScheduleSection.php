<?php

namespace App\Services\Onboarding\Sections\Staff;

use App\Services\Onboarding\OnboardingContext;
use App\Services\Onboarding\Sections\AbstractSection;
use App\Services\Staff\PractitionerScheduleEditor;

/**
 * The practitioner's weekly hours at their primary branch, one shift per
 * day. Split shifts and other branches are edited later in the staff
 * profile; this writes the same schedule rows.
 */
class MyScheduleSection extends AbstractSection
{
    public function __construct(protected PractitionerScheduleEditor $schedule) {}

    public function key(): string
    {
        return 'my_schedule';
    }

    public function title(OnboardingContext $context): string
    {
        return 'Your working hours';
    }

    public function description(OnboardingContext $context): string
    {
        return $context->branch
            ? "When you see patients at {$context->branch->name}."
            : 'When you see patients.';
    }

    public function initial(OnboardingContext $context): array
    {
        $weekly = $context->employee && $context->branch ? ($this->schedule->weekly($context->employee)[$context->branch->id] ?? []) : [];

        return [
            'days' => collect(range(0, 6))->map(function (int $day) use ($weekly) {
                $shift = $weekly[$day][0] ?? null;

                return ['day' => $day, 'open' => $shift !== null || ($weekly === [] && $day >= 1 && $day <= 6), 'start' => $shift['start'] ?? '10:00', 'end' => $shift['end'] ?? '18:00'];
            })->all(),
        ];
    }

    public function rules(OnboardingContext $context): array
    {
        return [
            'days' => ['required', 'array', 'size:7'],
            'days.*.day' => ['required', 'integer', 'between:0,6', 'distinct'],
            'days.*.open' => ['required', 'boolean'],
            'days.*.start' => ['required_if:days.*.open,true', 'nullable', 'date_format:H:i'],
            'days.*.end' => ['required_if:days.*.open,true', 'nullable', 'date_format:H:i'],
        ];
    }

    public function apply(OnboardingContext $context, array $data): void
    {
        $days = collect($data['days'])
            ->mapWithKeys(fn (array $day) => [(int) $day['day'] => $day['open'] ? [['start' => $day['start'], 'end' => $day['end']]] : []])
            ->all();

        $this->schedule->save($context->employee, $context->branch, $days);
    }

    public function summary(OnboardingContext $context): array
    {
        $names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

        return [collect($this->initial($context)['days'])->filter(fn (array $day) => $day['open'])->map(fn (array $day) => "{$names[$day['day']]} {$day['start']}–{$day['end']}")->implode(', ')];
    }
}
