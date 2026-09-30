<?php

namespace App\Http\Controllers\Clinic\Admin;

use App\ClinicRole;
use App\Exceptions\ResourceLimitExceededException;
use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicEmployeeInvitation;
use App\Models\ClinicMembership;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\ResourceLimitService;
use App\Services\Security\SecurityAudit;
use App\Services\Staff\InvitationAcceptance;
use App\UserType;
use DomainException;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class EmployeeInvitationController extends Controller
{
    /**
     * Store a newly created invitation and send an email.
     */
    public function store(Request $request, ClinicContext $context, ResourceLimitService $limits): RedirectResponse
    {
        $validated = $request->validate([
            'clinic_employee_id' => ['required', Rule::exists('clinic_employees', 'id')->where('clinic_id', $context->clinic()->id)],
            'email' => ['required', 'email'],
        ]);

        $employee = ClinicEmployee::findOrFail($validated['clinic_employee_id']);

        if ($employee->clinic_id !== $context->clinic()->id) {
            abort(403);
        }

        if ($employee->user_id) {
            return back()->with('error', 'Employee already has an associated user account.');
        }

        // Early feedback for the admin sending the invite. The real gate is
        // in accept() — this employee has no ClinicMembership yet, so it
        // consumes no seat until then, and another invite accepted in the
        // meantime could still fill the last one.
        $error = $this->checkSeatLimits($context->clinic(), $employee->roles->contains('name', 'Doctor'), $limits);
        if ($error) {
            return back()->with('error', $error);
        }

        // Revoke any existing pending invitations for this employee
        ClinicEmployeeInvitation::where('clinic_employee_id', $employee->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        // Create new invitation
        $token = Str::random(60);
        $invitation = ClinicEmployeeInvitation::create([
            'clinic_id' => $context->clinic()->id,
            'clinic_employee_id' => $employee->id,
            'email' => $validated['email'],
            'token' => hash('sha256', $token),
            'expires_at' => now()->addDays(3),
            'created_by' => auth()->id(),
        ]);

        // Generate Signed URL for acceptance
        $acceptUrl = URL::temporarySignedRoute(
            'clinic.invitation.accept',
            now()->addDays(3),
            ['token' => $token]
        );

        // TODO: Fire event / Send Mailable with $acceptUrl
        // For now we just log it or rely on the user manual flow for tests
        // e.g. Mail::to($invitation->email)->send(new EmployeeInvitationMailable($acceptUrl, $employee));

        app(SecurityAudit::class)->record('invitation.created', $invitation, ['employee_id' => $invitation->clinic_employee_id]);

        return back()->with('success', 'Invitation sent successfully.');
    }

    /**
     * Revoke an active invitation.
     */
    public function revoke(ClinicEmployeeInvitation $invitation, ClinicContext $context): RedirectResponse
    {
        if ($invitation->clinic_id !== $context->clinic()->id) {
            abort(403);
        }

        $invitation->update(['revoked_at' => now()]);

        app(SecurityAudit::class)->record('invitation.revoked', $invitation);

        return back()->with('success', 'Invitation revoked successfully.');
    }

    /**
     * Accept a staff invitation.
     *
     * One person is one login. When the invited email has no login yet, the
     * invitee sets a password and the login is created. When it already has
     * one (they work at another clinic), they confirm with that login's own
     * password and this clinic is added to it; the password is only checked,
     * never changed.
     */
    public function accept(Request $request, string $token, InvitationAcceptance $acceptance, ResourceLimitService $limits, ClinicContext $clinicContext)
    {
        // A stale or tampered link is a normal thing for an invitee to hit
        // (they waited too long, or forwarded an old email) — it gets the
        // branded "this link doesn't work" state on this page, not a bare
        // Laravel error page.
        if (! $request->hasValidSignature()) {
            return Inertia::render('Clinic/Auth/AcceptInvitation', ['status' => 'invalid']);
        }

        $invitation = $acceptance->findPending($token);

        if (! $invitation) {
            return Inertia::render('Clinic/Auth/AcceptInvitation', ['status' => 'invalid']);
        }

        $existing = $acceptance->existingAccount($invitation);
        $page = [
            'employeeName' => $invitation->employee->name,
            'email' => $invitation->email,
            'roleName' => $invitation->employee->roles->pluck('name')->intersect(ClinicRole::values())->implode(', ') ?: null,
            'clinicName' => $invitation->clinic->branding_name,
            'clinicLogo' => $invitation->clinic->logo_url,
        ];

        if ($existing) {
            if ($existing->user_type !== UserType::ClinicUser) {
                return Inertia::render('Clinic/Auth/AcceptInvitation', ['status' => 'account_conflict'] + $page);
            }

            if ($acceptance->isMember($invitation, $existing)) {
                return Inertia::render('Clinic/Auth/AcceptInvitation', ['status' => 'already_member'] + $page);
            }

            // Signed in as someone else: never switch accounts under them.
            if ($request->user() && $request->user()->id !== $existing->id) {
                return Inertia::render('Clinic/Auth/AcceptInvitation', ['status' => 'wrong_account'] + $page);
            }

            if (! $request->isMethod('post')) {
                return Inertia::render('Clinic/Auth/AcceptInvitation', ['status' => 'existing'] + $page);
            }

            $request->validate(['password' => ['required', 'string']]);

            if (! Hash::check($request->string('password')->toString(), $existing->password)) {
                event(new Failed('web', $existing, ['email' => $existing->email]));

                throw ValidationException::withMessages(['password' => 'That password does not match this account.']);
            }

            if ($error = $this->seatLimitError($invitation, $limits)) {
                return back()->with('error', $error);
            }

            try {
                $membership = $acceptance->acceptAsExistingUser($invitation, $existing);
            } catch (DomainException $e) {
                return back()->with('error', $e->getMessage());
            }

            return $this->signInto($request, $existing, $membership, $clinicContext);
        }

        if (! $request->isMethod('post')) {
            return Inertia::render('Clinic/Auth/AcceptInvitation', ['status' => 'valid'] + $page);
        }

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if ($error = $this->seatLimitError($invitation, $limits)) {
            return back()->with('error', $error);
        }

        try {
            $membership = $acceptance->acceptAsNewUser($invitation, $validated['password']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $this->signInto($request, $membership->user, $membership, $clinicContext);
    }

    /**
     * The real seat gate: a seat is consumed when the membership is created,
     * not when the invite was sent. The invitee can't add seats or call an
     * account manager, so this gets its own message — see `checkSeatLimits()`.
     */
    private function seatLimitError(ClinicEmployeeInvitation $invitation, ResourceLimitService $limits): ?string
    {
        return $this->checkSeatLimits(
            $invitation->clinic,
            $invitation->employee->roles->contains('name', ClinicRole::Doctor->value),
            $limits,
            audience: 'invitee'
        );
    }

    /**
     * Sign the person in (on a fresh session id, so a session id planted
     * before sign-in is not the one carried into the portal) and open the
     * clinic they just joined.
     */
    private function signInto(Request $request, User $user, ClinicMembership $membership, ClinicContext $clinicContext): RedirectResponse
    {
        auth()->login($user);
        $request->session()->regenerate();
        $clinicContext->activateMembership($membership);

        return redirect()->route('dashboard');
    }

    /**
     * Checks the role-specific sub-cap first (doctor or staff, whichever
     * applies), then the overall seat ceiling — a sub-cap can block before
     * the ceiling is ever reached, but the ceiling always blocks once it is,
     * regardless of which sub-cap has room.
     *
     * `$audience` picks the message: an admin sending an invite can add
     * seats or call their account manager, so `store()` passes through
     * `ResourceLimitService`'s own message. The invitee mid-signup can do
     * neither — they get a message pointing back at whoever invited them,
     * built here rather than reused from the exception, which is why this
     * doesn't just return `$e->getMessage()` for that audience.
     *
     * @return string|null An error message if a limit is reached, else null.
     */
    private function checkSeatLimits(Clinic $clinic, bool $isDoctor, ResourceLimitService $limits, string $audience = 'admin'): ?string
    {
        $limitType = $isDoctor ? 'doctor' : 'staff';

        try {
            if ($isDoctor) {
                $limits->enforceDoctorLimit($clinic);
            } else {
                $limits->enforceStaffLimit($clinic);
            }

            $limitType = 'seat';
            $limits->enforceUserLimit($clinic);
        } catch (ResourceLimitExceededException $e) {
            if ($audience === 'invitee') {
                return "This clinic has reached its plan's {$limitType} limit. You haven't been added yet — contact the clinic admin who invited you.";
            }

            return $e->getMessage();
        }

        return null;
    }
}
