<?php

use App\ClinicMembershipStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\CompanySubscription;
use App\Models\Organization;
use App\Models\SecurityAuditLog;
use App\Models\User;
use App\Services\ClinicContext;
use App\UserType;

it('changes nothing on a dry run', function () {
    Clinic::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    $this->artisan('subscriptions:backfill-legacy')->assertExitCode(0);

    expect(CompanySubscription::count())->toBe(0);
});

it('gives a legacy clinic a fallback subscription that keeps its access', function () {
    $organization = Organization::factory()->create();
    $clinic = Clinic::factory()->create(['organization_id' => $organization->id]);
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $organization->id]);
    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id, 'user_id' => $user->id, 'status' => ClinicMembershipStatus::Active,
        'has_all_branches_access' => true, 'is_primary_owner' => true,
    ]);

    $this->artisan('subscriptions:backfill-legacy', ['--apply' => true])->assertExitCode(0);

    $subscription = CompanySubscription::where('clinic_id', $clinic->id)->sole();
    expect($subscription->company_plan_id)->toBeNull()
        ->and($subscription->max_branches)->toBeGreaterThanOrEqual(1)
        ->and(SecurityAuditLog::where('event', 'subscription.backfilled')->sole()->context)->toBe(['mode' => 'created_legacy']);

    // No lockout: the plan layer now applies, and the fallback modules cover
    // what the clinic could already reach.
    foreach (['/clinic/patients', '/clinic/appointments'] as $page) {
        $this->actingAs($user)->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id,
            ClinicContext::SESSION_BRANCH_ID => $branch->id,
        ])->get($page)->assertOk();
    }
});

it('links an organization\'s single unlinked subscription to its only clinic', function () {
    $organization = Organization::factory()->create();
    $clinic = Clinic::factory()->create(['organization_id' => $organization->id]);
    $orphan = CompanySubscription::factory()->create(['organization_id' => $organization->id, 'clinic_id' => null]);
    CompanySubscription::whereKey($orphan->id)->update(['clinic_id' => null]);

    $this->artisan('subscriptions:backfill-legacy', ['--apply' => true])->assertExitCode(0);

    expect($orphan->fresh()->clinic_id)->toBe($clinic->id)
        ->and(CompanySubscription::count())->toBe(1);
});

it('leaves clinics that already have a subscription untouched', function () {
    $clinic = Clinic::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    $existing = CompanySubscription::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $clinic->organization_id]);

    $this->artisan('subscriptions:backfill-legacy', ['--apply' => true])->assertExitCode(0);

    expect(CompanySubscription::where('clinic_id', $clinic->id)->pluck('id')->all())->toBe([$existing->id]);
});

it('skips and reports a clinic with no organization', function () {
    Clinic::factory()->create(['organization_id' => null, 'name' => 'Orphan Dental']);

    $this->artisan('subscriptions:backfill-legacy', ['--apply' => true])
        ->expectsOutputToContain('SKIPPED')
        ->assertExitCode(0);

    expect(CompanySubscription::count())->toBe(0);
});
