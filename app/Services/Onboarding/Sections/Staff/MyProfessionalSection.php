<?php

namespace App\Services\Onboarding\Sections\Staff;

use App\Models\ClinicalSpecialty;
use App\Models\Practitioner;
use App\Models\PractitionerQualification;
use App\Services\Onboarding\OnboardingContext;
use App\Services\Onboarding\Sections\AbstractSection;
use Illuminate\Validation\Rule;

/**
 * A practitioner's profession, specialty, registration and main
 * qualification (BDS, MDS, BPT …). Writes the practitioner profile and its
 * structured qualification records, the same ones the staff profile edits.
 */
class MyProfessionalSection extends AbstractSection
{
    public function key(): string
    {
        return 'my_professional';
    }

    public function title(OnboardingContext $context): string
    {
        return 'Professional details';
    }

    public function description(OnboardingContext $context): string
    {
        return 'Your qualification, specialty and registration.';
    }

    public function initial(OnboardingContext $context): array
    {
        $profile = $this->profile($context);
        $qualification = $profile
            ? PractitionerQualification::withoutGlobalScopes()->where('doctor_profile_id', $profile->id)->orderBy('position')->first()
            : null;

        return [
            'profession' => (string) $profile?->profession,
            'specialty' => (string) ($profile?->specialty ?? ''),
            'experience_years' => $profile?->experience_years,
            'qualification' => (string) $qualification?->qualification,
            'degree_type' => $qualification?->degree_type ?? 'bachelor',
            'specialization' => (string) $qualification?->specialization,
            'institution' => (string) $qualification?->institution,
            'year' => $qualification?->year,
            'registration_number' => (string) $profile?->registration_number,
            'registration_authority' => (string) $profile?->registration_authority,
            'registration_state' => (string) $profile?->registration_state,
            'registration_expires_at' => $profile?->registration_expires_at?->toDateString(),
        ];
    }

    public function meta(OnboardingContext $context): array
    {
        return [
            'degree_types' => collect(config('clinic_staff.degree_types'))->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values()->all(),
            'specialties' => ClinicalSpecialty::withoutGlobalScopes()->where('organization_id', $context->clinic->organization_id)->where('is_active', true)->orderBy('name')->limit(200)->pluck('name')->all(),
        ];
    }

    public function rules(OnboardingContext $context): array
    {
        return [
            'profession' => ['required', 'string', 'max:80'],
            'specialty' => ['nullable', 'string', 'max:120'],
            'experience_years' => ['nullable', 'integer', 'min:0', 'max:70'],
            'qualification' => ['required', 'string', 'max:80'],
            'degree_type' => ['required', Rule::in(array_keys(config('clinic_staff.degree_types')))],
            'specialization' => ['nullable', 'string', 'max:120'],
            'institution' => ['nullable', 'string', 'max:160'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:'.now()->year],
            'registration_number' => ['nullable', 'string', 'max:80'],
            'registration_authority' => ['nullable', 'required_with:registration_number', 'string', 'max:150'],
            'registration_state' => ['nullable', 'string', 'max:80'],
            'registration_expires_at' => ['nullable', 'date', 'after:today'],
        ];
    }

    public function apply(OnboardingContext $context, array $data): void
    {
        $profile = $this->profile($context) ?? Practitioner::withoutGlobalScopes()->create([
            'clinic_id' => $context->clinic->id,
            'clinic_employee_id' => $context->employee->id,
            'is_active' => true,
        ]);

        $specialty = null;
        $name = trim((string) ($data['specialty'] ?? ''));
        if ($name !== '') {
            $specialty = ClinicalSpecialty::withoutGlobalScopes()->where('organization_id', $context->clinic->organization_id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
                ?? ClinicalSpecialty::withoutGlobalScopes()->forceCreate(['organization_id' => $context->clinic->organization_id, 'name' => $name, 'is_active' => true]);
        }

        $profile->update([
            'profession' => $data['profession'],
            'specialty' => $specialty?->name,
            'primary_specialty_id' => $specialty?->id,
            'experience_years' => $data['experience_years'] ?? null,
            'registration_number' => $data['registration_number'] ?? null,
            'registration_authority' => $data['registration_authority'] ?? null,
            'registration_state' => $data['registration_state'] ?? null,
            'registration_expires_at' => $data['registration_expires_at'] ?? null,
        ]);

        PractitionerQualification::withoutGlobalScopes()->updateOrCreate(
            ['clinic_id' => $context->clinic->id, 'doctor_profile_id' => $profile->id, 'qualification' => $data['qualification'], 'specialization' => $data['specialization'] ?? null],
            ['degree_type' => $data['degree_type'], 'institution' => $data['institution'] ?? null, 'year' => $data['year'] ?? null, 'position' => 0],
        );
    }

    public function summary(OnboardingContext $context): array
    {
        $values = $this->initial($context);

        return array_values(array_filter([
            trim($values['qualification'].($values['specialization'] ? " ({$values['specialization']})" : '')),
            $values['specialty'] ?: null,
            $values['registration_number'] ? 'Reg. '.$values['registration_number'] : null,
        ]));
    }

    private function profile(OnboardingContext $context): ?Practitioner
    {
        return $context->employee
            ? Practitioner::withoutGlobalScopes()->where('clinic_employee_id', $context->employee->id)->first()
            : null;
    }
}
