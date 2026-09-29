<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\ClinicType;
use App\Models\OnboardingTemplate;
use Illuminate\Database\Seeder;

/**
 * The sequences a customer is actually taken through.
 *
 * The default is the five steps that used to sit inside the React page, kept
 * verbatim so every existing account's `completed_steps` still lines up: the
 * keys are unchanged.
 *
 * The rest differ where the work genuinely differs. A single dental clinic does
 * not need a department map; a multi-speciality hospital cannot go live without
 * one. A diagnostic centre's first real milestone is its test catalogue, not
 * its chairs. Getting this wrong in either direction is expensive at a hundred
 * and twenty thousand clinics — too many steps and nobody finishes them, too
 * few and accounts go live half-configured and churn.
 *
 * Idempotent: re-running updates the steps of a template rather than
 * duplicating it, so this is safe against a live database.
 */
class OnboardingTemplateSeeder extends Seeder
{
    /**
     * The five original steps, unchanged. Every other template starts here and
     * inserts its own work into the sequence.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const BASE = [
        ['password_setup', 'Primary admin password setup', 'Clinic admin sets their password from the welcome email.'],
        ['organization_profile', 'Profile and branding', 'Legal name, logo, tax registration and billing details.'],
        ['branch_setup', 'Branches and operating hours', 'Locations, chairs and working hours.'],
        ['admin_access', 'Staff and clinician accounts', 'Clinician onboarding, roles and licence allocation.'],
        ['go_live', 'Go-live signoff', 'Data verification, system validation and formal launch.'],
    ];

    public function run(): void
    {
        $this->template('Standard onboarding', null, true, self::BASE);

        $this->template('Dental clinic onboarding', ClinicType::DentalClinic, false, self::BASE);

        /*
         * Hospitals run departments, and a department map has to exist before
         * staff can be assigned to one — so it lands before `admin_access`.
         */
        $this->template('Hospital onboarding', ClinicType::MultiSpecialityHospital, false, $this->withSteps(self::BASE, 3, [
            ['department_setup', 'Departments and specialities', 'Department map, speciality list and per-department leads.'],
            ['bed_and_theatre_setup', 'Beds and theatres', 'Inpatient capacity, theatre slots and scheduling rules.'],
        ]));

        $this->template('General hospital onboarding', ClinicType::GeneralHospital, false, $this->withSteps(self::BASE, 3, [
            ['department_setup', 'Departments and specialities', 'Department map, speciality list and per-department leads.'],
            ['bed_and_theatre_setup', 'Beds and theatres', 'Inpatient capacity, theatre slots and scheduling rules.'],
        ]));

        $this->template('Dental hospital onboarding', ClinicType::DentalHospital, false, $this->withSteps(self::BASE, 3, [
            ['department_setup', 'Departments and specialities', 'Department map and per-department leads.'],
        ]));

        /*
         * A diagnostic centre sells tests. Until the catalogue and the sample
         * workflow exist there is nothing for staff accounts to operate.
         */
        $this->template('Diagnostic centre onboarding', ClinicType::DiagnosticCenter, false, $this->withSteps(self::BASE, 3, [
            ['test_catalogue', 'Test catalogue', 'Panels, pricing and reference ranges.'],
            ['sample_workflow', 'Sample and reporting workflow', 'Collection points, turnaround times and report templates.'],
        ]));

        $this->template('Eye hospital onboarding', ClinicType::EyeHospital, false, $this->withSteps(self::BASE, 3, [
            ['department_setup', 'Departments and specialities', 'Department map and per-department leads.'],
            ['bed_and_theatre_setup', 'Theatres', 'Theatre slots and scheduling rules.'],
        ]));

        $this->template('IVF centre onboarding', ClinicType::IVF, false, $this->withSteps(self::BASE, 3, [
            ['cycle_protocols', 'Cycle protocols', 'Treatment protocols, consent forms and cycle stages.'],
            ['lab_setup', 'Embryology lab', 'Lab identifiers, storage and chain-of-custody rules.'],
        ]));

        $this->template('Physiotherapy onboarding', ClinicType::Physiotherapy, false, $this->withSteps(self::BASE, 3, [
            ['therapy_programmes', 'Therapy programmes', 'Programme templates, session counts and package pricing.'],
        ]));

        $this->template('Veterinary onboarding', ClinicType::Veterinary, false, $this->withSteps(self::BASE, 3, [
            ['species_and_species_protocols', 'Species and protocols', 'Species list, vaccination schedules and weight-based dosing.'],
        ]));

        $this->template('Custom healthcare onboarding', ClinicType::CustomHealthcare, false, self::BASE);
    }

    /**
     * Insert extra steps into the base sequence at `$position`.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $base
     * @param  list<array{0: string, 1: string, 2: string}>  $extra
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function withSteps(array $base, int $position, array $extra): array
    {
        return [...array_slice($base, 0, $position), ...$extra, ...array_slice($base, $position)];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $steps
     */
    private function template(string $name, ?ClinicType $clinicType, bool $isDefault, array $steps): void
    {
        $template = OnboardingTemplate::updateOrCreate(
            ['clinic_type' => $clinicType?->value],
            ['name' => $name, 'is_default' => $isDefault],
        );

        foreach ($steps as $position => [$key, $label, $description]) {
            $template->steps()->updateOrCreate(
                ['step_key' => $key],
                [
                    'label' => $label,
                    'description' => $description,
                    'position' => $position + 1,
                    'is_required' => true,
                ],
            );
        }

        /* A step removed from the definition should leave the template too. */
        $template->steps()
            ->whereNotIn('step_key', array_column($steps, 0))
            ->delete();
    }
}
