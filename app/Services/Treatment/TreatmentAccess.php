<?php

namespace App\Services\Treatment;

use App\ClinicPermission;
use App\EmploymentStatus;
use App\Models\Branch;
use App\Models\CatalogTreatment;
use App\Models\CatalogTreatmentVersion;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientTreatment;
use App\Models\User;
use App\PatientStatus;
use App\Services\ClinicContext;
use App\Services\Patient\ClinicSpecialties;
use App\Services\Patient\PatientBranchAccess;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Server-side authorization for the treatment engine. Every writer calls it;
 * nothing relies on a screen hiding a button.
 *
 * Starting or doing treatment needs: the permission, the treatment's
 * specialty active in the clinic, a practitioner who practises it, a branch
 * the user may work at, a patient the user may open — all in one clinic.
 */
class TreatmentAccess
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected ClinicSpecialties $clinicSpecialties,
        protected PatientBranchAccess $branchAccess,
    ) {}

    public function assertCanManageCatalog(User $user, Clinic $clinic): void
    {
        $this->assertInClinic($clinic->id);
        $this->require($user->hasClinicPermission(ClinicPermission::CatalogManage), 'You cannot change the treatment catalog.');
    }

    public function assertCanConfigureSpecialties(User $user, Clinic $clinic): void
    {
        $this->assertInClinic($clinic->id);
        $this->require($user->hasClinicPermission(ClinicPermission::SpecialtyConfigure), 'Only the clinic owner decides which specialties the clinic practises.');
    }

    public function assertCanStart(User $user, Patient $patient, CatalogTreatmentVersion $version, ClinicEmployee $practitioner, Branch $branch): void
    {
        $this->require($user->hasClinicPermission(ClinicPermission::TreatmentStart), 'You cannot start treatment.');
        $this->assertCommon($user, $patient, $branch);
        $this->assertWorkingRecord($patient);
        $this->assertSameClinic($patient->clinic_id, $version->clinic_id, $practitioner->clinic_id);

        $treatment = $version->treatment()->withoutGlobalScopes()->first(['id', 'specialty_key', 'required_capability']);
        $this->assertSpecialty($patient->clinic_id, $treatment?->specialty_key, $practitioner);
        $this->assertCapability($treatment?->required_capability, $practitioner);
    }

    /** Starting a treatment without a catalogue entry: the same rules, against the specialty named. */
    public function assertCanStartAdHoc(User $user, Patient $patient, string $specialtyKey, ClinicEmployee $practitioner, Branch $branch): void
    {
        $this->require($user->hasClinicPermission(ClinicPermission::TreatmentStart), 'You cannot start treatment.');
        $this->assertCommon($user, $patient, $branch);
        $this->assertWorkingRecord($patient);
        $this->assertSameClinic($patient->clinic_id, $practitioner->clinic_id);
        $this->assertSpecialty($patient->clinic_id, $specialtyKey, $practitioner);
    }

    /** Treatment work happens on a working record only: a merged patient is read-only. */
    private function assertWorkingRecord(Patient $patient): void
    {
        $this->require(in_array($patient->status?->value ?? PatientStatus::Active->value, PatientStatus::working(), true), 'This patient record was merged into another; it is read-only.');
    }

    public function assertCanExecute(User $user, PatientTreatment $treatment, ClinicEmployee $performer): void
    {
        $this->require($user->hasClinicPermission(ClinicPermission::TreatmentExecute), 'You cannot record treatment.');
        $patient = Patient::query()->withoutGlobalScopes()->findOrFail($treatment->patient_id);
        $branch = Branch::query()->withoutGlobalScopes()->find($treatment->branch_id);
        $this->assertCommon($user, $patient, $branch);
        $this->assertWorkingRecord($patient);
        $this->assertSameClinic($treatment->clinic_id, $performer->clinic_id);

        if ($treatment->specialty_key !== null) {
            $this->assertSpecialty($treatment->clinic_id, $treatment->specialty_key, $performer);
        }
        if ($treatment->catalog_treatment_id) {
            $this->assertCapability(CatalogTreatment::query()->withoutGlobalScopes()->whereKey($treatment->catalog_treatment_id)->value('required_capability'), $performer);
        }
    }

    /**
     * Confirming at the desk that a session of this treatment took place,
     * with the practitioner who gave it. Its own permission, never
     * `treatment.execute`: it grants no notes, diagnosis, sign-off or other
     * treatment work. The patient, branch, record and practitioner checks are
     * the same as for recording the treatment.
     */
    public function assertCanConfirmSession(User $user, PatientTreatment $treatment, ClinicEmployee $practitioner): void
    {
        $this->require($user->hasClinicPermission(ClinicPermission::PhysioSessionConfirm), 'You cannot confirm treatment sessions.');
        $patient = Patient::query()->withoutGlobalScopes()->findOrFail($treatment->patient_id);
        $branch = Branch::query()->withoutGlobalScopes()->find($treatment->branch_id);
        $this->assertCommon($user, $patient, $branch);
        $this->assertWorkingRecord($patient);
        $this->assertSameClinic($treatment->clinic_id, $practitioner->clinic_id);
        if ($treatment->specialty_key !== null) {
            $this->assertSpecialty($treatment->clinic_id, $treatment->specialty_key, $practitioner);
        }
        if ($treatment->catalog_treatment_id) {
            $this->assertCapability(CatalogTreatment::query()->withoutGlobalScopes()->whereKey($treatment->catalog_treatment_id)->value('required_capability'), $practitioner);
        }
    }

    /**
     * A treatment limited to a practice-area capability (braces need an
     * Orthodontics capability) is started and recorded only by a
     * practitioner whose practice areas carry it. Being in the same module
     * (Dental) is not enough, and a qualification grants nothing by itself.
     */
    private function assertCapability(?string $capability, ClinicEmployee $practitioner): void
    {
        if ($capability === null) {
            return;
        }
        $profile = $practitioner->practitionerProfile()->withoutGlobalScopes()->first();
        $this->require($profile !== null && in_array($capability, $profile->capabilityKeys(), true),
            "{$practitioner->name} does not practise the area this treatment needs.");
    }

    /** Treatment work filed under a consultation: only one held at a branch the user works at. */
    public function assertCanUseConsultation(?ClinicalEncounter $encounter): void
    {
        if ($encounter === null) {
            return;
        }
        $allowed = $this->branchAccess->branchIds();
        $this->require($allowed === null || in_array((int) $encounter->branch_id, $allowed, true), 'You do not work at the branch of this consultation.');
    }

    /** Reading a treatment: the patient may be opened and the treatment's branch is one the user works at. */
    public function assertCanView(User $user, PatientTreatment $treatment): void
    {
        $patient = Patient::query()->withoutGlobalScopes()->findOrFail($treatment->patient_id);
        $this->assertCommon($user, $patient, $treatment->branch_id ? Branch::query()->withoutGlobalScopes()->find($treatment->branch_id) : null);
    }

    /**
     * The specialties a user's treatment board is limited to: a clinician
     * sees the specialties they practise; anyone who runs the catalog (owner,
     * admin) sees every specialty (null).
     *
     * @return list<string>|null
     */
    public function boardSpecialties(User $user): ?array
    {
        if ($user->hasClinicPermission(ClinicPermission::CatalogManage)) {
            return null;
        }
        $profile = $this->clinicContext->currentEmployee()?->practitionerProfile;

        return $profile ? $profile->specialtyKeys() : null;
    }

    private function assertCommon(User $user, Patient $patient, ?Branch $branch): void
    {
        $this->assertInClinic($patient->clinic_id);
        $this->require($user->can('view', $patient), 'You cannot open this patient.');

        if ($branch) {
            $this->assertSameClinic($patient->clinic_id, $branch->clinic_id);
            $allowed = $this->branchAccess->branchIds();
            $this->require($allowed === null || in_array($branch->id, $allowed, true), 'You do not work at this branch.');
        }
    }

    private function assertSpecialty(int $clinicId, ?string $key, ClinicEmployee $practitioner): void
    {
        $clinic = Clinic::query()->findOrFail($clinicId);
        $this->require($key !== null && $this->clinicSpecialties->active($clinic, $key) !== null, 'This clinic does not practise that specialty.');

        $profile = $practitioner->practitionerProfile()->withoutGlobalScopes()->first();
        $this->require($practitioner->status === EmploymentStatus::Active && $profile !== null && in_array($key, $profile->specialtyKeys(), true),
            "{$practitioner->name} is not a practitioner of this specialty.");
    }

    private function assertInClinic(int $clinicId): void
    {
        if ($this->clinicContext->hasClinic()) {
            $this->require($this->clinicContext->clinic()->id === $clinicId, 'That belongs to another clinic.');
        }
    }

    private function assertSameClinic(int ...$clinicIds): void
    {
        $this->require(count(array_unique($clinicIds)) === 1, 'Everything in a treatment must belong to one clinic.');
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new AuthorizationException($message);
        }
    }
}
