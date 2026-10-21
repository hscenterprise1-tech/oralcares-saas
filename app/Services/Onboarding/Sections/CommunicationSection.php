<?php

namespace App\Services\Onboarding\Sections;

use App\Services\Onboarding\OnboardingContext;
use Illuminate\Validation\Rule;

/**
 * Which messages patients get and how they are signed. Stored in
 * `clinics.communication_config`; sending follows once messaging is
 * connected, so this step is optional.
 */
class CommunicationSection extends AbstractSection
{
    public const REMINDER_HOURS = [2, 4, 12, 24, 48];

    public const LANGUAGES = ['en', 'hi'];

    public function key(): string
    {
        return 'communication';
    }

    public function title(OnboardingContext $context): string
    {
        return 'Patient messages';
    }

    public function description(OnboardingContext $context): string
    {
        return 'Booking confirmations and reminders patients receive.';
    }

    public function required(OnboardingContext $context): bool
    {
        return false;
    }

    public function initial(OnboardingContext $context): array
    {
        $defaults = [
            'sender_name' => $context->clinic->display_name ?: $context->clinic->name,
            'send_confirmations' => true,
            'send_reminders' => true,
            'reminder_hours_before' => 24,
            'language' => 'en',
        ];

        return array_replace($defaults, array_intersect_key((array) ($context->clinic->communication_config ?? []), $defaults));
    }

    public function meta(OnboardingContext $context): array
    {
        return ['reminder_hours' => self::REMINDER_HOURS, 'languages' => [['value' => 'en', 'label' => 'English'], ['value' => 'hi', 'label' => 'Hindi']]];
    }

    public function rules(OnboardingContext $context): array
    {
        return [
            'sender_name' => ['required', 'string', 'max:60'],
            'send_confirmations' => ['required', 'boolean'],
            'send_reminders' => ['required', 'boolean'],
            'reminder_hours_before' => ['required', 'integer', Rule::in(self::REMINDER_HOURS)],
            'language' => ['required', Rule::in(self::LANGUAGES)],
        ];
    }

    public function apply(OnboardingContext $context, array $data): void
    {
        $context->clinic->update(['communication_config' => [
            ...(array) ($context->clinic->communication_config ?? []),
            'sender_name' => $data['sender_name'],
            'send_confirmations' => (bool) $data['send_confirmations'],
            'send_reminders' => (bool) $data['send_reminders'],
            'reminder_hours_before' => (int) $data['reminder_hours_before'],
            'language' => $data['language'],
        ]]);
    }

    public function summary(OnboardingContext $context): array
    {
        $values = $this->initial($context);

        return [
            'Signed as '.$values['sender_name'],
            $values['send_reminders'] ? "Reminders {$values['reminder_hours_before']} h before" : 'No reminders',
        ];
    }
}
