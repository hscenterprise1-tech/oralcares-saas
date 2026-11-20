<?php

namespace App\Services\Physio;

use App\ClinicPermission;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\User;
use App\Services\ClinicContext;

/**
 * The front desk running a physiotherapy visit for a therapist who has no
 * login (owner's decision, 2026-11-05): the desk opens the visit, records the
 * problem, plan and sessions, sells the package and takes the payment, all
 * in that therapist's name, with the desk member recorded as who did it.
 *
 * Narrow on purpose: only physiotherapy, only a therapist without a login
 * (one who can sign in does their own work), only a desk member holding the
 * session permission (`physio.session.confirm`) who works at that branch, and
 * never another specialty's or another clinic's visit.
 */
class DeskPhysio
{
    public function __construct(protected ClinicContext $clinicContext) {}

    /** Whether this member may work for this practitioner at the desk. */
    public function mayRunFor(User $user, ?ClinicEmployee $practitioner): bool
    {
        // Read from the database, never from a partly loaded model: a missing `user_id` must not read as "no login".
        $practitioner = $practitioner ? ClinicEmployee::query()->withoutGlobalScopes()->find($practitioner->id) : null;
        if (! $practitioner || $practitioner->user_id !== null || ! $user->hasClinicPermission(ClinicPermission::PhysioSessionConfirm)) {
            return false;
        }
        if ($this->clinicContext->hasClinic() && $practitioner->clinic_id !== $this->clinicContext->clinic()->id) {
            return false;
        }
        $profile = $practitioner->practitionerProfile()->withoutGlobalScopes()->first();

        return $profile !== null && in_array(PhysioCareSummary::SPECIALTY, $profile->specialtyKeys(), true);
    }

    /** Whether this member runs this consultation at the desk: a physiotherapy visit of a therapist without a login. */
    public function runs(User $user, ClinicalEncounter $encounter): bool
    {
        if ($encounter->specialty !== PhysioCareSummary::SPECIALTY || ($this->clinicContext->hasClinic() && $encounter->clinic_id !== $this->clinicContext->clinic()->id)) {
            return false;
        }
        $branch = $this->clinicContext->workingBranch();
        if ($branch && $branch->id !== $encounter->branch_id) {
            return false;
        }

        return $this->mayRunFor($user, ClinicEmployee::query()->withoutGlobalScopes()->find($encounter->doctor_id));
    }
}
