<?php

namespace App\Services\Patient;

use App\ClinicPermission;
use App\Models\ClinicalEncounter;
use App\Models\Patient;
use App\Models\PatientClinicalAccessAudit;
use App\Models\PatientVisit;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\Patient\Patient360\Patient360Sections;
use App\Support\ClinicClock;

/**
 * Another branch's consultations for a branch-limited member (D5, TR-14):
 * only with `clinical.cross_branch`, and only while the patient attends the
 * member's working branch today (a checked-in visit there). Without that the
 * notes stay blocked as before. Every opening of another branch's
 * consultation is written to `patient_clinical_access_audits`, which cannot
 * be changed or deleted.
 *
 * Members who see every branch (owner, all-branch access) are not affected:
 * nothing is widened for them and nothing is audited here.
 */
class CrossBranchClinicalAccess
{
    public const REASON = 'attending_today';

    /** @var array<string, ?PatientVisit> */
    private array $memo = [];

    public function __construct(
        protected ClinicContext $clinicContext,
        protected PatientBranchAccess $branchAccess,
    ) {}

    /**
     * Today's attended visit of the patient at the member's working branch,
     * when the member may read other branches' notes; null otherwise.
     */
    public function attendance(User $user, Patient $patient): ?PatientVisit
    {
        $branch = $this->clinicContext->workingBranch();
        if ($branch === null || $branch->clinic_id !== $patient->clinic_id || ! $user->hasClinicPermission(ClinicPermission::ClinicalCrossBranch)) {
            return null;
        }

        $key = spl_object_id(request()).":{$user->id}:{$patient->id}:{$branch->id}";
        if (! array_key_exists($key, $this->memo)) {
            // Only this request's answer is kept, so the memo cannot grow.
            $this->memo = [$key => PatientVisit::query()->withoutGlobalScopes()
                ->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)->where('branch_id', $branch->id)
                ->whereDate('visit_date', ClinicClock::now()->toDateString())
                ->whereIn('visit_status', Patient360Sections::ATTENDED_VISIT)
                ->latest('id')->first()];
        }

        return $this->memo[$key];
    }

    /**
     * The branches whose consultations this member may read for the patient:
     * their own, or all of them (null) while the patient attends here today.
     *
     * @return list<int>|null
     */
    public function encounterBranchIds(User $user, Patient $patient): ?array
    {
        $own = $this->branchAccess->branchIds();

        return $own === null || $this->attendance($user, $patient) !== null ? null : $own;
    }

    /** Whether this consultation, from a branch the member does not hold, may be opened now. */
    public function mayView(User $user, ClinicalEncounter $encounter): bool
    {
        $patient = Patient::query()->withoutGlobalScopes()->where('clinic_id', $encounter->clinic_id)->find($encounter->patient_id);

        return $patient !== null && $this->attendance($user, $patient) !== null;
    }

    /** Whether the consultation is from a branch outside the member's own. */
    public function isOtherBranch(ClinicalEncounter $encounter): bool
    {
        $own = $this->branchAccess->branchIds();

        return $own !== null && $encounter->branch_id !== null && ! in_array($encounter->branch_id, $own, true);
    }

    /** Record the opening of another branch's consultation. Does nothing for the member's own branches. */
    public function record(User $user, ClinicalEncounter $encounter, string $resourceType = 'encounter'): void
    {
        if (! $this->isOtherBranch($encounter)) {
            return;
        }
        $patient = Patient::query()->withoutGlobalScopes()->where('clinic_id', $encounter->clinic_id)->findOrFail($encounter->patient_id);
        $visit = $this->attendance($user, $patient);
        if ($visit === null) {
            return;
        }

        PatientClinicalAccessAudit::query()->withoutGlobalScopes()->create([
            'clinic_id' => $encounter->clinic_id,
            'actor_user_id' => $user->id,
            'patient_id' => $patient->id,
            'source_branch_id' => $encounter->branch_id,
            'accessing_branch_id' => $visit->branch_id,
            'resource_type' => $resourceType,
            'resource_id' => $encounter->id,
            'attendance_visit_id' => $visit->id,
            'access_reason' => self::REASON,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'accessed_at' => now(),
        ]);
    }
}
