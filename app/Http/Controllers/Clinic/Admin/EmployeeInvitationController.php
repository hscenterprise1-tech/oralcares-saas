<?php

namespace App\Http\Controllers\Clinic\Admin;

use App\ClinicMembershipStatus;
use App\Exceptions\ResourceLimitExceededException;
use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicEmployeeInvitation;
use App\Models\ClinicMembership;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\ResourceLimitService;
use App\Services\Security\SecurityAudit;
use App\UserType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class EmployeeInvitationController extends Controller
{
    /**
     * Store a newly created invitation and send an email.
     */
    public function store(Request $request, ClinicContext $context, ResourceLimitService $limits): RedirectResponse
    {
        $validated = $request->validate([
            'clinic_employee_id' => ['required', 'exists:clinic_employees,id'],
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
     * Show the acceptance form (Password setup for invitee).
     */
    public function accept(Request $request, string $token, ClinicRoleAssignmentService $roleAssignments, ResourceLimitService $limits)
    {
        // A stale or tampered link is a normal thing for an invitee to hit
        // (they waited too long, or forwarded an old email) — it gets the
        // branded "this link doesn't work" state on this page, not a bare
        // Laravel error page.
        if (! $request->hasValidSignature()) {
            return Inertia::render('Clinic/Auth/AcceptInvitation', ['status' => 'invalid']);
        }

        $invitation = ClinicEmployeeInvitation::where('token', hash('sha256', $token))
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $invitation) {
            return Inertia::render('Clinic/Auth/AcceptInvitation', ['status' => 'invalid']);
        }

        // If it's a POST request, we are saving the password
        if ($request->isMethod('post')) {
            $validated = $request->validate([
                'password' => ['required', 'confirmed', Password::defaults()],
            ]);

            // The real gate: this is the moment a seat actually gets
            // consumed (ClinicMembership::create() below), not when the
            // invite was sent. The invitee can't add seats or call an
            // account manager, so this gets its own message — see
            // `checkSeatLimits()`.
            $error = $this->checkSeatLimits(
                $invitation->clinic,
                $invitation->employee->roles->contains('name', 'Doctor'),
                $limits,
                audience: 'invitee'
            );
            if ($error) {
                return back()->with('error', $error);
            }

            // Create User
            $user = User::create([
                'name' => $invitation->employee->name,
                'email' => $invitation->email,
                'password' => Hash::make($validated['password']),
                'user_type' => UserType::ClinicUser,
                'organization_id' => $invitation->clinic->organization_id,
            ]);

            // Create ClinicMembership
            $membership = ClinicMembership::create([
                'clinic_id' => $invitation->clinic_id,
                'user_id' => $user->id,
                'status' => ClinicMembershipStatus::Active,
                'has_all_branches_access' => false,
            ]);

            // Sync branches to membership from the employee profile
            $branches = $invitation->employee->branches;
            if ($branches->isNotEmpty()) {
                $membership->assignedBranches()->sync($branches->pluck('id'));

                app(SecurityAudit::class)->record('access.branches_changed', $membership, [
                    'member_user_id' => $user->id,
                    'granted' => $branches->pluck('id')->values()->all(),
                    'via' => 'invitation',
                ], ['actor_user_id' => $user->id, 'clinic_id' => $invitation->clinic_id]);
            }

            // Sync roles to User from Employee profile — scoped to each
            // branch, since the invitee has no active clinic context of
            // their own yet for a plain syncRoles() call to land under.
            $roleNames = $invitation->employee->roles->pluck('name')->all();
            if (! empty($roleNames) && $branches->isNotEmpty()) {
                $roleAssignments->syncRoles($user, $roleNames, $branches);
            }

            // Update Employee record
            $invitation->employee->update([
                'user_id' => $user->id,
                'email' => $user->email, // Ensure email matches
            ]);

            // Mark invitation accepted
            $invitation->update(['accepted_at' => now()]);

            app(SecurityAudit::class)->record('invitation.accepted', $invitation, [], [
                'actor_user_id' => $user->id,
                'clinic_id' => $invitation->clinic_id,
            ]);

            // Log them in, on a fresh session id, so a session id planted
            // before sign-in is not the one carried into the portal.
            auth()->login($user);
            $request->session()->regenerate();

            // Redirect to dashboard (they bypass onboarding since organization is already onboarded)
            return redirect()->route('dashboard');
        }

        // GET request - show the form
        return Inertia::render('Clinic/Auth/AcceptInvitation', [
            'status' => 'valid',
            'employeeName' => $invitation->employee->name,
            'email' => $invitation->email,
            'roleName' => $invitation->employee->roles->pluck('name')->implode(', ') ?: null,
            'clinicName' => $invitation->clinic->branding_name,
            'clinicLogo' => $invitation->clinic->logo_url,
        ]);
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
