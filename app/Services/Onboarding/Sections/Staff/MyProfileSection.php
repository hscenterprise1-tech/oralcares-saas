<?php

namespace App\Services\Onboarding\Sections\Staff;

use App\Services\Onboarding\OnboardingContext;
use App\Services\Onboarding\Sections\AbstractSection;
use Illuminate\Validation\Rule;

/**
 * The invited staff member's own details. Role, branches and staff type
 * were set by whoever invited them and are shown read only.
 */
class MyProfileSection extends AbstractSection
{
    public function key(): string
    {
        return 'my_profile';
    }

    public function title(OnboardingContext $context): string
    {
        return 'Your details';
    }

    public function description(OnboardingContext $context): string
    {
        return 'How colleagues and patients see you.';
    }

    public function initial(OnboardingContext $context): array
    {
        $employee = $context->employee;

        return [
            'name' => (string) $employee?->name,
            'phone' => (string) $employee?->phone,
            'date_of_birth' => $employee?->date_of_birth?->toDateString(),
            'gender' => $employee?->gender,
        ];
    }

    public function meta(OnboardingContext $context): array
    {
        $employee = $context->employee?->loadMissing(['roles:id,name', 'branches:id,name']);

        return [
            'locked' => array_values(array_filter([
                ['label' => 'Email', 'value' => $employee?->email],
                ['label' => 'Role', 'value' => $employee?->roles->pluck('name')->implode(', ') ?: '—'],
                ['label' => 'Branches', 'value' => $employee?->branches->pluck('name')->implode(', ') ?: '—'],
            ])),
            'genders' => [['value' => 'female', 'label' => 'Female'], ['value' => 'male', 'label' => 'Male'], ['value' => 'other', 'label' => 'Other']],
        ];
    }

    public function rules(OnboardingContext $context): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^[0-9+\-\s]{7,20}$/'],
            'date_of_birth' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'gender' => ['nullable', Rule::in(['female', 'male', 'other'])],
        ];
    }

    public function apply(OnboardingContext $context, array $data): void
    {
        $context->employee->update($data);
    }

    public function summary(OnboardingContext $context): array
    {
        return array_values(array_filter([$context->employee?->fresh()?->name, $context->employee?->fresh()?->phone]));
    }
}
