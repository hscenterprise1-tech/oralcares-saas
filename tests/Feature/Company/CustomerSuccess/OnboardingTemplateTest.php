<?php

declare(strict_types=1);

namespace Tests\Feature\Company\CustomerSuccess;

use App\ClinicType;
use App\Models\OnboardingProgress;
use App\Models\OnboardingTemplate;
use App\Models\Organization;
use App\Models\User;
use App\UserType;
use Database\Seeders\OnboardingTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Getting a customer live, per kind of customer.
 *
 * The five steps used to be an array of labels inside the React page: the
 * server could not name them, no test could reach them, and a single dental
 * clinic and a multi-speciality hospital were handed the same checklist. These
 * pin the sequence, the resolution, and the two things that must not break —
 * an account's progress surviving a template edit, and go-live working even
 * when no template resolves at all.
 */
beforeEach(function () {
    setPermissionsTeamId(0);
    $this->seed(OnboardingTemplateSeeder::class);
    $this->admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);
});

it('gives a hospital more steps than a dental clinic', function () {
    $hospital = OnboardingTemplate::forClinicType(ClinicType::MultiSpecialityHospital);
    $clinic = OnboardingTemplate::forClinicType(ClinicType::DentalClinic);

    expect($hospital->steps->pluck('step_key'))->toContain('department_setup')
        ->and($hospital->steps->pluck('step_key'))->toContain('bed_and_theatre_setup')
        ->and($clinic->steps->pluck('step_key'))->not->toContain('department_setup')
        ->and($hospital->steps)->toHaveCount(7)
        ->and($clinic->steps)->toHaveCount(5);
});

it('puts a hospital department map before its staff accounts', function () {
    /*
     * Order is the point of a sequence, not decoration: staff are assigned to
     * departments, so a department map that arrives afterwards is a step
     * nobody can complete when they reach it.
     */
    $keys = OnboardingTemplate::forClinicType(ClinicType::MultiSpecialityHospital)
        ->steps->pluck('step_key')->all();

    expect(array_search('department_setup', $keys, true))
        ->toBeLessThan(array_search('admin_access', $keys, true));
});

it('falls back to the default sequence for a type with no template of its own', function () {
    $template = OnboardingTemplate::forClinicType('Something Nobody Has Modelled');

    expect($template)->not->toBeNull()
        ->and($template->is_default)->toBeTrue()
        ->and($template->steps)->toHaveCount(5);
});

it('falls back to the default when an account has no clinic type at all', function () {
    expect(OnboardingTemplate::forClinicType(null)?->is_default)->toBeTrue();
});

it('resolves and holds the template when onboarding starts', function () {
    $org = Organization::factory()->customer()->create([
        'clinic_type' => ClinicType::MultiSpecialityHospital,
    ]);

    $this->actingAs($this->admin)
        ->post(route('company.customer-success.accounts.onboarding.complete-step', $org), [
            'step' => 'password_setup',
            'next_step' => 'organization_profile',
        ])
        ->assertSessionHasNoErrors();

    $progress = OnboardingProgress::where('organization_id', $org->id)->sole();

    expect($progress->onboarding_template_id)->not->toBeNull()
        ->and($progress->template->clinic_type)->toBe(ClinicType::MultiSpecialityHospital);
});

it('keeps an account on the sequence it started, even after the template changes', function () {
    /*
     * A template is held on the progress row rather than resolved on every
     * read. Editing one must not rewrite the checklist of every account already
     * half way through it.
     */
    $org = Organization::factory()->customer()->create(['clinic_type' => ClinicType::DentalClinic]);
    $original = OnboardingTemplate::forClinicType(ClinicType::DentalClinic);

    $progress = OnboardingProgress::create([
        'organization_id' => $org->id,
        'onboarding_template_id' => $original->id,
        'current_step' => 'password_setup',
        'status' => 'in_progress',
        'completed_steps' => ['password_setup'],
    ]);

    /* The account's clinic type is corrected afterwards. */
    $org->update(['clinic_type' => ClinicType::MultiSpecialityHospital]);

    expect($progress->fresh()->steps()->pluck('step_key'))->not->toContain('department_setup');
});

it('marks go-live against every step of this account own template', function () {
    $org = Organization::factory()->customer()->create([
        'clinic_type' => ClinicType::MultiSpecialityHospital,
    ]);

    OnboardingProgress::create([
        'organization_id' => $org->id,
        'onboarding_template_id' => OnboardingTemplate::forClinicType(ClinicType::MultiSpecialityHospital)->id,
        'current_step' => 'password_setup',
        'status' => 'in_progress',
        'completed_steps' => [],
    ]);

    $this->actingAs($this->admin)
        ->post(route('company.customer-success.accounts.onboarding.mark-go-live', $org))
        ->assertSessionHasNoErrors();

    $completed = OnboardingProgress::where('organization_id', $org->id)->sole()->completed_steps;

    /*
     * All seven, not the dental five. Marking a hospital live against the
     * shorter list left two of its steps permanently unfinished.
     */
    expect($completed)->toHaveCount(7)
        ->and($completed)->toContain('department_setup')
        ->and($completed)->toContain('bed_and_theatre_setup');
});

it('still goes live when no template resolves at all', function () {
    /*
     * A missing template must never be the difference between go-live working
     * and silently doing nothing — a fresh database, or a clinic type added
     * before somebody wrote its sequence.
     */
    OnboardingTemplate::query()->delete();

    $org = Organization::factory()->customer()->create();

    $this->actingAs($this->admin)
        ->post(route('company.customer-success.accounts.onboarding.mark-go-live', $org))
        ->assertSessionHasNoErrors();

    $progress = OnboardingProgress::where('organization_id', $org->id)->sole();

    expect($progress->status)->toBe('completed')
        ->and($progress->completed_steps)->toContain('go_live');
});

it('tells the account page which sequence it is showing', function () {
    $org = Organization::factory()->customer()->create([
        'clinic_type' => ClinicType::DiagnosticCenter,
    ]);

    $this->actingAs($this->admin)
        ->get(route('company.customer-success.accounts.show', ['organization' => $org, 'tab' => 'onboarding']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(['setup'], fn ($page) => $page
                ->where('onboarding.template_name', 'Diagnostic centre onboarding')
                ->has('onboarding.steps', 7)
                ->where('onboarding.steps.3.key', 'test_catalogue')
                ->where('onboarding.required_steps_complete', false)
            )
        );
});

it('re-seeds without duplicating a template or its steps', function () {
    $this->seed(OnboardingTemplateSeeder::class);
    $this->seed(OnboardingTemplateSeeder::class);

    expect(OnboardingTemplate::where('clinic_type', ClinicType::DentalClinic->value)->count())->toBe(1)
        ->and(OnboardingTemplate::forClinicType(ClinicType::DentalClinic)->steps)->toHaveCount(5);
});
