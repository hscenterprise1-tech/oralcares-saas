<?php

namespace App\Services\Staff;

use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\ClinicStatus;
use App\EmploymentStatus;
use App\Models\ClinicEmployee;
use App\Models\ClinicEmployeeInvitation;
use App\Models\ClinicMembership;
use App\Models\User;
use App\Services\ClinicRoleAssignmentService;
use App\Services\Security\SecurityAudit;
use App\UserType;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Turns a staff invitation into clinic access for one login.
 *
 * One person is one `User`, whatever the number of clinics they work at: an
 * invitation either creates the login (new email) or adds a membership to the
 * existing one (known email, after its owner proves the account is theirs).
 * An existing login's password, name and type are never changed here.
 *
 * Everything about the new access comes from the invitation's own clinic:
 * the membership, the branch access (the staff record's branches in that
 * clinic) and the roles (clinic roles only, per branch).
 *
 * Invitation lookups use `withoutGlobalScopes()` on purpose: the invitee is
 * not signed into the clinic yet, so there is no tenant context. The secret
 * token is the capability, and every row touched is then checked against the
 * invitation's own clinic.
 */
class InvitationAcceptance
{
    public function __construct(
        private readonly ClinicRoleAssignmentService $roles,
        private readonly SecurityAudit $audit,
    ) {}

    /**
     * The pending invitation for this token, or null when it is unknown,
     * used, revoked, expired, or no longer points at a usable staff record
     * in an active clinic.
     */
    public function findPending(string $token): ?ClinicEmployeeInvitation
    {
        $invitation = ClinicEmployeeInvitation::withoutGlobalScopes()
            ->where('token', hash('sha256', $token))
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $invitation) {
            return null;
        }

        $clinic = $invitation->clinic()->withoutGlobalScopes()->first();
        $employee = $invitation->employee()->withoutGlobalScopes()->first();

        $usable = $clinic
            && $clinic->status === ClinicStatus::Active
            && $employee
            && $employee->clinic_id === $invitation->clinic_id
            && ! in_array($this->plain($employee->status), EmploymentStatus::accessEnding(), true);

        if (! $usable) {
            return null;
        }

        $invitation->setRelation('clinic', $clinic);
        $invitation->setRelation('employee', $employee);

        return $invitation;
    }

    public function existingAccount(ClinicEmployeeInvitation $invitation): ?User
    {
        return User::where('email', $invitation->email)->first();
    }

    public function isMember(ClinicEmployeeInvitation $invitation, User $user): bool
    {
        return ClinicMembership::withoutGlobalScopes()
            ->where('clinic_id', $invitation->clinic_id)
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * The invited email has no login yet: create it with the chosen password
     * and give it access to the invitation's clinic.
     */
    public function acceptAsNewUser(ClinicEmployeeInvitation $invitation, string $password): ClinicMembership
    {
        return DB::transaction(function () use ($invitation, $password) {
            $user = User::create([
                'name' => $invitation->employee->name,
                'email' => $invitation->email,
                'password' => Hash::make($password),
                'user_type' => UserType::ClinicUser,
                'organization_id' => $invitation->clinic->organization_id,
            ]);

            return $this->join($invitation, $user, existingAccount: false);
        });
    }

    /**
     * The invited email already has a login and its owner has proved it is
     * theirs: add this clinic to that login. Nothing about the login changes.
     */
    public function acceptAsExistingUser(ClinicEmployeeInvitation $invitation, User $user): ClinicMembership
    {
        if ($user->email !== $invitation->email || $user->user_type !== UserType::ClinicUser) {
            throw new DomainException('This invitation is for a different account.');
        }

        return DB::transaction(fn () => $this->join($invitation, $user, existingAccount: true));
    }

    private function join(ClinicEmployeeInvitation $invitation, User $user, bool $existingAccount): ClinicMembership
    {
        // Re-read under a lock: two tabs, or two people with the link, must
        // not both use one invitation.
        $locked = ClinicEmployeeInvitation::withoutGlobalScopes()->lockForUpdate()->find($invitation->id);

        if (! $locked || $locked->accepted_at || $locked->revoked_at || $locked->expires_at->isPast()) {
            throw new DomainException('This invitation has already been used or is no longer valid.');
        }

        if ($this->isMember($invitation, $user)) {
            throw new DomainException('This account is already a member of this clinic.');
        }

        $employee = ClinicEmployee::withoutGlobalScopes()->lockForUpdate()->find($invitation->clinic_employee_id);

        if (! $employee || $employee->clinic_id !== $invitation->clinic_id || ($employee->user_id !== null && $employee->user_id !== $user->id)) {
            throw new DomainException('This invitation no longer matches a staff record in the clinic.');
        }

        $membership = ClinicMembership::withoutGlobalScopes()->create([
            'clinic_id' => $invitation->clinic_id,
            'user_id' => $user->id,
            'status' => ClinicMembershipStatus::Active,
            'has_all_branches_access' => false,
        ]);

        // Branch access: the staff record's branches, which the database
        // only lets belong to this clinic.
        $branches = $employee->branches()->withoutGlobalScopes()->get();
        if ($branches->isNotEmpty()) {
            $membership->syncAssignedBranches($branches, $user->id);
        }

        // Roles: only clinic roles, per branch. Anything else held on the
        // staff record is ignored, never granted.
        $roleNames = $employee->roles()->pluck('name')->intersect(ClinicRole::values())->values()->all();
        if ($roleNames !== [] && $branches->isNotEmpty()) {
            $this->roles->syncRoles($user, $roleNames, $branches);
        }

        if ($employee->user_id === null) {
            $employee->update(['user_id' => $user->id]);
        }

        $locked->update(['accepted_at' => now()]);

        $this->audit->record('invitation.accepted', $locked, ['existing_account' => $existingAccount], [
            'actor_user_id' => $user->id,
            'clinic_id' => $invitation->clinic_id,
        ]);

        return $membership;
    }

    private function plain(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}
