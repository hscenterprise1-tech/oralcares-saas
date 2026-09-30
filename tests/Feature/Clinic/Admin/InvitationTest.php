<?php

use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicEmployeeInvitation;
use App\Models\ClinicMembership;
use App\Models\CompanySubscription;
use App\Models\OnboardingProgress;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

function setupInvitationTest()
{
    $org = Organization::factory()->create();
    $clinic = Clinic::factory()->create(['organization_id' => $org->id]);
    $user = User::factory()->create(['organization_id' => $org->id]);

    ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'status' => ClinicMembershipStatus::Active,
        'has_all_branches_access' => true,
        // Admin pages require staff.manage / branch.manage; the owner always holds them.
        'is_primary_owner' => true,
    ]);

    OnboardingProgress::create([
        'organization_id' => $org->id,
        'current_step' => 'completed',
        'status' => 'completed',
    ]);

    // Seat-limit enforcement reads the clinic's active subscription —
    // without one, ResourceLimitService fails closed ("no active
    // subscription") and every invite/accept in this file is refused.
    CompanySubscription::factory()->create(['clinic_id' => $clinic->id]);

    return [$user, $clinic];
}

it('can create an invitation for an employee', function () {
    [$user, $clinic] = setupInvitationTest();

    $employee = ClinicEmployee::create([
        'clinic_id' => $clinic->id,
        'name' => 'Invited Staff',
        'email' => 'invite@example.com',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->post(route('admin.invitations.store'), [
        'clinic_employee_id' => $employee->id,
        'email' => 'invite@example.com',
    ]);

    $response->assertRedirect();
    expect(ClinicEmployeeInvitation::where('email', 'invite@example.com')->exists())->toBeTrue();
});

it('can revoke an invitation', function () {
    [$user, $clinic] = setupInvitationTest();

    $employee = ClinicEmployee::create([
        'clinic_id' => $clinic->id,
        'name' => 'Revoke Staff',
        'email' => 'revoke@example.com',
        'status' => 'active',
    ]);

    $invitation = ClinicEmployeeInvitation::create([
        'clinic_id' => $clinic->id,
        'clinic_employee_id' => $employee->id,
        'email' => 'revoke@example.com',
        'token' => hash('sha256', 'dummytoken'),
        'expires_at' => now()->addDays(3),
        'created_by' => $user->id,
    ]);

    $response = $this->actingAs($user)->post(route('admin.invitations.revoke', $invitation->id));

    $response->assertRedirect();
    expect($invitation->fresh()->revoked_at)->not->toBeNull();
});

it('can accept an invitation and setup password', function () {
    [$user, $clinic] = setupInvitationTest();

    $employee = ClinicEmployee::create([
        'clinic_id' => $clinic->id,
        'name' => 'Accept Staff',
        'email' => 'accept@example.com',
        'status' => 'active',
    ]);

    $token = Str::random(60);
    $invitation = ClinicEmployeeInvitation::create([
        'clinic_id' => $clinic->id,
        'clinic_employee_id' => $employee->id,
        'email' => 'accept@example.com',
        'token' => hash('sha256', $token),
        'expires_at' => now()->addDays(3),
        'created_by' => $user->id,
    ]);

    $acceptUrl = URL::temporarySignedRoute(
        'clinic.invitation.accept',
        now()->addDays(3),
        ['token' => $token]
    );

    $response = $this->post($acceptUrl, [
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $response->assertRedirect(route('dashboard'));

    // User should be created
    $newUser = User::where('email', 'accept@example.com')->first();
    expect($newUser)->not->toBeNull();

    // Invitation should be accepted
    expect($invitation->fresh()->accepted_at)->not->toBeNull();

    // Employee should be linked to user
    expect($employee->fresh()->user_id)->toBe($newUser->id);
});

it('starts the invitee on a new session id when they accept', function () {
    [$user, $clinic] = setupInvitationTest();

    $employee = ClinicEmployee::create([
        'clinic_id' => $clinic->id,
        'name' => 'Session Staff',
        'email' => 'session@example.com',
        'status' => 'active',
    ]);

    $token = Str::random(60);
    ClinicEmployeeInvitation::create([
        'clinic_id' => $clinic->id,
        'clinic_employee_id' => $employee->id,
        'email' => 'session@example.com',
        'token' => hash('sha256', $token),
        'expires_at' => now()->addDays(3),
        'created_by' => $user->id,
    ]);

    $acceptUrl = URL::temporarySignedRoute('clinic.invitation.accept', now()->addDays(3), ['token' => $token]);

    $this->get($acceptUrl);
    $before = session()->getId();

    $this->post($acceptUrl, [
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    expect(session()->getId())->not->toBe($before);
});

it('can accept an invitation for an employee with an assigned branch and role', function () {
    [$user, $clinic] = setupInvitationTest();

    $branch = Branch::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $clinic->organization_id]);

    setPermissionsTeamId(null);
    $role = Role::create(['name' => ClinicRole::Receptionist->value, 'guard_name' => 'web', 'branch_id' => null]);

    $employee = ClinicEmployee::create([
        'clinic_id' => $clinic->id,
        'name' => 'Branched Staff',
        'email' => 'branched@example.com',
        'status' => 'active',
    ]);
    $employee->branches()->sync([$branch->id]);
    $employee->roles()->sync([$role->id]);

    $token = Str::random(60);
    ClinicEmployeeInvitation::create([
        'clinic_id' => $clinic->id,
        'clinic_employee_id' => $employee->id,
        'email' => 'branched@example.com',
        'token' => hash('sha256', $token),
        'expires_at' => now()->addDays(3),
        'created_by' => $user->id,
    ]);

    $acceptUrl = URL::temporarySignedRoute('clinic.invitation.accept', now()->addDays(3), ['token' => $token]);

    $response = $this->post($acceptUrl, [
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $response->assertRedirect(route('dashboard'));

    $newUser = User::where('email', 'branched@example.com')->first();
    expect($newUser)->not->toBeNull();

    $membership = ClinicMembership::where('user_id', $newUser->id)->first();
    expect($membership)->not->toBeNull()
        ->and($membership->assignedBranches->pluck('id')->all())->toBe([$branch->id]);
});

it('shows the valid invitation state with the employee and clinic names', function () {
    [$user, $clinic] = setupInvitationTest();

    $employee = ClinicEmployee::create([
        'clinic_id' => $clinic->id,
        'name' => 'Preview Staff',
        'email' => 'preview@example.com',
        'status' => 'active',
    ]);

    $token = Str::random(60);
    ClinicEmployeeInvitation::create([
        'clinic_id' => $clinic->id,
        'clinic_employee_id' => $employee->id,
        'email' => 'preview@example.com',
        'token' => hash('sha256', $token),
        'expires_at' => now()->addDays(3),
        'created_by' => $user->id,
    ]);

    $acceptUrl = URL::temporarySignedRoute('clinic.invitation.accept', now()->addDays(3), ['token' => $token]);

    $this->get($acceptUrl)->assertInertia(fn ($page) => $page
        ->component('Clinic/Auth/AcceptInvitation')
        ->where('status', 'valid')
        ->where('employeeName', 'Preview Staff')
        ->where('email', 'preview@example.com')
        ->where('roleName', null)
        ->where('clinicName', $clinic->name)
        ->where('clinicLogo', null)
    );
});

it('tells the invitee which role they are being invited as', function () {
    [$user, $clinic] = setupInvitationTest();

    setPermissionsTeamId(null);
    $role = Role::create(['name' => ClinicRole::Receptionist->value, 'guard_name' => 'web', 'branch_id' => null]);

    $employee = ClinicEmployee::create([
        'clinic_id' => $clinic->id,
        'name' => 'Role Preview Staff',
        'email' => 'role-preview@example.com',
        'status' => 'active',
    ]);
    $employee->roles()->sync([$role->id]);

    $token = Str::random(60);
    ClinicEmployeeInvitation::create([
        'clinic_id' => $clinic->id,
        'clinic_employee_id' => $employee->id,
        'email' => 'role-preview@example.com',
        'token' => hash('sha256', $token),
        'expires_at' => now()->addDays(3),
        'created_by' => $user->id,
    ]);

    $acceptUrl = URL::temporarySignedRoute('clinic.invitation.accept', now()->addDays(3), ['token' => $token]);

    $this->get($acceptUrl)->assertInertia(fn ($page) => $page
        ->component('Clinic/Auth/AcceptInvitation')
        ->where('roleName', ClinicRole::Receptionist->value)
    );
});

it('shows the clinic\'s own branding on the invitation-accept page when set', function () {
    [$user, $clinic] = setupInvitationTest();
    $clinic->update(['display_name' => 'Branded Dental', 'logo_path' => 'clinics/1/logo.png']);

    $employee = ClinicEmployee::create([
        'clinic_id' => $clinic->id,
        'name' => 'Branded Preview Staff',
        'email' => 'branded-preview@example.com',
        'status' => 'active',
    ]);

    $token = Str::random(60);
    ClinicEmployeeInvitation::create([
        'clinic_id' => $clinic->id,
        'clinic_employee_id' => $employee->id,
        'email' => 'branded-preview@example.com',
        'token' => hash('sha256', $token),
        'expires_at' => now()->addDays(3),
        'created_by' => $user->id,
    ]);

    $acceptUrl = URL::temporarySignedRoute('clinic.invitation.accept', now()->addDays(3), ['token' => $token]);

    $this->get($acceptUrl)->assertInertia(fn ($page) => $page
        ->where('clinicName', 'Branded Dental')
        ->where('clinicLogo', fn ($url) => str_contains($url, 'clinics/1/logo.png'))
    );
});

it('shows the invalid state for a tampered signature instead of aborting', function () {
    [$user, $clinic] = setupInvitationTest();

    $employee = ClinicEmployee::create([
        'clinic_id' => $clinic->id,
        'name' => 'Tampered Staff',
        'email' => 'tampered@example.com',
        'status' => 'active',
    ]);

    ClinicEmployeeInvitation::create([
        'clinic_id' => $clinic->id,
        'clinic_employee_id' => $employee->id,
        'email' => 'tampered@example.com',
        'token' => hash('sha256', 'sometoken'),
        'expires_at' => now()->addDays(3),
        'created_by' => $user->id,
    ]);

    $response = $this->get(route('clinic.invitation.accept', ['token' => 'sometoken']));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Clinic/Auth/AcceptInvitation')
        ->where('status', 'invalid')
    );
});

it('shows the invalid state for an expired invitation', function () {
    [$user, $clinic] = setupInvitationTest();

    $employee = ClinicEmployee::create([
        'clinic_id' => $clinic->id,
        'name' => 'Expired Staff',
        'email' => 'expired@example.com',
        'status' => 'active',
    ]);

    $token = Str::random(60);
    ClinicEmployeeInvitation::create([
        'clinic_id' => $clinic->id,
        'clinic_employee_id' => $employee->id,
        'email' => 'expired@example.com',
        'token' => hash('sha256', $token),
        'expires_at' => now()->subDay(),
        'created_by' => $user->id,
    ]);

    $acceptUrl = URL::temporarySignedRoute('clinic.invitation.accept', now()->addDays(3), ['token' => $token]);

    $this->get($acceptUrl)->assertInertia(fn ($page) => $page
        ->component('Clinic/Auth/AcceptInvitation')
        ->where('status', 'invalid')
    );
});

it('blocks accepting with an invitee-facing message when the staff seat limit is reached', function () {
    [$user, $clinic] = setupInvitationTest();

    // Tighten the staff cap below what's already consumed by the admin's
    // own membership, so the *first* sub-cap check (`enforceStaffLimit`,
    // since this employee has no Doctor role) fails at accept time.
    CompanySubscription::where('clinic_id', $clinic->id)->update(['max_staff' => 0]);

    $employee = ClinicEmployee::create([
        'clinic_id' => $clinic->id,
        'name' => 'Blocked Staff',
        'email' => 'blocked@example.com',
        'status' => 'active',
    ]);

    $token = Str::random(60);
    $invitation = ClinicEmployeeInvitation::create([
        'clinic_id' => $clinic->id,
        'clinic_employee_id' => $employee->id,
        'email' => 'blocked@example.com',
        'token' => hash('sha256', $token),
        'expires_at' => now()->addDays(3),
        'created_by' => $user->id,
    ]);

    $acceptUrl = URL::temporarySignedRoute('clinic.invitation.accept', now()->addDays(3), ['token' => $token]);

    $response = $this->from($acceptUrl)->post($acceptUrl, [
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $response->assertRedirect($acceptUrl);
    $response->assertSessionHas('error');
    expect(session('error'))
        ->toContain("plan's staff limit")
        ->toContain('contact the clinic admin who invited you')
        ->not->toContain('account manager');

    expect(User::where('email', 'blocked@example.com')->exists())->toBeFalse();
    expect($invitation->fresh()->accepted_at)->toBeNull();
});
