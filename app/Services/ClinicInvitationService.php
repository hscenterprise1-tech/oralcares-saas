<?php

namespace App\Services;

use App\ClinicMembershipStatus;
use App\EmploymentStatus;
use App\Models\ClinicEmployee;
use App\Models\ClinicEmployeeInvitation;
use App\Models\ClinicMembership;
use App\Models\User;
use App\UserType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ClinicInvitationService
{
    /**
     * Invite a clinic employee.
     */
    public function inviteEmployee(ClinicEmployee $employee, User $invitedBy): ClinicEmployeeInvitation
    {
        return DB::transaction(function () use ($employee, $invitedBy) {
            // Revoke any existing active invitations for this employee
            ClinicEmployeeInvitation::where('clinic_employee_id', $employee->id)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $invitation = ClinicEmployeeInvitation::create([
                'clinic_id' => $employee->clinic_id,
                'clinic_employee_id' => $employee->id,
                'email' => $employee->email,
                'token' => Str::random(40),
                'expires_at' => Carbon::now()->addDays(7),
                'created_by' => $invitedBy->id,
            ]);

            $employee->update(['status' => EmploymentStatus::Invited]);

            return $invitation;
        });
    }

    /**
     * Accept a clinic employee invitation, setting their password and activating their user.
     */
    public function acceptInvitation(string $token, string $password): ClinicEmployee
    {
        return DB::transaction(function () use ($token, $password) {
            $invitation = ClinicEmployeeInvitation::where('token', $token)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->first();

            if (! $invitation) {
                throw new InvalidArgumentException('Invalid, expired, or revoked invitation token.');
            }

            $employee = $invitation->employee;

            // 1. Create or retrieve User
            $user = User::where('email', $invitation->email)->first();

            if (! $user) {
                $user = User::create([
                    'name' => $employee->name,
                    'email' => $employee->email,
                    'password' => Hash::make($password),
                    'user_type' => UserType::ClinicUser,
                    'email_verified_at' => now(),
                ]);
            } else {
                $user->update([
                    'password' => Hash::make($password),
                    'email_verified_at' => now(),
                ]);
            }

            // 2. Mark Invitation as Accepted
            $invitation->update(['accepted_at' => now()]);

            // 3. Update Clinic Employee status
            $employee->update([
                'user_id' => $user->id,
                'status' => EmploymentStatus::Active,
            ]);

            // 4. Generate Platform-Level Clinic Membership for compatibility
            ClinicMembership::updateOrCreate(
                [
                    'clinic_id' => $employee->clinic_id,
                    'user_id' => $user->id,
                ],
                [
                    'is_primary_owner' => false,
                    'has_all_branches_access' => true,
                    'status' => ClinicMembershipStatus::Active,
                ]
            );

            return $employee;
        });
    }
}
