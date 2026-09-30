<?php

use App\ClinicMembershipStatus;
use App\ClinicPasswordTokenPurpose;
use App\ClinicStatus;
use App\Mail\ClinicPasswordResetMailable;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\User;
use App\Services\Auth\ClinicPasswordTokenService;
use App\UserType;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

const RESET_CONFIRMATION = 'If that email belongs to an account at this clinic, a link to reset the password is on its way. It works for one hour.';

/**
 * @return array{0: User, 1: Clinic}
 */
function resettableClinicUser(array $clinicAttributes = []): array
{
    $user = User::factory()->create([
        'user_type' => UserType::ClinicUser,
        'email' => 'staff@clinic.test',
        'password' => 'old-password',
    ]);
    $clinic = Clinic::factory()->create($clinicAttributes);
    ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'status' => ClinicMembershipStatus::Active,
    ]);

    return [$user, $clinic];
}

function issueResetToken(User $user): string
{
    return app(ClinicPasswordTokenService::class)->issue($user, ClinicPasswordTokenPurpose::Reset);
}

it('shows the forgot-password page with the clinic branding', function () {
    Clinic::factory()->create(['slug' => 'reset-dental', 'display_name' => 'Reset Dental']);

    $this->get('/clinic/password/forgot?clinic=reset-dental')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Clinic/Auth/ForgotPassword')
            ->where('clinicName', 'Reset Dental')
        );
});

it('emails a reset link to an active clinic user', function () {
    Mail::fake();
    [$user] = resettableClinicUser(['slug' => 'mail-dental']);

    $this->post('/clinic/password/forgot?clinic=mail-dental', ['email' => $user->email])
        ->assertSessionHas('success', RESET_CONFIRMATION);

    Mail::assertQueued(ClinicPasswordResetMailable::class, fn ($mail) => $mail->hasTo($user->email)
        && str_contains($mail->resetUrl, '/clinic/password/reset/')
        && str_contains($mail->resetUrl, 'clinic=mail-dental'));
});

it('answers the same way for an email that belongs to no one', function () {
    Mail::fake();

    $this->post('/clinic/password/forgot', ['email' => 'nobody@clinic.test'])
        ->assertSessionHas('success', RESET_CONFIRMATION);

    Mail::assertNothingQueued();
});

it('sends nothing to accounts that cannot use the clinic portal', function (Closure $makeIneligible) {
    Mail::fake();
    [$user, $clinic] = resettableClinicUser();
    $makeIneligible($user, $clinic);

    $this->post('/clinic/password/forgot', ['email' => $user->email])
        ->assertSessionHas('success', RESET_CONFIRMATION);

    Mail::assertNothingQueued();
})->with([
    'company user' => [fn (User $user) => $user->update(['user_type' => UserType::CompanyUser])],
    'inactive membership' => [fn (User $user) => ClinicMembership::where('user_id', $user->id)->update(['status' => ClinicMembershipStatus::Inactive])],
    'suspended clinic' => [fn (User $user, Clinic $clinic) => $clinic->update(['status' => ClinicStatus::Suspended])],
]);

it('sends nothing when the email belongs to a different clinic than the page', function () {
    Mail::fake();
    [$user] = resettableClinicUser(['slug' => 'own-dental']);
    Clinic::factory()->create(['slug' => 'other-dental']);

    $this->post('/clinic/password/forgot?clinic=other-dental', ['email' => $user->email])
        ->assertSessionHas('success', RESET_CONFIRMATION);

    Mail::assertNothingQueued();
});

it('sends at most three reset emails to one address in fifteen minutes', function () {
    Mail::fake();
    [$user] = resettableClinicUser();

    foreach (range(1, 4) as $request) {
        $this->post('/clinic/password/forgot', ['email' => $user->email])
            ->assertSessionHas('success', RESET_CONFIRMATION);
    }

    Mail::assertQueuedCount(3);
});

it('resets the password from a valid link and sends the user to sign in', function () {
    [$user] = resettableClinicUser(['slug' => 'done-dental']);
    $token = issueResetToken($user);

    $this->get("/clinic/password/reset/{$token}?clinic=done-dental")
        ->assertInertia(fn ($page) => $page
            ->component('Clinic/Auth/SetupPassword')
            ->where('status', 'valid')
            ->where('mode', 'reset')
            ->where('email', $user->email)
        );

    $this->post("/clinic/password/reset/{$token}?clinic=done-dental", [
        'password' => 'BrandNewPassword123!',
        'password_confirmation' => 'BrandNewPassword123!',
    ])->assertRedirect('/clinic/login?clinic=done-dental');

    $this->assertGuest();
    expect(Hash::check('BrandNewPassword123!', $user->fresh()->password))->toBeTrue();
});

it('does not accept a reset link twice', function () {
    [$user] = resettableClinicUser();
    $token = issueResetToken($user);
    $payload = ['password' => 'BrandNewPassword123!', 'password_confirmation' => 'BrandNewPassword123!'];

    $this->post("/clinic/password/reset/{$token}", $payload);

    $this->post("/clinic/password/reset/{$token}", ['password' => 'Another123456!', 'password_confirmation' => 'Another123456!'])
        ->assertInertia(fn ($page) => $page->where('status', 'invalid'));

    expect(Hash::check('BrandNewPassword123!', $user->fresh()->password))->toBeTrue();
});

it('does not accept a reset link after an hour', function () {
    [$user] = resettableClinicUser();
    $token = issueResetToken($user);

    $this->travel(61)->minutes();

    $this->get("/clinic/password/reset/{$token}")
        ->assertInertia(fn ($page) => $page->where('status', 'invalid')->missing('email'));
});

it('does not accept a setup link on the reset page', function () {
    [$user] = resettableClinicUser();
    $setupToken = app(ClinicPasswordTokenService::class)->issue($user, ClinicPasswordTokenPurpose::Setup);

    $this->get("/clinic/password/reset/{$setupToken}")
        ->assertInertia(fn ($page) => $page->where('status', 'invalid'));
});

it('signs out remembered sessions when the password is reset', function () {
    [$user] = resettableClinicUser();
    $user->forceFill(['remember_token' => 'old-remember-token'])->save();

    $this->post('/clinic/password/reset/'.issueResetToken($user), [
        'password' => 'BrandNewPassword123!',
        'password_confirmation' => 'BrandNewPassword123!',
    ]);

    expect($user->fresh()->remember_token)->not->toBe('old-remember-token');
});

it('escapes the user name in the reset email', function () {
    $user = User::factory()->make(['name' => "O'Reilly <script>alert('xss')</script>"]);

    $content = (new ClinicPasswordResetMailable($user, 'https://example.test/reset'))->render();

    expect($content)
        ->toContain('&lt;script&gt;')
        ->not->toContain("<script>alert('xss')</script>");
});
