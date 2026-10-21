<?php

namespace App\Services\Onboarding\Sections;

use App\HealthcareSpecialty;
use App\Services\Onboarding\OnboardingContext;
use App\Services\Onboarding\OnboardingTemplates;
use App\Services\Provisioning\EntitlementResolver;
use Illuminate\Support\Str;

/**
 * The clinic's name as patients see it and as invoices print it, plus how
 * to reach it. Plan and specialty are shown, read only: they were sold in
 * CRM and nothing here can change them.
 */
class ClinicIdentitySection extends AbstractSection
{
    public function __construct(protected EntitlementResolver $entitlements, protected OnboardingTemplates $templates) {}

    public function key(): string
    {
        return 'clinic_identity';
    }

    public function title(OnboardingContext $context): string
    {
        return 'Clinic identity';
    }

    public function description(OnboardingContext $context): string
    {
        return 'Check how your clinic is named on screens, prescriptions and invoices.';
    }

    public function initial(OnboardingContext $context): array
    {
        return [
            'display_name' => $context->clinic->display_name ?: $context->clinic->name,
            'legal_name' => $context->clinic->legal_name ?: $context->clinic->name,
            'contact_email' => (string) $context->clinic->contact_email,
            'contact_phone' => (string) $context->clinic->contact_phone,
        ];
    }

    public function meta(OnboardingContext $context): array
    {
        $plan = $this->entitlements->forClinic($context->clinic)['plan'] ?? null;

        return [
            'locked' => [
                ['label' => 'Plan', 'value' => $plan['name'] ?? 'Assigned by OralCares'],
                ['label' => 'Specialty', 'value' => $this->specialtyLabel($context)],
                ['label' => 'Clinic address', 'value' => $context->clinic->slug],
            ],
            'locked_note' => 'Your plan and specialty were set up with your OralCares account manager. Contact them to change either.',
        ];
    }

    public function rules(OnboardingContext $context): array
    {
        return [
            'display_name' => ['required', 'string', 'max:120'],
            'legal_name' => ['required', 'string', 'max:160'],
            'contact_email' => ['nullable', 'email', 'max:160'],
            'contact_phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s]{7,20}$/'],
        ];
    }

    public function apply(OnboardingContext $context, array $data): void
    {
        $context->clinic->update([
            'display_name' => $data['display_name'],
            'legal_name' => $data['legal_name'],
            'contact_email' => $data['contact_email'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
        ]);
    }

    public function summary(OnboardingContext $context): array
    {
        return array_values(array_filter([
            $context->clinic->display_name,
            $context->clinic->legal_name !== $context->clinic->display_name ? 'Legal name: '.$context->clinic->legal_name : null,
            $this->specialtyLabel($context),
        ]));
    }

    private function specialtyLabel(OnboardingContext $context): string
    {
        $labels = collect((array) ($context->clinic->specialties ?? []))
            ->map(fn (string $value) => HealthcareSpecialty::tryFrom($value)?->label() ?? Str::headline($value))
            ->implode(', ');

        return $labels ?: $this->templates->template($context->session->template_key)['label'];
    }
}
