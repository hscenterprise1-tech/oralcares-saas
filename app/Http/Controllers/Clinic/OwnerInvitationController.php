<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\User;
use App\OwnerInvitationStatus;
use App\Services\ClinicContext;
use App\Services\Onboarding\OwnerInvitationService;
use DomainException;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The clinic owner accepts the invitation that provisioning sent.
 *
 * The link is signed and time-limited, and its token is matched by hash. A
 * new login sets its password; an existing login confirms its own password
 * and the password is not changed. The owner's membership was created by
 * provisioning, so acceptance never grants anything new: it only proves the
 * person holds the email and signs them in to that one clinic.
 *
 * Renders the approved invitation page unchanged.
 */
class OwnerInvitationController extends Controller
{
    public function __construct(protected OwnerInvitationService $invitations) {}

    public function accept(Request $request, string $token, ClinicContext $clinicContext): Response|RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            return Inertia::render('Clinic/Auth/AcceptInvitation', ['status' => 'invalid']);
        }

        $invitation = $this->invitations->findUsable($token);

        if (! $invitation) {
            return Inertia::render('Clinic/Auth/AcceptInvitation', ['status' => 'invalid']);
        }

        $clinic = Clinic::withoutGlobalScopes()->findOrFail($invitation->clinic_id);
        $owner = User::findOrFail($invitation->user_id);
        $page = [
            'employeeName' => $owner->name,
            'email' => $invitation->email,
            'roleName' => 'Clinic owner',
            'clinicName' => $clinic->branding_name,
            'clinicLogo' => $clinic->logo_url,
        ];

        if ($request->user() && $request->user()->id !== $owner->id) {
            return Inertia::render('Clinic/Auth/AcceptInvitation', ['status' => 'wrong_account'] + $page);
        }

        if ($invitation->status->isAccepted()) {
            return Inertia::render('Clinic/Auth/AcceptInvitation', ['status' => 'already_member'] + $page);
        }

        $existing = $this->invitations->ownerHasOwnPassword($invitation);

        if (! $request->isMethod('post')) {
            $this->invitations->advance($invitation, OwnerInvitationStatus::Opened);

            return Inertia::render('Clinic/Auth/AcceptInvitation', ['status' => $existing ? 'existing' : 'valid'] + $page);
        }

        $validated = $request->validate([
            'password' => $existing ? ['required', 'string'] : ['required', 'confirmed', Password::defaults()],
        ]);

        try {
            $membership = $this->invitations->accept($invitation, $validated['password']);
        } catch (DomainException $e) {
            if ($existing) {
                event(new Failed('web', $owner, ['email' => $owner->email]));
            }

            throw ValidationException::withMessages(['password' => $e->getMessage()]);
        }

        auth()->login($owner);
        $request->session()->regenerate();
        $clinicContext->activateMembership($membership);

        return redirect()->route('clinic.onboarding.account');
    }
}
