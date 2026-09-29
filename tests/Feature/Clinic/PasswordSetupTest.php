<?php

use App\ClinicMembershipStatus;
use App\ClinicPasswordTokenPurpose;
use App\Mail\PasswordSetupMailable;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\ClinicPasswordToken;
use App\Models\OnboardingProgress;
use App\Models\Organization;
use App\Models\User;
use App\Services\Auth\ClinicPasswordTokenService;
use Illuminate\Support\Facades\Hash;

function issueSetupToken(User $user): string
{
    return app(ClinicPasswordTokenService::class)->issue($user, ClinicPasswordTokenPurpose::Setup);
}

/**
 * @return array{0: User, 1: Clinic}
 */
function ownerAwaitingPasswordSetup(): array
{
    $org = Organization::factory()->create();
    $clinic = Clinic::factory()->create(['organization_id' => $org->id]);
    $user = User::factory()->create([
        'email' => 'owner-setup@example.com',
        'password' => 'old_password',
        'organization_id' => $org->id,
    ]);

    ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'status' => ClinicMembershipStatus::Active,
    ]);

    OnboardingProgress::create([
        'organization_id' => $org->id,
        'current_step' => 'password_setup',
        'status' => 'pending',
    ]);

    return [$user, $clinic];
}

it('shows the setup form for a valid token', function () {
    $user = User::factory()->create(['email' => 'test-setup@example.com']);

    $this->get(route('clinic.password.setup', ['token' => issueSetupToken($user)]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Clinic/Auth/SetupPassword')
            ->where('status', 'valid')
            ->where('mode', 'setup')
            ->where('email', 'test-setup@example.com')
        );
});

it('passes the owning clinic\'s branding to the password setup page', function () {
    $org = Organization::factory()->create();
    Clinic::factory()->create([
        'organization_id' => $org->id,
        'display_name' => 'Branded Dental',
        'logo_path' => 'clinics/1/logo.png',
    ]);
    $user = User::factory()->create(['email' => 'branded-setup@example.com', 'organization_id' => $org->id]);

    $this->get(route('clinic.password.setup', ['token' => issueSetupToken($user)]))
        ->assertInertia(fn ($page) => $page
            ->where('clinicName', 'Branded Dental')
            ->where('clinicLogo', fn ($url) => str_contains($url, 'clinics/1/logo.png'))
        );
});

it('shows the invalid-link state for a token that was never issued', function () {
    User::factory()->create();

    $this->get(route('clinic.password.setup', ['token' => str_repeat('a', 64)]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Clinic/Auth/SetupPassword')
            ->where('status', 'invalid')
            ->missing('email')
        );
});

it('does not accept a link built from the user\'s email, as the old sha1 link was', function () {
    $user = User::factory()->create(['email' => 'derivable@example.com']);
    issueSetupToken($user);

    $this->get('/clinic/password/setup/'.sha1($user->email))
        ->assertInertia(fn ($page) => $page->where('status', 'invalid'));
    $this->get('/clinic/password/setup/'.$user->id.'/'.sha1($user->email))
        ->assertNotFound();
});

it('stores only a hash of the token, never the token itself', function () {
    $user = User::factory()->create();
    $token = issueSetupToken($user);

    $stored = ClinicPasswordToken::where('user_id', $user->id)->sole();

    expect($stored->token_hash)->not->toBe($token)
        ->and($stored->token_hash)->toBe(hash('sha256', $token))
        ->and(strlen($token))->toBe(64);
});

it('rejects a setup link after it expires', function () {
    [$user] = ownerAwaitingPasswordSetup();
    $token = issueSetupToken($user);

    $this->travel(48)->hours();
    $this->travel(1)->minutes();

    $this->get(route('clinic.password.setup', ['token' => $token]))
        ->assertInertia(fn ($page) => $page->where('status', 'invalid'));

    $this->post(route('clinic.password.store', ['token' => $token]), [
        'password' => 'NewSecurePassword123!',
        'password_confirmation' => 'NewSecurePassword123!',
    ])->assertInertia(fn ($page) => $page->where('status', 'invalid'));

    $this->assertGuest();
    expect(Hash::check('old_password', $user->fresh()->password))->toBeTrue();
});

it('sets the password, signs the owner in and sends them to onboarding', function () {
    [$user] = ownerAwaitingPasswordSetup();
    $token = issueSetupToken($user);

    $this->post(route('clinic.password.store', ['token' => $token]), [
        'password' => 'NewSecurePassword123!',
        'password_confirmation' => 'NewSecurePassword123!',
    ])->assertRedirect(route('clinic.onboarding.index'));

    $this->assertAuthenticatedAs($user);
    expect(Hash::check('NewSecurePassword123!', $user->fresh()->password))->toBeTrue();
});

it('cannot be used a second time', function () {
    [$user] = ownerAwaitingPasswordSetup();
    $token = issueSetupToken($user);

    $this->post(route('clinic.password.store', ['token' => $token]), [
        'password' => 'FirstPassword123!',
        'password_confirmation' => 'FirstPassword123!',
    ]);
    auth()->logout();

    $this->get(route('clinic.password.setup', ['token' => $token]))
        ->assertInertia(fn ($page) => $page->where('status', 'invalid'));

    $this->post(route('clinic.password.store', ['token' => $token]), [
        'password' => 'AttackerPassword123!',
        'password_confirmation' => 'AttackerPassword123!',
    ])->assertInertia(fn ($page) => $page->where('status', 'invalid'));

    $this->assertGuest();
    expect(Hash::check('FirstPassword123!', $user->fresh()->password))->toBeTrue();
});

it('keeps the link usable when the password fails validation', function () {
    [$user] = ownerAwaitingPasswordSetup();
    $token = issueSetupToken($user);

    $this->post(route('clinic.password.store', ['token' => $token]), [
        'password' => 'short',
        'password_confirmation' => 'different',
    ])->assertSessionHasErrors('password');

    $this->assertGuest();
    $this->get(route('clinic.password.setup', ['token' => $token]))
        ->assertInertia(fn ($page) => $page->where('status', 'valid'));
});

it('retires an earlier setup link when a new one is issued', function () {
    $user = User::factory()->create();
    $firstToken = issueSetupToken($user);
    $secondToken = issueSetupToken($user);

    $this->get(route('clinic.password.setup', ['token' => $firstToken]))
        ->assertInertia(fn ($page) => $page->where('status', 'invalid'));
    $this->get(route('clinic.password.setup', ['token' => $secondToken]))
        ->assertInertia(fn ($page) => $page->where('status', 'valid'));
});

it('stops working if the account email changes after the link was sent', function () {
    $user = User::factory()->create(['email' => 'before@example.com']);
    $token = issueSetupToken($user);

    $user->update(['email' => 'after@example.com']);

    $this->get(route('clinic.password.setup', ['token' => $token]))
        ->assertInertia(fn ($page) => $page->where('status', 'invalid'));
});

it('emails a working one-time setup link', function () {
    [$user] = ownerAwaitingPasswordSetup();

    $mailable = new PasswordSetupMailable($user);

    expect($mailable->setupUrl)->not->toContain(sha1($user->email))
        ->not->toContain('/'.$user->id.'/');

    $this->get($mailable->setupUrl)
        ->assertInertia(fn ($page) => $page->where('status', 'valid')->where('email', $user->email));
});
