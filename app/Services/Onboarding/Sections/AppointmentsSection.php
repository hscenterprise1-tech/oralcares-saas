<?php

namespace App\Services\Onboarding\Sections;

use App\Services\Appointments\AppointmentSettingsService;
use App\Services\Onboarding\OnboardingContext;

/**
 * How the calendar books: slot size, usual length, gaps between visits,
 * cancellation notice and when a late patient counts as a no-show. Writes
 * the same appointment settings the settings page edits.
 */
class AppointmentsSection extends AbstractSection
{
    private const FIELDS = ['slot_minutes', 'default_duration_minutes', 'buffer_minutes', 'cancellation_notice_hours', 'no_show_after_minutes', 'online_booking_enabled'];

    public function __construct(protected AppointmentSettingsService $appointments) {}

    public function key(): string
    {
        return 'appointments';
    }

    public function title(OnboardingContext $context): string
    {
        return 'Appointments';
    }

    public function description(OnboardingContext $context): string
    {
        return 'How your calendar offers time. These can be changed later in Appointment settings.';
    }

    public function initial(OnboardingContext $context): array
    {
        return array_intersect_key($this->appointments->resolve($context->clinic), array_flip(self::FIELDS));
    }

    public function meta(OnboardingContext $context): array
    {
        return ['slot_choices' => AppointmentSettingsService::SLOT_CHOICES];
    }

    public function rules(OnboardingContext $context): array
    {
        return array_intersect_key(AppointmentSettingsService::rules(), array_flip(self::FIELDS));
    }

    public function apply(OnboardingContext $context, array $data): void
    {
        $this->appointments->update($context->clinic, [...$this->appointments->resolve($context->clinic), ...$data], $context->actor);
    }

    public function summary(OnboardingContext $context): array
    {
        $settings = $this->appointments->resolve($context->clinic);

        return [
            "{$settings['slot_minutes']}-minute slots, usual visit {$settings['default_duration_minutes']} min",
            $settings['buffer_minutes'] ? "{$settings['buffer_minutes']} min between visits" : 'No gap between visits',
        ];
    }
}
