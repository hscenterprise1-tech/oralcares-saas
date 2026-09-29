<?php

use App\ClinicMembershipStatus;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\OnboardingProgress;
use App\Models\Organization;
use App\Models\User;

/**
 * Helper: create a fully wired clinic user with organization, clinic, membership, and
 * onboarding progress — everything the middleware chain expects.
 */
function createClinicUserWithContext(string $onboardingStep = 'password_setup', string $onboardingStatus = 'pending'): array
{
    $org = Organization::factory()->create();
    $clinic = Clinic::factory()->create(['organization_id' => $org->id]);
    $user = User::factory()->create(['organization_id' => $org->id]);

    ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'status' => ClinicMembershipStatus::Active,
    ]);

    $progress = OnboardingProgress::create([
        'organization_id' => $org->id,
        'current_step' => $onboardingStep,
        'status' => $onboardingStatus,
    ]);

    return [$user, $org, $clinic, $progress];
}

// ─── Middleware Guard Tests ─────────────────────────────────

it('redirects an incomplete-onboarding user away from the dashboard to the onboarding wizard', function () {
    [$user] = createClinicUserWithContext('organization_profile', 'pending');

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertRedirect(route('clinic.onboarding.index'));
});

it('allows a fully-onboarded user to access the dashboard', function () {
    [$user] = createClinicUserWithContext('completed', 'completed');

    // `dashboard` is a bare redirect shim to `clinic.dashboard` (used as the
    // generic post-login landing name across the app) — completed onboarding
    // means it's not diverted to the onboarding wizard, and the real
    // dashboard behind it renders.
    $response = $this->actingAs($user)->get(route('dashboard'));
    $response->assertRedirect(route('clinic.dashboard'));

    $this->actingAs($user)->get(route('clinic.dashboard'))->assertStatus(200);
});

it('redirects a fully-onboarded user away from the onboarding wizard to the dashboard', function () {
    [$user] = createClinicUserWithContext('completed', 'completed');

    $response = $this->actingAs($user)->get(route('clinic.onboarding.index'));

    $response->assertRedirect(route('dashboard'));
});

// ─── Step Progression Tests ─────────────────────────────────

it('can advance the onboarding step', function () {
    [$user, , , $progress] = createClinicUserWithContext('password_setup', 'pending');

    $response = $this->actingAs($user)->post(route('clinic.onboarding.update'), [
        'step' => 'organization_profile',
    ]);

    $response->assertRedirect();

    $progress->refresh();
    expect($progress->current_step)->toBe('organization_profile');
});

it('can complete the onboarding process', function () {
    [$user, , , $progress] = createClinicUserWithContext('clinical_preferences', 'pending');

    $response = $this->actingAs($user)->post(route('clinic.onboarding.complete'));

    $response->assertRedirect(route('dashboard'));

    $progress->refresh();
    expect($progress->status)->toBe('completed')
        ->and($progress->current_step)->toBe('completed');
});
