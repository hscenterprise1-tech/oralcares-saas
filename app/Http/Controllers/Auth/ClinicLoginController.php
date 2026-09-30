<?php

namespace App\Http\Controllers\Auth;

use App\ClinicMembershipStatus;
use App\ClinicStatus;
use App\EmploymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ClinicLoginRequest;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Services\ClinicContext;
use App\Services\Security\SecurityAudit;
use App\UserType;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ClinicLoginController extends Controller
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected SecurityAudit $audit,
    ) {}

    public function create(Request $request): Response
    {
        $clinicSlug = $this->clinicContext->requestedClinicSlug($request);
        $clinicModel = $clinicSlug ? Clinic::where('slug', $clinicSlug)->first() : null;

        // No name is shown for a slug that matches no clinic. The old
        // `ucfirst($clinicSlug)` fallback put an invented name such as
        // "Demo-clinic" at the top of the page as if it were the tenant's own.
        return Inertia::render('Clinic/Auth/ClinicLogin', [
            'clinic' => $clinicSlug,
            'clinicName' => $clinicModel?->branding_name,
            'clinicLogo' => $clinicModel?->logo_url,
        ]);
    }

    /**
     * Sign a clinic user in, to the clinic this page belongs to.
     *
     * The clinic comes from the same place every later request reads it
     * (`ClinicContext::requestedClinicSlug` — the subdomain, or the `clinic`
     * parameter in path mode). Correct credentials are not enough: the
     * account must also hold an active membership in that clinic, and the
     * clinic itself must be active. With no clinic named (path mode only),
     * any active membership in an active clinic will do, and the session is
     * bound to it.
     *
     * The credentials are checked without logging in (`Auth::validate`), so
     * a refused account never gets a session that then has to be torn down.
     * Only wrong passwords count toward the lockout. A refusal after a
     * correct password proves the attacker already has the password, so
     * counting it would only lock out the real user.
     */
    public function store(ClinicLoginRequest $request): RedirectResponse
    {
        $request->ensureIsNotRateLimited();

        $credentials = $request->only('email', 'password');

        if (! Auth::validate($credentials)) {
            $request->recordFailedAttempt();

            // `Auth::validate` does not fire `Failed` the way `Auth::attempt`
            // does, so fire it here. That puts clinic failures in the security
            // trail through the same listener as every other portal.
            event(new Failed('web', Auth::getLastAttempted(), ['email' => $credentials['email']]));

            throw ValidationException::withMessages([
                'email' => 'The provided credentials do not match our records.',
            ]);
        }

        /** @var User $user */
        $user = Auth::getLastAttempted();

        if ($user->user_type !== UserType::ClinicUser) {
            $this->audit->record('auth.refused', $user, ['reason' => 'not_a_clinic_user'], ['actor_user_id' => $user->id]);

            throw ValidationException::withMessages([
                'email' => 'Unauthorized action. Clinic portal access required.',
            ]);
        }

        $clinicSlug = $this->clinicContext->requestedClinicSlug($request);
        $membership = $this->membershipToSignInto($user, $clinicSlug);

        if (! $membership) {
            $this->audit->record('auth.refused', $user, [
                'reason' => $clinicSlug === null ? 'no_active_membership' : 'no_access_to_requested_clinic',
                'requested_clinic' => $clinicSlug,
            ], ['actor_user_id' => $user->id]);

            throw ValidationException::withMessages([
                'email' => $clinicSlug === null
                    ? 'Your clinic membership is inactive or non-existent.'
                    : 'Your account does not have access to this clinic.',
            ]);
        }

        $request->clearFailedAttempts();

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        // Bind the session to the clinic that was signed into, so a user with
        // memberships in several clinics lands in this one and not whichever
        // the session happened to hold before.
        $this->clinicContext->activateMembership($membership);

        if (config('app.routing_mode') === 'domain') {
            return redirect()->intended(route('clinic.dashboard', array_filter(['clinic' => $request->route('clinic')])));
        }

        return redirect()->intended(route('clinic.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if (config('app.routing_mode') === 'domain') {
            $clinicParam = $request->route('clinic');

            return redirect()->route('clinic.login', array_filter(['clinic' => $clinicParam]));
        }

        return redirect()->route('clinic.login');
    }

    /**
     * The active membership, in an active clinic, that this sign-in should
     * open. Scoped to `$clinicSlug` when one was requested. Domain mode
     * always names a clinic, so a request with no slug there fails closed.
     */
    private function membershipToSignInto(User $user, ?string $clinicSlug): ?ClinicMembership
    {
        if ($clinicSlug === null && config('app.routing_mode') === 'domain') {
            return null;
        }

        return $user->clinicMemberships()
            ->withoutGlobalScope(TenantScope::class)
            ->where('status', ClinicMembershipStatus::Active)
            ->whereHas('clinic', function ($query) use ($clinicSlug): void {
                $query->where('status', ClinicStatus::Active)
                    ->when($clinicSlug !== null, fn ($q) => $q->where('slug', $clinicSlug));
            })
            // The rule `ResolveClinicContext` applies to every later request:
            // an inactive, suspended or resigned staff record ends that
            // login's access. Checking it here refuses the sign-in with a
            // reason instead of opening a session that then meets a bare 403.
            ->whereNotExists(fn ($query) => $query->from('clinic_employees')
                ->whereColumn('clinic_employees.clinic_id', 'clinic_memberships.clinic_id')
                ->whereColumn('clinic_employees.user_id', 'clinic_memberships.user_id')
                ->whereIn('clinic_employees.status', EmploymentStatus::accessEnding()))
            ->with('clinic')
            ->orderBy('id')
            ->first();
    }
}
