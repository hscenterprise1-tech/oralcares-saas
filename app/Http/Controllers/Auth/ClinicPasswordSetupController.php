<?php

namespace App\Http\Controllers\Auth;

use App\ClinicPasswordTokenPurpose;
use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\User;
use App\Services\Auth\ClinicPasswordTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The primary owner's first password, set from the one-time link emailed
 * when Company provisions their clinic (see `PasswordSetupMailable`).
 *
 * The link is `/clinic/password/setup/{token}`: a random token issued by
 * `ClinicPasswordTokenService`, single-use and expiring after 48 hours. It
 * replaced `/{id}/{sha1(email)}`, which anyone who knew a user's id and
 * email could build, and which never expired, because the signature the
 * email added to it was never checked.
 */
class ClinicPasswordSetupController extends Controller
{
    public function __construct(protected ClinicPasswordTokenService $tokens) {}

    /**
     * Display the password setup view. A dead link gets the page's own
     * "this link doesn't work" state, never an error page. The same state
     * appears whatever made the link unusable, so the page reveals nothing
     * about the account.
     */
    public function create(string $token): Response
    {
        $record = $this->tokens->find($token, ClinicPasswordTokenPurpose::Setup);

        if (! $record) {
            return Inertia::render('Clinic/Auth/SetupPassword', ['status' => 'invalid', 'mode' => 'setup']);
        }

        $clinic = $this->clinicForOwner($record->user);

        return Inertia::render('Clinic/Auth/SetupPassword', [
            'status' => 'valid',
            'mode' => 'setup',
            'token' => $token,
            'email' => $record->user->email,
            'clinicName' => $clinic?->branding_name,
            'clinicLogo' => $clinic?->logo_url,
        ]);
    }

    /**
     * Handle an incoming password setup request.
     */
    public function store(Request $request, string $token): RedirectResponse|Response
    {
        $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $this->tokens->redeem($token, ClinicPasswordTokenPurpose::Setup, $request->string('password')->toString());

        if (! $user) {
            return Inertia::render('Clinic/Auth/SetupPassword', ['status' => 'invalid', 'mode' => 'setup']);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('clinic.onboarding.index');
    }

    /**
     * This flow runs before the owner has any clinic membership, so the
     * clinic is found through the organization rather than `ClinicContext`,
     * which needs an active membership.
     */
    private function clinicForOwner(User $user): ?Clinic
    {
        return $user->organization_id
            ? Clinic::where('organization_id', $user->organization_id)->first()
            : null;
    }
}
