<?php

use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\OwnerMode\OwnerMode;
use App\UserType;
use Tests\Support\ReceptionFixture;

/**
 * Signing in while another account is still signed in on the same browser
 * (a stale tab, a sign-out that never reached the server) replaces that
 * session: never a 500, and nothing of the previous sign-in (its clinic,
 * branch, Owner Mode or intended page) carries over to the new account.
 */
beforeEach(function () {
    $this->f = ReceptionFixture::make('switch-clinic');
    $this->f->owner->forceFill(['password' => bcrypt('owner-pass')])->save();
    $this->ownerSession = $this->f->session + [
        OwnerMode::SESSION_KEY => ['user_id' => $this->f->owner->id, 'membership_id' => $this->f->session[ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID]],
        'url.intended' => '/clinic/admin/settings',
    ];
});

test('a colleague signing in over the owner\'s open session gets their own session, not a 500', function () {
    [$receptionist] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $receptionist->forceFill(['password' => bcrypt('desk-pass')])->save();
    $membership = ClinicMembership::query()->where('user_id', $receptionist->id)->firstOrFail();

    $this->actingAs($this->f->owner)->withSession($this->ownerSession)
        ->post('/clinic/login', ['email' => $receptionist->email, 'password' => 'desk-pass'])
        ->assertRedirect('/clinic/dashboard-redirect')
        ->assertSessionHas(ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID, $membership->id)
        ->assertSessionMissing(OwnerMode::SESSION_KEY);

    $this->assertAuthenticatedAs($receptionist);
    // Reception's home is the Front Desk.
    $this->get('/clinic/dashboard-redirect')->assertRedirect(route('clinic.reception'));
});

test('an account from another clinic signing in over an open session lands in its own clinic, never the previous one', function () {
    $other = Clinic::factory()->create(['slug' => 'other-clinic']);
    $otherBranch = Branch::factory()->create(['clinic_id' => $other->id, 'is_main' => true]);
    $stranger = User::factory()->create(['user_type' => UserType::ClinicUser, 'password' => bcrypt('other-pass')]);
    $strangerMembership = ClinicMembership::factory()->create([
        'clinic_id' => $other->id, 'user_id' => $stranger->id, 'is_primary_owner' => true,
        'has_all_branches_access' => true, 'status' => ClinicMembershipStatus::Active,
    ]);

    $response = $this->actingAs($this->f->owner)->withSession($this->ownerSession)
        ->post('/clinic/login', ['email' => $stranger->email, 'password' => 'other-pass']);

    $response->assertRedirect('/clinic/dashboard-redirect')
        ->assertSessionHas(ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID, $strangerMembership->id)
        ->assertSessionMissing(ClinicContext::SESSION_BRANCH_ID)
        ->assertSessionMissing(OwnerMode::SESSION_KEY);
    $this->assertAuthenticatedAs($stranger);
    expect(session(ClinicContext::SESSION_BRANCH_ID))->not->toBe($this->f->branch->id)
        ->and($otherBranch->clinic_id)->toBe($other->id);
});

test('a wrong password over an open session is refused and leaves that session as it was', function () {
    [$receptionist] = $this->f->memberWithRole(ClinicRole::Receptionist);

    $this->actingAs($this->f->owner)->withSession($this->ownerSession)
        ->post('/clinic/login', ['email' => $receptionist->email, 'password' => 'not-the-password'])
        ->assertSessionHasErrors('email')
        ->assertSessionHas(ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID, $this->f->session[ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID]);

    $this->assertAuthenticatedAs($this->f->owner);
});

test('signing in again as the same account starts a fresh session in the same clinic', function () {
    $this->actingAs($this->f->owner)->withSession($this->ownerSession)
        ->post('/clinic/login', ['email' => $this->f->owner->email, 'password' => 'owner-pass'])
        ->assertRedirect('/clinic/dashboard-redirect')
        ->assertSessionHas(ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID, $this->f->session[ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID])
        ->assertSessionMissing(OwnerMode::SESSION_KEY);

    $this->assertAuthenticatedAs($this->f->owner);
});
