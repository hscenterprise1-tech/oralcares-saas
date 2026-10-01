<?php

namespace App\Services\Appointments;

use App\Models\Clinic;
use App\Models\ClinicSettingAudit;
use App\Models\User;

/**
 * Per-clinic appointment rules (the calendar's Settings), stored on
 * `clinics.appointment_config`. Same pattern as `PatientFlowConfigService`.
 *
 * Branch operating hours and each provider's schedule stay where they are
 * configured today (`clinic_operating_hours`, `practitioner_schedules`); the
 * day window here is only the fallback for a branch with no hours set.
 *
 * @phpstan-type AppointmentSettings array{
 *     slot_minutes: int,
 *     default_duration_minutes: int,
 *     buffer_minutes: int,
 *     day_start: string,
 *     day_end: string,
 *     working_days: list<int>,
 *     break_start: string|null,
 *     break_end: string|null,
 *     holidays: list<string>,
 *     cancellation_notice_hours: int,
 *     no_show_after_minutes: int,
 *     recurring_enabled: bool,
 *     max_recurring_occurrences: int,
 *     online_booking_enabled: bool,
 * }
 */
class AppointmentSettingsService
{
    public const AUDIT_SECTION = 'appointments';

    public const SLOT_CHOICES = [5, 10, 15, 20, 30, 60];

    /**
     * @var AppointmentSettings
     */
    public const DEFAULTS = [
        'slot_minutes' => 15,
        'default_duration_minutes' => 30,
        'buffer_minutes' => 0,
        'day_start' => '09:00',
        'day_end' => '19:00',
        'working_days' => [1, 2, 3, 4, 5, 6],
        'break_start' => null,
        'break_end' => null,
        'holidays' => [],
        'cancellation_notice_hours' => 0,
        'no_show_after_minutes' => 30,
        'recurring_enabled' => true,
        'max_recurring_occurrences' => 12,
        'online_booking_enabled' => false,
    ];

    /**
     * The clinic's stored values over the defaults; anything missing or
     * malformed falls back to its default rather than breaking the calendar.
     *
     * @return AppointmentSettings
     */
    public function resolve(?Clinic $clinic): array
    {
        return $this->normalise($clinic?->appointment_config ?? []);
    }

    /**
     * Validation rules for an update, shared by the controller.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        $time = ['nullable', 'date_format:H:i'];

        return [
            'slot_minutes' => ['required', 'integer', 'in:'.implode(',', self::SLOT_CHOICES)],
            'default_duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'buffer_minutes' => ['required', 'integer', 'min:0', 'max:120'],
            'day_start' => ['required', 'date_format:H:i'],
            'day_end' => ['required', 'date_format:H:i', 'after:day_start'],
            'working_days' => ['present', 'array'],
            'working_days.*' => ['integer', 'between:0,6'],
            'break_start' => [...$time, 'required_with:break_end'],
            'break_end' => [...$time, 'required_with:break_start', 'after:break_start'],
            'holidays' => ['present', 'array', 'max:60'],
            'holidays.*' => ['date_format:Y-m-d'],
            'cancellation_notice_hours' => ['required', 'integer', 'min:0', 'max:168'],
            'no_show_after_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'recurring_enabled' => ['required', 'boolean'],
            'max_recurring_occurrences' => ['required', 'integer', 'min:2', 'max:52'],
            'online_booking_enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Clinic $clinic, array $input, ?User $actor): void
    {
        $before = $this->resolve($clinic);
        $after = $this->normalise($input);

        if ($before === $after) {
            return;
        }

        $clinic->update(['appointment_config' => $after]);

        ClinicSettingAudit::create([
            'clinic_id' => $clinic->id,
            'user_id' => $actor?->id,
            'section' => self::AUDIT_SECTION,
            'old_values' => $before,
            'new_values' => $after,
        ]);
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return AppointmentSettings
     */
    private function normalise(array $stored): array
    {
        $int = function (string $key, int $min, int $max) use ($stored): int {
            $value = $stored[$key] ?? null;

            return is_numeric($value) && (int) $value >= $min && (int) $value <= $max ? (int) $value : self::DEFAULTS[$key];
        };
        $time = function (string $key, bool $nullable) use ($stored): ?string {
            $value = $stored[$key] ?? null;
            if (is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
                return $value;
            }

            return $nullable ? null : self::DEFAULTS[$key];
        };
        $bool = fn (string $key): bool => array_key_exists($key, $stored) ? (bool) $stored[$key] : self::DEFAULTS[$key];

        $slot = (int) ($stored['slot_minutes'] ?? 0);
        $dayStart = $time('day_start', false);
        $dayEnd = $time('day_end', false);
        if ($dayEnd <= $dayStart) {
            [$dayStart, $dayEnd] = [self::DEFAULTS['day_start'], self::DEFAULTS['day_end']];
        }
        $breakStart = $time('break_start', true);
        $breakEnd = $time('break_end', true);
        if ($breakStart === null || $breakEnd === null || $breakEnd <= $breakStart) {
            [$breakStart, $breakEnd] = [null, null];
        }

        $days = is_array($stored['working_days'] ?? null)
            ? collect($stored['working_days'])->filter(fn ($day) => is_numeric($day) && (int) $day >= 0 && (int) $day <= 6)->map(fn ($day) => (int) $day)->unique()->sort()->values()->all()
            : self::DEFAULTS['working_days'];

        $holidays = is_array($stored['holidays'] ?? null)
            ? collect($stored['holidays'])->filter(fn ($day) => is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day))->unique()->sort()->values()->all()
            : [];

        return [
            'slot_minutes' => in_array($slot, self::SLOT_CHOICES, true) ? $slot : self::DEFAULTS['slot_minutes'],
            'default_duration_minutes' => $int('default_duration_minutes', 5, 480),
            'buffer_minutes' => $int('buffer_minutes', 0, 120),
            'day_start' => $dayStart,
            'day_end' => $dayEnd,
            'working_days' => $days,
            'break_start' => $breakStart,
            'break_end' => $breakEnd,
            'holidays' => $holidays,
            'cancellation_notice_hours' => $int('cancellation_notice_hours', 0, 168),
            'no_show_after_minutes' => $int('no_show_after_minutes', 5, 240),
            'recurring_enabled' => $bool('recurring_enabled'),
            'max_recurring_occurrences' => $int('max_recurring_occurrences', 2, 52),
            'online_booking_enabled' => $bool('online_booking_enabled'),
        ];
    }
}
