<?php

use App\ClinicMembershipStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\CompanySubscription;
use App\Models\OnboardingProgress;
use App\Models\Organization;
use App\Models\User;
use App\Services\ClinicContext;
use App\UserType;

/*
 * One login can belong to clinics of different organisations, while
 * `users.organization_id` holds one. The organisation boundary must follow
 * the clinic being worked in, or the second clinic's organisation-scoped
 * records (branches, invoices, histories) disappear and the first
 * organisation's appear in their place.
 */

/** @return array{0: Clinic, 1: Branch, 2: ClinicMembership} */
function ownedClinicIn(Organization $organization, User $owner, string $branchName): array
{
    $clinic = Clinic::factory()->create(['organization_id' => $organization->id]);
    OnboardingProgress::create(['organization_id' => $organization->id, 'current_step' => 'completed', 'status' => 'completed']);
    CompanySubscription::factory()->create(['clinic_id' => $clinic->id]);
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $organization->id, 'name' => $branchName, 'is_main' => true]);
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id, 'user_id' => $owner->id, 'status' => ClinicMembershipStatus::Active,
        'has_all_branches_access' => true, 'is_primary_owner' => true,
    ]);

    return [$clinic, $branch, $membership];
}

it('shows the branches of the clinic being worked in, not of the organisation on the user row', function () {
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();
    $owner = User::factory()->create(['user_type' => UserType::ClinicUser, 'organization_id' => $first->id]);
    ownedClinicIn($first, $owner, 'First Org Branch');
    [, $secondBranch, $secondMembership] = ownedClinicIn($second, $owner, 'Second Org Branch');
    entersOrganizationScope();

    $this->actingAs($owner)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $secondMembership->id,
            ClinicContext::SESSION_BRANCH_ID => $secondBranch->id,
        ])
        ->get(route('admin.branches.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('branches', 1)
            ->where('branches.0.name', 'Second Org Branch'));
});

it('stamps a record created in the second clinic with that clinic\'s organisation', function () {
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();
    $owner = User::factory()->create(['user_type' => UserType::ClinicUser, 'organization_id' => $first->id]);
    [$secondClinic, , $secondMembership] = ownedClinicIn($second, $owner, 'Second Org Branch');
    $this->actingAs($owner);
    app(ClinicContext::class)->setUser($owner);
    app(ClinicContext::class)->setMembership($secondMembership);

    $branch = Branch::create(['clinic_id' => $secondClinic->id, 'name' => 'Annexe', 'slug' => 'annexe']);

    expect($branch->organization_id)->toBe($second->id);
});
