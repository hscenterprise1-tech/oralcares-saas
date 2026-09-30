<?php

use App\ClinicMembershipStatus;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\OnboardingProgress;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\OnboardingTemplateSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Helper: create a fully wired clinic user with organization, clinic, membership, and
 * onboarding progress — everything the middleware chain expects. The primary
 * owner holds every clinic permission; a plain member holds none.
 */
function createClinicUserWithContext(string $onboardingStep = 'password_setup', string $onboardingStatus = 'pending', bool $owner = true): array
{
    $org = Organization::factory()->create();
    $clinic = Clinic::factory()->create(['organization_id' => $org->id]);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $membership = ClinicMembership::factory();
    ($owner ? $membership->primaryOwner() : $membership)->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'status' => ClinicMembershipStatus::Active,
    ]);

    $progress = OnboardingProgress::create([
        'organization_id' => $org->id,
        'current_step' => $onboardingStep,
        'status' => $onboardingStatus,
        'completed_steps' => [],
    ]);

    return [$user, $org, $clinic, $progress];
}

beforeEach(function () {
    $this->seed(OnboardingTemplateSeeder::class);
});

// ─── Newly provisioned clinic ───────────────────────────────

it('sends a newly provisioned clinic to its setup checklist and renders it', function () {
    [$user] = createClinicUserWithContext('password_setup', 'pending');

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('clinic.onboarding.index'));

    $this->actingAs($user)->get(route('clinic.onboarding.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Clinic/Onboarding/Index')
            ->where('currentStep', 'password_setup')
            ->has('steps', 5)
            ->where('steps.0.key', 'password_setup')
            ->where('steps.0.done', false)
            ->where('canFinish', false)
            ->where('canManage', true));
});

// ─── Pending onboarding ─────────────────────────────────────

it('marks a template step as done and moves on to the next open step', function () {
    [$user, , , $progress] = createClinicUserWithContext('password_setup', 'pending');

    $this->actingAs($user)->post(route('clinic.onboarding.update'), ['step' => 'password_setup'])->assertRedirect();

    $progress->refresh();
    expect($progress->completed_steps)->toBe(['password_setup'])
        ->and($progress->current_step)->toBe('organization_profile')
        ->and($progress->status)->toBe('in_progress');
});

it('rejects a step that is not on the clinic onboarding template', function () {
    [$user, , , $progress] = createClinicUserWithContext('password_setup', 'pending');

    $this->actingAs($user)->post(route('clinic.onboarding.update'), ['step' => 'made_up_step'])
        ->assertSessionHasErrors('step');

    expect($progress->fresh()->completed_steps)->toBe([]);
});

it('does not finish onboarding while a required step is still open', function () {
    [$user, , , $progress] = createClinicUserWithContext('password_setup', 'pending');

    $this->actingAs($user)->post(route('clinic.onboarding.complete'))->assertSessionHasErrors('step');

    expect($progress->fresh()->status)->toBe('pending');
});

it('finishes onboarding once every required step is done and opens the portal', function () {
    [$user, , , $progress] = createClinicUserWithContext('password_setup', 'pending');

    foreach (['password_setup', 'organization_profile', 'branch_setup', 'admin_access', 'go_live'] as $step) {
        $this->actingAs($user)->post(route('clinic.onboarding.update'), ['step' => $step]);
    }

    $this->actingAs($user)->post(route('clinic.onboarding.complete'))->assertRedirect(route('dashboard'));

    $progress->refresh();
    expect($progress->status)->toBe('completed')
        ->and($progress->current_step)->toBe('completed');

    $this->actingAs($user)->get(route('clinic.dashboard'))->assertOk();
});

// ─── Completed onboarding ───────────────────────────────────

it('allows a fully-onboarded user to access the dashboard', function () {
    [$user] = createClinicUserWithContext('completed', 'completed');

    // `dashboard` is a bare redirect shim to `clinic.dashboard` (used as the
    // generic post-login landing name across the app) — completed onboarding
    // means it's not diverted to the onboarding wizard, and the real
    // dashboard behind it renders.
    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('clinic.dashboard'));

    $this->actingAs($user)->get(route('clinic.dashboard'))->assertStatus(200);
});

it('redirects a fully-onboarded user away from the onboarding wizard to the dashboard', function () {
    [$user] = createClinicUserWithContext('completed', 'completed');

    $this->actingAs($user)->get(route('clinic.onboarding.index'))->assertRedirect(route('dashboard'));
});

// ─── Unauthorized users ─────────────────────────────────────

it('shows the checklist read-only to a clinic member without settings.manage and refuses their changes', function () {
    [$user, , , $progress] = createClinicUserWithContext('password_setup', 'pending', owner: false);

    $this->actingAs($user)->get(route('clinic.onboarding.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canManage', false));

    $this->actingAs($user)->post(route('clinic.onboarding.update'), ['step' => 'password_setup'])->assertForbidden();
    $this->actingAs($user)->post(route('clinic.onboarding.complete'))->assertForbidden();

    expect($progress->fresh()->status)->toBe('pending')
        ->and($progress->fresh()->completed_steps)->toBe([]);
});

it('keeps a company user out of the clinic onboarding page', function () {
    createClinicUserWithContext('password_setup', 'pending');

    $this->actingAs(User::factory()->companyUser()->create())
        ->get(route('clinic.onboarding.index'))
        ->assertForbidden();

    $this->actingAs(User::factory()->companyUser()->create())
        ->post(route('clinic.onboarding.complete'))
        ->assertForbidden();
});

// ─── No redirect loop ───────────────────────────────────────

it('never loops between the dashboard and the onboarding page', function (string $status) {
    [$user] = createClinicUserWithContext('password_setup', $status);

    $first = $this->actingAs($user)->get(route('clinic.onboarding.index'));

    if ($status === 'completed') {
        $first->assertRedirect(route('dashboard'));
        $this->actingAs($user)->get(route('clinic.dashboard'))->assertOk();
    } else {
        $first->assertOk();
    }
})->with(['pending', 'in_progress', 'completed']);

it('lets a clinic with no onboarding record straight into the portal', function () {
    [$user, , , $progress] = createClinicUserWithContext('completed', 'completed');
    $progress->delete();

    $this->actingAs($user)->get(route('clinic.onboarding.index'))->assertRedirect(route('dashboard'));
    $this->actingAs($user)->get(route('clinic.dashboard'))->assertOk();
});
