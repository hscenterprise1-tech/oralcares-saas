<?php

namespace App\Http\Controllers\Clinic;

use App\ClinicPermission;
use App\Http\Controllers\Concerns\AnswersDomainErrors;
use App\Http\Controllers\Concerns\BindsToActiveClinic;
use App\Http\Controllers\Controller;
use App\Models\CatalogTreatmentVersion;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalProcedure;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientTreatment;
use App\Models\PatientTreatmentStage;
use App\Services\ClinicContext;
use App\Services\Patient\ClinicSpecialties;
use App\Services\Patient\PatientBranchAccess;
use App\Services\Treatment\PatientTreatments;
use App\Services\Treatment\TreatmentAccess;
use App\Services\Treatment\TreatmentReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Patient treatment API (T7 start, T8 detail). Reads go through
 * `TreatmentReadModel`; every write is a `PatientTreatments` call, which
 * authorises it (`TreatmentAccess`: permission, clinic, branch, patient,
 * specialty, practitioner) and keeps the history.
 */
class PatientTreatmentController extends Controller
{
    use AnswersDomainErrors, BindsToActiveClinic;

    public function __construct(
        protected ClinicContext $clinicContext,
        protected PatientTreatments $treatments,
        protected TreatmentAccess $access,
        protected TreatmentReadModel $readModel,
        protected PatientBranchAccess $branchAccess,
        protected ClinicSpecialties $clinicSpecialties,
    ) {}

    /** A patient's treatments, open first. */
    public function index(Request $request, Patient $patient): JsonResponse
    {
        $this->inActiveClinic($patient);
        $this->authorize('view', $patient);
        $openOnly = $request->boolean('open');

        return response()->json(['data' => $this->readModel->forPatient($patient->clinic_id, $patient->id, $this->branchAccess->branchIds(), $openOnly, $this->seesMoney($request), 100)]);
    }

    /** What the signed-in practitioner may start for this patient (T7's picker). */
    public function options(Request $request, Patient $patient): JsonResponse
    {
        $this->inActiveClinic($patient);
        $this->authorize('view', $patient);
        abort_unless($request->user()->hasClinicPermission(ClinicPermission::TreatmentStart), 403, 'You cannot start treatment.');
        $clinic = $this->clinicContext->clinic();
        $specialties = $this->access->boardSpecialties($request->user()) ?? $this->clinicSpecialties->activeKeys($clinic);
        $specialties = array_values(array_intersect($specialties, $this->clinicSpecialties->activeKeys($clinic)));

        return response()->json(['data' => $this->readModel->startable($clinic->id, $specialties, $this->clinicContext->workingBranch()?->id, $this->seesMoney($request))]);
    }

    public function store(Request $request, Patient $patient): JsonResponse
    {
        $this->inActiveClinic($patient);
        $clinicId = $this->clinicContext->clinic()->id;
        $data = $request->validate([
            'version_id' => ['required', 'integer'],
            'practitioner_id' => ['nullable', 'integer'],
            'encounter_id' => ['nullable', 'integer'],
            'targets' => ['nullable', 'array', 'max:5'],
            'targets.*' => ['array', 'max:32'],
            'targets.*.*' => ['array'],
        ]);
        $version = CatalogTreatmentVersion::query()->where('clinic_id', $clinicId)->findOrFail($data['version_id']);
        $practitioner = isset($data['practitioner_id'])
            ? ClinicEmployee::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->findOrFail($data['practitioner_id'])
            : $this->performer();
        $encounter = isset($data['encounter_id']) ? ClinicalEncounter::query()->where('clinic_id', $clinicId)->findOrFail($data['encounter_id']) : null;

        return $this->attemptJson(function () use ($request, $patient, $version, $practitioner, $encounter, $data) {
            $treatment = $this->treatments->start($patient, $version, $practitioner, $this->clinicContext->requireBranch(), $request->user(), [
                'encounter' => $encounter,
                'targets' => (array) ($data['targets'] ?? []),
            ]);

            return ['data' => $this->readModel->detail($treatment->fresh(), $this->seesMoney($request))];
        }, 201);
    }

    public function show(Request $request, PatientTreatment $treatment): JsonResponse
    {
        $this->inActiveClinic($treatment);
        $this->access->assertCanView($request->user(), $treatment);

        return response()->json(['data' => $this->readModel->detail($treatment, $this->seesMoney($request))]);
    }

    public function startStage(Request $request, PatientTreatment $treatment, PatientTreatmentStage $stage): JsonResponse
    {
        $this->inActiveClinic($treatment, $stage);
        $encounter = $this->encounter($request);

        return $this->attemptJson(fn () => $this->answer($request, $this->treatments->startStage($stage, $request->user(), $this->performer(), $encounter)->patient_treatment_id));
    }

    public function completeStage(Request $request, PatientTreatment $treatment, PatientTreatmentStage $stage): JsonResponse
    {
        $this->inActiveClinic($treatment, $stage);
        $encounter = $this->encounter($request);

        return $this->attemptJson(fn () => $this->answer($request, $this->treatments->completeStage($stage, $request->user(), $this->performer(), $encounter)->patient_treatment_id));
    }

    public function skipStage(Request $request, PatientTreatment $treatment, PatientTreatmentStage $stage): JsonResponse
    {
        $this->inActiveClinic($treatment, $stage);

        return $this->attemptJson(fn () => $this->answer($request, $this->treatments->skipStage($stage, $request->user(), $this->performer())->patient_treatment_id));
    }

    public function recordProcedure(Request $request, PatientTreatment $treatment, PatientTreatmentStage $stage): JsonResponse
    {
        $this->inActiveClinic($treatment, $stage);
        $clinicId = $this->clinicContext->clinic()->id;
        $data = $request->validate([
            'encounter_id' => ['required', 'integer'],
            'clinical_procedure_id' => ['required', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $encounter = ClinicalEncounter::query()->where('clinic_id', $clinicId)->findOrFail($data['encounter_id']);
        $procedure = ClinicalProcedure::query()->where('clinic_id', $clinicId)->withinPlan($this->clinicContext->clinic())->findOrFail($data['clinical_procedure_id']);

        return $this->attemptJson(function () use ($request, $treatment, $stage, $encounter, $procedure, $data) {
            $this->treatments->recordProcedure($stage, $procedure, $encounter, $this->performer(), $request->user(), (int) ($data['quantity'] ?? 1), $data['notes'] ?? null);

            return $this->answer($request, $treatment->id);
        }, 201);
    }

    public function pause(Request $request, PatientTreatment $treatment): JsonResponse
    {
        $this->inActiveClinic($treatment);
        $reason = $this->reason($request);

        return $this->attemptJson(fn () => $this->answer($request, $this->treatments->pause($treatment, $request->user(), $this->performer(), $reason)->id));
    }

    public function resume(Request $request, PatientTreatment $treatment): JsonResponse
    {
        $this->inActiveClinic($treatment);

        return $this->attemptJson(fn () => $this->answer($request, $this->treatments->resume($treatment, $request->user(), $this->performer())->id));
    }

    public function cancel(Request $request, PatientTreatment $treatment): JsonResponse
    {
        $this->inActiveClinic($treatment);
        $reason = $this->reason($request);

        return $this->attemptJson(fn () => $this->answer($request, $this->treatments->cancel($treatment, $request->user(), $this->performer(), $reason)->id));
    }

    /** The signed-in member acting as performer: only a staff member of this clinic can record treatment. */
    private function performer(): ClinicEmployee
    {
        return $this->clinicContext->currentEmployee() ?? abort(403, 'Only clinic staff can record treatment.');
    }

    private function encounter(Request $request): ?ClinicalEncounter
    {
        $id = $request->validate(['encounter_id' => ['nullable', 'integer']])['encounter_id'] ?? null;

        return $id ? ClinicalEncounter::query()->where('clinic_id', $this->clinicContext->clinic()->id)->findOrFail($id) : null;
    }

    private function reason(Request $request): string
    {
        return $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'];
    }

    private function seesMoney(Request $request): bool
    {
        return $request->user()->hasClinicPermission(ClinicPermission::BillingView);
    }

    /**
     * @return array{data: array<string, mixed>}
     */
    private function answer(Request $request, int $treatmentId): array
    {
        $treatment = PatientTreatment::query()->withoutGlobalScopes()->where('clinic_id', $this->clinicContext->clinic()->id)->findOrFail($treatmentId);

        return ['data' => $this->readModel->detail($treatment, $this->seesMoney($request))];
    }
}
