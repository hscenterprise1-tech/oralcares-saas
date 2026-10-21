<?php

namespace App\Services\Patient\Patient360;

use App\ClinicPermission;
use App\Models\Branch;
use App\Models\ClinicalEncounter;
use App\Models\Patient;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\Patient\PatientBranchAccess;
use App\Support\Specialty\SpecialtyRegistry;

/**
 * What one user may see and do in one patient's Patient 360.
 *
 * The page is clinic-wide (the patient belongs to the clinic), but records
 * that happened at a branch — consultations, orders, prescriptions, bills,
 * appointments — are shown only from branches the member may open. Sections
 * are offered from the same permissions the routes enforce; hiding a section
 * is presentation, every endpoint checks again.
 */
class Patient360Access
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected SpecialtyRegistry $specialties,
        protected PatientBranchAccess $branchAccess,
    ) {}

    public function canViewClinical(User $user): bool
    {
        return $user->hasClinicPermission(ClinicPermission::ClinicalView);
    }

    public function canViewBilling(User $user): bool
    {
        return $user->hasClinicPermission(ClinicPermission::BillingView);
    }

    public function canViewAppointments(User $user): bool
    {
        return $user->hasClinicPermission(ClinicPermission::AppointmentView);
    }

    /** Clinic owner or an administrator (settings), never front-line roles. */
    public function canViewAudit(User $user): bool
    {
        $membership = $this->clinicContext->membership();

        return ($membership?->is_primary_owner ?? false) || $user->hasClinicPermission(ClinicPermission::SettingsManage);
    }

    /** Follow-ups are managed by clinicians and by the front desk. */
    public function canManageFollowUps(User $user): bool
    {
        return $user->hasClinicPermission(ClinicPermission::ClinicalEdit) || $user->hasClinicPermission(ClinicPermission::AppointmentCreate);
    }

    /**
     * Branches whose records this member may see; null when every branch.
     *
     * @return list<int>|null
     */
    public function branchIds(): ?array
    {
        return $this->branchAccess->branchIds();
    }

    /**
     * Specialty workspaces this patient's clinic has (from its branches'
     * specialties), and only ever by extension, never by specialty name.
     *
     * @return array{dental: bool, physio: bool}
     */
    public function specialtyWorkspaces(Patient $patient): array
    {
        $extensions = Branch::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)
            ->when($this->branchIds() !== null, fn ($query) => $query->whereIn('id', $this->branchIds()))
            ->get()
            ->flatMap(fn (Branch $branch) => $this->specialties->extensionsFor($this->specialties->forBranch($branch)))
            ->unique()->values()->all();

        return [
            'dental' => in_array('odontogram', $extensions, true),
            'physio' => array_intersect(['physio_assessment', 'soap_notes', 'exercise_plan', 'session_packages'], $extensions) !== [],
        ];
    }

    /**
     * The consultation this user is writing for the patient right now, if
     * any (specialty charting writes go into it).
     */
    public function openConsultation(User $user, Patient $patient): ?ClinicalEncounter
    {
        $me = $this->clinicContext->currentEmployee();
        if (! $me) {
            return null;
        }

        $encounter = ClinicalEncounter::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->where('doctor_id', $me->id)->whereNull('finalized_at')->where('status', 'in_progress')
            ->latest('started_at')->first();

        return $encounter && $user->can('update', $encounter) ? $encounter : null;
    }
}
