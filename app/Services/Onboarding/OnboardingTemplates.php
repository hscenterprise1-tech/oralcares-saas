<?php

namespace App\Services\Onboarding;

use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\Practitioner;
use App\Services\Staff\StaffDirectory;
use App\Support\Specialty\SpecialtyEntitlements;
use App\Support\Specialty\SpecialtyRegistry;
use InvalidArgumentException;

/**
 * Reads onboarding templates and sections from `config/clinic_onboarding.php`.
 *
 * The template comes from what CRM provisioned (the clinic's specialty set)
 * or from the staff member's type; nobody picks it in the UI.
 */
class OnboardingTemplates
{
    public function forClinic(Clinic $clinic): string
    {
        $specialties = $this->specialties($clinic);
        $map = config('clinic_onboarding.specialty_templates', []);

        if (count($specialties) > 1) {
            return 'multi_specialty';
        }

        return $map[$specialties[0] ?? ''] ?? config('clinic_onboarding.default_template');
    }

    public function forEmployee(ClinicEmployee $employee): string
    {
        $clinical = StaffDirectory::isClinical($employee->staff_type)
            || Practitioner::withoutGlobalScopes()->where('clinic_employee_id', $employee->id)->exists();

        return $clinical ? 'staff_clinical' : 'staff_general';
    }

    /**
     * @return array{label: string, kind: string, version: int, sections: list<string>, options: array<string, mixed>}
     */
    public function template(string $key): array
    {
        $template = config("clinic_onboarding.templates.{$key}");

        if (! is_array($template)) {
            throw new InvalidArgumentException("Unknown onboarding template [{$key}].");
        }

        return $template;
    }

    /**
     * The template's options for this clinic. A multi-specialty clinic gets
     * the service suggestions of every specialty it was sold.
     *
     * @return array<string, mixed>
     */
    public function options(string $key, Clinic $clinic): array
    {
        $options = $this->template($key)['options'] ?? [];

        if ($key === 'multi_specialty') {
            $map = config('clinic_onboarding.specialty_templates', []);
            $catalogues = collect($this->specialties($clinic))
                ->map(fn (string $specialty) => $map[$specialty] ?? null)
                ->filter()
                ->flatMap(fn (string $template) => config("clinic_onboarding.templates.{$template}.options.catalogues", []))
                ->unique()
                ->values()
                ->all();

            $options['catalogues'] = $catalogues ?: $options['catalogues'];
        }

        return $options;
    }

    /**
     * The specialties the setup is for: the clinic's own set, within what its
     * plan sells. The same rule every practised-specialty resolver applies
     * (`SpecialtyEntitlements::filter`), so a clinic is never set up for a
     * specialty it cannot use. A plan that sells no specialty product leaves
     * the clinic's set exactly as it is.
     *
     * @return list<string>
     */
    private function specialties(Clinic $clinic): array
    {
        $configured = array_values(array_filter((array) ($clinic->specialties ?? [])));
        $entitlements = app(SpecialtyEntitlements::class);

        if ($entitlements->entitled($clinic) === null) {
            return $configured;
        }

        $registry = app(SpecialtyRegistry::class);
        $normalized = $registry->normalize($configured);

        return $entitlements->filter($clinic, $normalized !== [] ? $normalized : [$registry->default()]);
    }

    public function section(string $key): OnboardingSection
    {
        $class = config("clinic_onboarding.sections.{$key}");

        if (! $class) {
            throw new InvalidArgumentException("Unknown onboarding section [{$key}].");
        }

        return app($class);
    }
}
