<?php

namespace App\Services\Dental;

use App\ClinicPermission;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\User;
use App\Services\ClinicContext;

/**
 * The front desk running a dental visit for a dentist who has no login: the
 * desk opens the visit, records the findings, the work done and the next
 * visits, and the visit is billed, all in that dentist's name, with the desk
 * member recorded as who did it. The mirror of `DeskPhysio`.
 *
 * Narrow on purpose: only dentistry, only a dentist without a login (one who
 * can sign in does their own work), only a desk member holding
 * `dental.desk.run` who works at that branch, and never another specialty's or
 * another clinic's visit. Clinical records that need the dentist's own
 * judgement (root canal, implant, braces, lab steps) stay the dentist's.
 */
class DeskDental
{
    public function __construct(protected ClinicContext $clinicContext) {}

    /** Whether this member may work for this practitioner at the desk. */
    public function mayRunFor(User $user, ?ClinicEmployee $practitioner): bool
    {
        // Read from the database, never from a partly loaded model: a missing `user_id` must not read as "no login".
        $practitioner = $practitioner ? ClinicEmployee::query()->withoutGlobalScopes()->find($practitioner->id) : null;
        if (! $practitioner || $practitioner->user_id !== null || ! $user->hasClinicPermission(ClinicPermission::DentalDeskRun)) {
            return false;
        }
        if ($this->clinicContext->hasClinic() && $practitioner->clinic_id !== $this->clinicContext->clinic()->id) {
            return false;
        }
        $profile = $practitioner->practitionerProfile()->withoutGlobalScopes()->first();

        return $profile !== null && in_array(DentalConsultation::SPECIALTY, $profile->specialtyKeys(), true);
    }

    /** Whether this member runs this consultation at the desk: a dental visit of a dentist without a login. */
    public function runs(User $user, ClinicalEncounter $encounter): bool
    {
        if ($encounter->specialty !== DentalConsultation::SPECIALTY || ($this->clinicContext->hasClinic() && $encounter->clinic_id !== $this->clinicContext->clinic()->id)) {
            return false;
        }
        $branch = $this->clinicContext->workingBranch();
        if ($branch && $branch->id !== $encounter->branch_id) {
            return false;
        }

        return $this->mayRunFor($user, ClinicEmployee::query()->withoutGlobalScopes()->find($encounter->doctor_id));
    }
}
