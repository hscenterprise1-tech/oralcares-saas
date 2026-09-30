<?php

use App\ClinicMembershipStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\Organization;
use App\Models\User;
use App\Services\ClinicContext;
use App\UserType;

/**
 * @return array{user: User, membership: ClinicMembership, branch: Branch}
 */
function receptionist(): array
{
    $organization = Organization::factory()->create();
    $clinic = Clinic::factory()->create(['organization_id' => $organization->id]);
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $organization->id]);
    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id, 'user_id' => $user->id, 'status' => ClinicMembershipStatus::Active,
        'has_all_branches_access' => true, 'is_primary_owner' => true,
    ]);

    return compact('user', 'membership', 'branch');
}

function lookUp($test, array $who)
{
    return $test->actingAs($who['user'])->withSession([
        ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $who['membership']->id,
        ClinicContext::SESSION_BRANCH_ID => $who['branch']->id,
    ])->getJson('/clinic/search/patients?q=ra');
}

beforeEach(function () {
    config(['security.rate_limits.patient_lookup' => 3, 'security.rate_limits.notifications' => 2, 'security.rate_limits.geo' => 2]);
});

it('allows patient lookups up to the limit, then throttles that user', function () {
    $desk = receptionist();

    foreach (range(1, 3) as $request) {
        expect(lookUp($this, $desk)->status())->not->toBe(429);
    }

    lookUp($this, $desk)->assertStatus(429);
});

it('throttles one clinic\'s user without affecting another clinic', function () {
    $busy = receptionist();
    $other = receptionist();

    foreach (range(1, 4) as $request) {
        lookUp($this, $busy);
    }

    lookUp($this, $busy)->assertStatus(429);
    expect(lookUp($this, $other)->status())->not->toBe(429);
});

it('keeps separate budgets for separate endpoint groups', function () {
    $user = User::factory()->create();

    foreach (range(1, 2) as $request) {
        $this->actingAs($user)->getJson('/api/notifications/unread-count')->assertSuccessful();
    }

    $this->actingAs($user)->getJson('/api/notifications/unread-count')->assertStatus(429);
    expect($this->actingAs($user)->getJson('/api/v1/geo/countries')->status())->not->toBe(429);
});

it('still requires sign-in before any budget is spent', function () {
    $this->getJson('/api/notifications/unread-count')->assertUnauthorized();
});
