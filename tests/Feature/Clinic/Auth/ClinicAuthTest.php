<?php

use App\ClinicMembershipStatus;
use App\ClinicStatus;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\User;
use App\Services\ClinicContext;
use App\UserType;

test('clinic login screen renders successfully with clinic context', function () {
    $clinic = Clinic::factory()->create(['slug' => 'sunrise-dental', 'name' => 'Sunrise Dental']);

    $response = $this->get('/clinic/login?clinic=sunrise-dental');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Clinic/Auth/ClinicLogin')
        ->where('clinic', 'sunrise-dental')
        ->where('clinicName', 'Sunrise Dental')
        ->where('clinicLogo', null)
    );
});

test('clinic login screen shows no clinic name for a slug that matches no clinic', function () {
    $response = $this->get('/clinic/login?clinic=no-such-clinic');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Clinic/Auth/ClinicLogin')
        ->where('clinicName', null)
        ->where('clinicLogo', null)
    );
});

test('clinic login screen shows the clinic\'s own branding when set', function () {
    Clinic::factory()->create([
        'slug' => 'branded-dental',
        'name' => 'Branded Dental Legal Name',
        'display_name' => 'Branded Dental',
        'logo_path' => 'clinics/1/logo.png',
    ]);

    $response = $this->get('/clinic/login?clinic=branded-dental');

    $response->assertInertia(fn ($page) => $page
        ->component('Clinic/Auth/ClinicLogin')
        ->where('clinicName', 'Branded Dental')
        ->where('clinicLogo', fn ($url) => str_contains($url, 'clinics/1/logo.png'))
    );
});

test('clinic user with active membership can authenticate and redirects to dashboard', function () {
    $user = User::factory()->create([
        'user_type' => UserType::ClinicUser,
        'password' => bcrypt('password123'),
    ]);

    $clinic = Clinic::factory()->create(['slug' => 'apex-health']);
    ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'status' => ClinicMembershipStatus::Active,
    ]);

    $response = $this->post('/clinic/login', [
        'email' => $user->email,
        'password' => 'password123',
    ]);

    $response->assertRedirect('/clinic/dashboard');
    $this->assertAuthenticatedAs($user);
});

test('master admin or company user is rejected from clinic login', function () {
    $masterAdmin = User::factory()->create([
        'user_type' => UserType::MasterAdmin,
        'password' => bcrypt('password123'),
    ]);

    $response = $this->post('/clinic/login', [
        'email' => $masterAdmin->email,
        'password' => 'password123',
    ]);

    $response->assertSessionHasErrors(['email' => 'Unauthorized action. Clinic portal access required.']);
    $this->assertGuest();
});

test('user with inactive or missing clinic membership is rejected from clinic portal', function () {
    $user = User::factory()->create([
        'user_type' => UserType::ClinicUser,
        'password' => bcrypt('password123'),
    ]);

    $clinic = Clinic::factory()->create();
    ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'status' => ClinicMembershipStatus::Suspended,
    ]);

    $response = $this->post('/clinic/login', [
        'email' => $user->email,
        'password' => 'password123',
    ]);

    $response->assertSessionHasErrors(['email' => 'Your clinic membership is inactive or non-existent.']);
    $this->assertGuest();
});

test('clinic user can log out successfully', function () {
    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);

    $response = $this->actingAs($user)->post('/clinic/logout');

    $response->assertRedirect('/clinic/login');
    $this->assertGuest();
});

/*
 * Sign-in hardening: brute-force lockout, and binding the sign-in to the
 * clinic the page belongs to.
 */

/**
 * @return array{0: User, 1: Clinic}
 */
function clinicUserWithMembership(array $clinicAttributes = [], ClinicMembershipStatus $status = ClinicMembershipStatus::Active): array
{
    $user = User::factory()->create([
        'user_type' => UserType::ClinicUser,
        'password' => bcrypt('password123'),
    ]);
    $clinic = Clinic::factory()->create($clinicAttributes);
    ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'status' => $status,
    ]);

    return [$user, $clinic];
}

test('a wrong password is refused with a generic message and keeps the email', function () {
    [$user] = clinicUserWithMembership(['slug' => 'generic-dental']);

    $this->from('/clinic/login?clinic=generic-dental')
        ->post('/clinic/login?clinic=generic-dental', ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertRedirect('/clinic/login?clinic=generic-dental')
        ->assertSessionHasErrors(['email' => 'The provided credentials do not match our records.'])
        ->assertSessionHasInput('email', $user->email)
        ->assertSessionMissing('_old_input.password');

    $this->assertGuest();
});

test('an unknown email gets the same message as a wrong password', function () {
    $this->post('/clinic/login', ['email' => 'nobody@example.com', 'password' => 'whatever'])
        ->assertSessionHasErrors(['email' => 'The provided credentials do not match our records.']);
});

test('five failed attempts lock the email out, even with the right password', function () {
    [$user] = clinicUserWithMembership();

    foreach (range(1, 5) as $attempt) {
        $this->post('/clinic/login', ['email' => $user->email, 'password' => 'wrong-password']);
    }

    $this->post('/clinic/login', ['email' => $user->email, 'password' => 'password123'])
        ->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))->toStartWith('Too many login attempts.');

    $this->assertGuest();
});

test('the lockout is not sidestepped by changing the case of the email', function () {
    [$user] = clinicUserWithMembership();

    foreach (range(1, 5) as $attempt) {
        $this->post('/clinic/login', ['email' => $user->email, 'password' => 'wrong-password']);
    }

    $this->post('/clinic/login', ['email' => strtoupper($user->email), 'password' => 'password123'])
        ->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))->toStartWith('Too many login attempts.');
});

test('sign-in works again once the lockout window has passed', function () {
    [$user] = clinicUserWithMembership();

    foreach (range(1, 5) as $attempt) {
        $this->post('/clinic/login', ['email' => $user->email, 'password' => 'wrong-password']);
    }

    $this->travel(61)->seconds();

    $this->post('/clinic/login', ['email' => $user->email, 'password' => 'password123'])
        ->assertRedirect('/clinic/dashboard');
    $this->assertAuthenticatedAs($user);
});

test('one address is capped across many emails', function () {
    foreach (range(1, 30) as $attempt) {
        $this->post('/clinic/login', ['email' => "spray{$attempt}@example.com", 'password' => 'wrong-password']);
    }

    $this->post('/clinic/login', ['email' => 'spray31@example.com', 'password' => 'wrong-password'])
        ->assertStatus(429);
});

test('signing in to a named clinic opens that clinic', function () {
    [$user, $clinic] = clinicUserWithMembership(['slug' => 'named-dental']);

    $this->post('/clinic/login?clinic=named-dental', ['email' => $user->email, 'password' => 'password123'])
        ->assertRedirect('/clinic/dashboard');

    $this->assertAuthenticatedAs($user);
    expect(session(ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID))
        ->toBe(ClinicMembership::where('user_id', $user->id)->where('clinic_id', $clinic->id)->value('id'));
});

test('a correct password is refused on the sign-in page of a clinic the user does not belong to', function () {
    [$user] = clinicUserWithMembership(['slug' => 'own-dental']);
    Clinic::factory()->create(['slug' => 'other-dental']);

    $this->post('/clinic/login?clinic=other-dental', ['email' => $user->email, 'password' => 'password123'])
        ->assertSessionHasErrors(['email' => 'Your account does not have access to this clinic.']);

    $this->assertGuest();
});

test('a slug that matches no clinic signs no one in', function () {
    [$user] = clinicUserWithMembership(['slug' => 'real-dental']);

    $this->post('/clinic/login?clinic=made-up-clinic', ['email' => $user->email, 'password' => 'password123'])
        ->assertSessionHasErrors(['email' => 'Your account does not have access to this clinic.']);

    $this->assertGuest();
});

test('an inactive membership in the named clinic is refused', function () {
    [$user] = clinicUserWithMembership(['slug' => 'inactive-member-dental'], ClinicMembershipStatus::Inactive);

    $this->post('/clinic/login?clinic=inactive-member-dental', ['email' => $user->email, 'password' => 'password123'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('members of a suspended or inactive clinic cannot sign in', function (ClinicStatus $status) {
    [$user] = clinicUserWithMembership(['slug' => 'paused-dental', 'status' => $status]);

    $this->post('/clinic/login?clinic=paused-dental', ['email' => $user->email, 'password' => 'password123'])
        ->assertSessionHasErrors('email');
    $this->post('/clinic/login', ['email' => $user->email, 'password' => 'password123'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
})->with([ClinicStatus::Suspended, ClinicStatus::Inactive]);

test('a member of several clinics signs in to the one named, not the first they joined', function () {
    [$user] = clinicUserWithMembership(['slug' => 'first-dental']);
    $secondClinic = Clinic::factory()->create(['slug' => 'second-dental']);
    $secondMembership = ClinicMembership::factory()->create([
        'clinic_id' => $secondClinic->id,
        'user_id' => $user->id,
        'status' => ClinicMembershipStatus::Active,
    ]);

    $this->post('/clinic/login?clinic=second-dental', ['email' => $user->email, 'password' => 'password123'])
        ->assertRedirect('/clinic/dashboard');

    expect(session(ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID))->toBe($secondMembership->id);
});

test('an open session loses the portal when its clinic is suspended', function () {
    [$user, $clinic] = clinicUserWithMembership(['slug' => 'suspended-later-dental', 'status' => ClinicStatus::Suspended]);
    $membership = ClinicMembership::where('user_id', $user->id)->sole();

    // A session that was bound to the clinic before it was suspended.
    $this->actingAs($user)
        ->withSession([ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id])
        ->get('/clinic/dashboard?clinic=suspended-later-dental')
        ->assertForbidden();
});

test('signing in issues a new session id', function () {
    [$user] = clinicUserWithMembership();

    $this->get('/clinic/login');
    $before = session()->getId();

    $this->post('/clinic/login', ['email' => $user->email, 'password' => 'password123']);

    expect(session()->getId())->not->toBe($before);
});
