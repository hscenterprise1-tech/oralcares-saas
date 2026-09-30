<?php

namespace App\Http\Controllers\Auth;

use App\ClinicMembershipStatus;
use App\ClinicPasswordTokenPurpose;
use App\ClinicStatus;
use App\Http\Controllers\Controller;
use App\Mail\ClinicPasswordResetMailable;
use App\Models\Clinic;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Services\Auth\ClinicPasswordTokenService;
use App\Services\ClinicContext;
use App\Services\Security\SecurityAudit;
use App\UserType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Forgot your password?" for clinic users.
 *
 * The reply to a reset request never depends on whether the email belongs
 * to anyone, so the form cannot be used to find out who has an account.
 * Only an active clinic user with an active membership in an active clinic
 * (in *this* clinic, when the page names one) is sent a link. The link is a
 * one-time `reset` token from `ClinicPasswordTokenService` that expires
 * after an hour.
 *
 * Throttling happens at two levels. The route caps each address
 * (`throttle:clinic-password`), and each email may be sent at most three
 * links in fifteen minutes, so the form cannot be used to flood someone's
 * inbox.
 */
class ClinicPasswordResetController extends Controller
{
    /** Reset emails one address may be sent in `EMAIL_DECAY_SECONDS`. */
    private const MAX_EMAILS = 3;

    private const EMAIL_DECAY_SECONDS = 15 * 60;

    public function __construct(
        protected ClinicPasswordTokenService $tokens,
        protected ClinicContext $clinicContext,
        protected SecurityAudit $audit,
    ) {}

    public function create(Request $request): Response
    {
        $clinic = $this->requestedClinic($request);

        return Inertia::render('Clinic/Auth/ForgotPassword', [
            'clinicName' => $clinic?->branding_name,
            'clinicLogo' => $clinic?->logo_url,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $email = Str::lower($validated['email']);
        $clinicSlug = $this->clinicContext->requestedClinicSlug($request);
        $limiterKey = 'clinic-password-reset|'.$email;

        $limited = RateLimiter::tooManyAttempts($limiterKey, self::MAX_EMAILS);
        $user = null;

        if (! $limited) {
            RateLimiter::hit($limiterKey, self::EMAIL_DECAY_SECONDS);

            $user = $this->eligibleUser($email, $clinicSlug);

            if ($user) {
                $token = $this->tokens->issue($user, ClinicPasswordTokenPurpose::Reset);
                $resetUrl = route('clinic.password.reset', array_filter(['token' => $token, 'clinic' => $clinicSlug]));

                Mail::to($user->email)->send(new ClinicPasswordResetMailable($user, $resetUrl));
            }
        }

        // Every request is recorded, whether or not a link went out. The
        // person on the page sees one reply either way; the trail says which.
        $this->audit->record('auth.password_reset_requested', $user, [
            'email' => $email,
            'requested_clinic' => $clinicSlug,
            'link_sent' => $user !== null,
            'rate_limited' => $limited,
        ], ['actor_user_id' => null]);

        return back()->with('success', 'If that email belongs to an account at this clinic, a link to reset the password is on its way. It works for one hour.');
    }

    public function edit(Request $request, string $token): Response
    {
        $record = $this->tokens->find($token, ClinicPasswordTokenPurpose::Reset);
        $clinic = $this->requestedClinic($request);

        if (! $record) {
            return Inertia::render('Clinic/Auth/SetupPassword', [
                'status' => 'invalid',
                'mode' => 'reset',
                'clinicName' => $clinic?->branding_name,
                'clinicLogo' => $clinic?->logo_url,
            ]);
        }

        return Inertia::render('Clinic/Auth/SetupPassword', [
            'status' => 'valid',
            'mode' => 'reset',
            'token' => $token,
            'email' => $record->user->email,
            'clinicName' => $clinic?->branding_name,
            'clinicLogo' => $clinic?->logo_url,
        ]);
    }

    /**
     * Set the new password, then send the person to sign in rather than
     * signing them in here, so the sign-in checks (the clinic's status and
     * the membership) still apply.
     */
    public function update(Request $request, string $token): RedirectResponse|Response
    {
        $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $this->tokens->redeem($token, ClinicPasswordTokenPurpose::Reset, $request->string('password')->toString());

        if (! $user) {
            $clinic = $this->requestedClinic($request);

            return Inertia::render('Clinic/Auth/SetupPassword', [
                'status' => 'invalid',
                'mode' => 'reset',
                'clinicName' => $clinic?->branding_name,
                'clinicLogo' => $clinic?->logo_url,
            ]);
        }

        return redirect()
            ->route('clinic.login', array_filter(['clinic' => $this->clinicContext->requestedClinicSlug($request)]))
            ->with('success', 'Your password has been reset. Sign in with your new password.');
    }

    /**
     * An active clinic user with an active membership in an active clinic,
     * scoped to the requested clinic when there is one.
     */
    private function eligibleUser(string $email, ?string $clinicSlug): ?User
    {
        return User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('user_type', UserType::ClinicUser)
            ->whereHas('clinicMemberships', function ($query) use ($clinicSlug): void {
                $query->withoutGlobalScope(TenantScope::class)
                    ->where('status', ClinicMembershipStatus::Active)
                    ->whereHas('clinic', function ($clinicQuery) use ($clinicSlug): void {
                        $clinicQuery->where('status', ClinicStatus::Active)
                            ->when($clinicSlug !== null, fn ($q) => $q->where('slug', $clinicSlug));
                    });
            })
            ->first();
    }

    private function requestedClinic(Request $request): ?Clinic
    {
        $slug = $this->clinicContext->requestedClinicSlug($request);

        return $slug ? Clinic::where('slug', $slug)->first() : null;
    }
}
