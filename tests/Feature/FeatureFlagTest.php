<?php

use App\Models\Organization;
use App\Models\User;
use App\UserType;
use Laravel\Pennant\Feature;

beforeEach(function () {
    $this->org = Organization::factory()->create();
    $this->user = User::factory()->create(['user_type' => UserType::ClinicUser, 'organization_id' => $this->org->id]);

    // Define a test feature
    Feature::define('beta-dashboard', function (User $user) {
        // Just for testing, enable if user's email ends with @example.com
        return str_ends_with($user->email, '@example.com');
    });

    // Define an organization level feature
    Feature::define('enterprise-search', function (Organization $organization) {
        return $organization->plan_name === 'enterprise';
    });
});

it('evaluates user level features correctly', function () {
    $allowedUser = User::factory()->create(['email' => 'test@example.com']);
    $deniedUser = User::factory()->create(['email' => 'test@other.com']);

    expect(Feature::for($allowedUser)->active('beta-dashboard'))->toBeTrue();
    expect(Feature::for($deniedUser)->active('beta-dashboard'))->toBeFalse();
});

it('evaluates organization level features correctly', function () {
    // Note: plan_name doesn't exist on Organization model by default, but we can simulate logic
    Feature::define('org-feature', function (Organization $org) {
        return $org->id === $this->org->id;
    });

    $otherOrg = Organization::factory()->create();

    expect(Feature::for($this->org)->active('org-feature'))->toBeTrue();
    expect(Feature::for($otherOrg)->active('org-feature'))->toBeFalse();
});

it('persists feature state if requested', function () {
    Feature::for($this->user)->activate('beta-dashboard');

    expect(Feature::for($this->user)->active('beta-dashboard'))->toBeTrue();

    Feature::for($this->user)->deactivate('beta-dashboard');

    expect(Feature::for($this->user)->active('beta-dashboard'))->toBeFalse();
});
