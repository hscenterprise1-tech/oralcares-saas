<?php

namespace App\Services\Staff;

use App\Mail\StaffInvitationMail;
use App\Models\ClinicEmployee;
use App\Models\ClinicEmployeeInvitation;
use App\Models\User;
use App\Services\Security\SecurityAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Issues a staff invitation and emails the signed link.
 *
 * The invitation carries its context through the staff record it points
 * at: the clinic, branch placements, roles to grant, staff type, and who
 * invited them. Only the sha256 of the token is stored; a new invitation
 * revokes any earlier pending one for the same person.
 */
class StaffInvitationService
{
    public const VALID_DAYS = 3;

    public function __construct(protected SecurityAudit $audit) {}

    /**
     * @return array{invitation: ClinicEmployeeInvitation, url: string}
     */
    public function invite(ClinicEmployee $employee, string $email, User $actor): array
    {
        $token = Str::random(60);

        $invitation = DB::transaction(function () use ($employee, $email, $actor, $token) {
            ClinicEmployeeInvitation::withoutGlobalScopes()
                ->where('clinic_id', $employee->clinic_id)
                ->where('clinic_employee_id', $employee->id)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            return ClinicEmployeeInvitation::create([
                'clinic_id' => $employee->clinic_id,
                'clinic_employee_id' => $employee->id,
                'email' => $email,
                'token' => hash('sha256', $token),
                'expires_at' => now()->addDays(self::VALID_DAYS),
                'created_by' => $actor->id,
            ]);
        });

        $url = URL::temporarySignedRoute('clinic.invitation.accept', now()->addDays(self::VALID_DAYS), ['token' => $token]);

        Mail::to($email)->send(new StaffInvitationMail($employee, $url, $actor->name));

        $this->audit->record('invitation.created', $invitation, ['employee_id' => $employee->id], ['clinic_id' => $employee->clinic_id, 'actor_user_id' => $actor->id]);

        return ['invitation' => $invitation, 'url' => $url];
    }
}
