<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Concerns\AnswersDomainErrors;
use App\Http\Controllers\Concerns\BindsToActiveClinic;
use App\Http\Controllers\Controller;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientSessionPackage;
use App\Models\PatientTreatment;
use App\Models\PhysioCarePlan;
use App\Models\PhysioSession;
use App\Services\ClinicContext;
use App\Services\Physio\PhysioRecord;
use App\Services\Physio\SessionPackages;
use App\Services\Reception\IntakeSubmissions;
use App\Services\Treatment\PatientTreatments;
use App\Services\Treatment\TreatmentAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Care inside one patient treatment, addressed by the treatment: its context
 * (diagnosis, goal), ad-hoc start and completion, its plan of care
 * revisions, its sessions (open → save → complete → reverse) and its
 * packages. Everything nested under a treatment must belong to it, or the
 * answer is 404; every write is authorised by the services
 * (`TreatmentAccess`), not by this controller's routes alone.
 */
class TreatmentCareController extends Controller
{
    use AnswersDomainErrors, BindsToActiveClinic;

    public function __construct(
        protected ClinicContext $clinicContext,
        protected PatientTreatments $treatments,
        protected PhysioRecord $physio,
        protected SessionPackages $packages,
        protected TreatmentAccess $access,
    ) {}

    public function startAdHoc(Request $request, Patient $patient): JsonResponse
    {
        $this->inActiveClinic($patient);
        $clinicId = $this->clinicContext->clinic()->id;
        $data = $request->validate([
            'specialty_key' => ['required', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:150'],
            'practitioner_id' => ['nullable', 'integer'],
            'encounter_id' => ['nullable', 'integer'],
            'goal' => ['nullable', 'string', 'max:2000'],
            'primary_diagnosis_id' => ['nullable', 'integer'],
            'sessions_planned' => ['nullable', 'integer', 'between:1,500'],
        ]);
        $practitioner = isset($data['practitioner_id'])
            ? ClinicEmployee::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->findOrFail($data['practitioner_id'])
            : $this->performer();
        $encounter = isset($data['encounter_id']) ? ClinicalEncounter::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->findOrFail($data['encounter_id']) : null;

        return $this->attemptJson(fn () => ['data' => $this->summary($this->treatments->startAdHoc(
            $patient, $data['specialty_key'], $data['title'], $practitioner, $this->clinicContext->requireBranch(), $request->user(),
            ['encounter' => $encounter, 'goal' => $data['goal'] ?? null, 'primary_diagnosis_id' => $data['primary_diagnosis_id'] ?? null, 'sessions_planned' => $data['sessions_planned'] ?? null],
        ))], 201);
    }

    public function context(Request $request, PatientTreatment $treatment): JsonResponse
    {
        $this->inActiveClinic($treatment);
        $data = $request->validate([
            'primary_diagnosis_id' => ['nullable', 'integer'],
            'goal' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->attemptJson(fn () => ['data' => $this->summary($this->treatments->setClinicalContext($treatment, $data['primary_diagnosis_id'] ?? null, $data['goal'] ?? null, $request->user(), $this->performer()))]);
    }

    public function complete(Request $request, PatientTreatment $treatment): JsonResponse
    {
        $this->inActiveClinic($treatment);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return $this->attemptJson(fn () => ['data' => $this->summary($this->treatments->complete($treatment, $request->user(), $this->performer(), $note))]);
    }

    // ---------------------------------------------------------------- Plans

    public function startPlan(Request $request, PatientTreatment $treatment): JsonResponse
    {
        $this->inActiveClinic($treatment);
        $data = $this->planInput($request);
        $encounter = isset($data['encounter_id']) ? $this->encounterOf($treatment, (int) $data['encounter_id']) : null;

        return $this->attemptJson(fn () => ['data' => $this->planData($this->physio->startPlan($treatment, $data, $data['exercises'] ?? [], $this->performer(), $request->user(), $encounter))], 201);
    }

    public function revisePlan(Request $request, PatientTreatment $treatment, PhysioCarePlan $plan): JsonResponse
    {
        $this->inActiveClinic($treatment, $plan);
        $this->belongsTo($plan, $treatment);
        $data = $this->planInput($request);

        return $this->attemptJson(fn () => ['data' => $this->planData($this->physio->revisePlan($plan, $data, $data['exercises'] ?? [], $this->performer(), $request->user()))], 201);
    }

    // ---------------------------------------------------------------- Sessions

    public function openSession(Request $request, PatientTreatment $treatment): JsonResponse
    {
        $this->inActiveClinic($treatment);
        $encounter = $this->encounterOf($treatment, (int) $request->validate(['encounter_id' => ['required', 'integer']])['encounter_id']);

        return $this->attemptJson(fn () => ['data' => $this->sessionData($this->physio->openSession($treatment, $encounter, $this->performer(), $request->user()))], 201);
    }

    public function saveSession(Request $request, PatientTreatment $treatment, PhysioSession $session): JsonResponse
    {
        $this->inActiveClinic($treatment, $session);
        $this->belongsTo($session, $treatment);
        $data = $request->validate([
            'subjective' => ['nullable', 'string', 'max:10000'],
            'objective' => ['nullable', 'string', 'max:10000'],
            'assessment' => ['nullable', 'string', 'max:10000'],
            'plan' => ['nullable', 'string', 'max:10000'],
            'pain_before' => ['nullable', 'integer', 'between:0,10'],
            'pain_after' => ['nullable', 'integer', 'between:0,10'],
            'modalities' => ['sometimes', 'array'],
            'modalities.*' => [Rule::in(array_keys(config('clinic_physio.modalities')))],
            'room_id' => ['nullable', 'integer'],
            'treatment_performed' => ['nullable', 'string', 'max:10000'],
            'patient_response' => ['nullable', 'string', 'max:5000'],
            'home_program' => ['nullable', 'string', 'max:5000'],
            'next_step' => ['nullable', 'string', 'max:500'],
            'exercises' => ['sometimes', 'array', 'max:60'],
            'exercises.*.physio_exercise_id' => ['nullable', 'integer'],
            'exercises.*.name' => ['nullable', 'string', 'max:150'],
            'exercises.*.status' => ['nullable', Rule::in(['performed', 'skipped'])],
            'exercises.*.sets' => ['nullable', 'integer', 'between:0,50'],
            'exercises.*.reps' => ['nullable', 'integer', 'between:0,500'],
            'exercises.*.duration_seconds' => ['nullable', 'integer', 'between:0,14400'],
            'exercises.*.intensity' => ['nullable', 'string', 'max:80'],
            'exercises.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->attemptJson(fn () => ['data' => $this->sessionData($this->physio->saveSession($session, $data, $this->performer(), $request->user()))]);
    }

    public function completeSession(Request $request, PatientTreatment $treatment, PhysioSession $session): JsonResponse
    {
        $this->inActiveClinic($treatment, $session);
        $this->belongsTo($session, $treatment);
        $packageId = $request->validate(['package_id' => ['nullable', 'integer']])['package_id'] ?? null;

        return $this->attemptJson(fn () => ['data' => $this->sessionData($this->physio->completeSession($session, $packageId !== null ? (int) $packageId : null, $this->performer(), $request->user()))]);
    }

    public function reverseSession(Request $request, PatientTreatment $treatment, PhysioSession $session): JsonResponse
    {
        $this->inActiveClinic($treatment, $session);
        $this->belongsTo($session, $treatment);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'];

        return $this->attemptJson(fn () => ['data' => $this->sessionData($this->physio->reverseSession($session, $reason, $this->performer(), $request->user()))]);
    }

    // ---------------------------------------------------------------- Packages

    /** The packages that may pay for this treatment's session now, with what each has left. */
    public function eligiblePackages(Request $request, PatientTreatment $treatment): JsonResponse
    {
        $this->inActiveClinic($treatment);
        $this->access->assertCanView($request->user(), $treatment);

        return response()->json(['data' => $this->packages->eligible($treatment)->map(fn (array $row) => [
            ...$this->packageData($row['package']), 'remaining' => $row['remaining'],
        ])->values()]);
    }

    /**
     * Sell a package for this treatment (and bill it, when it has a price).
     * A request key made when the dialog opened sells it once however often
     * it is sent; another package is another key.
     */
    public function sellPackage(Request $request, PatientTreatment $treatment, IntakeSubmissions $submissions): JsonResponse
    {
        $this->inActiveClinic($treatment);
        abort_unless(SessionPackages::maySell($request->user()), 403, 'Selling a package needs the package-selling permission.');
        $this->access->assertCanView($request->user(), $treatment);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'sessions_total' => ['required', 'integer', 'between:1,200'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'clinical_procedure_id' => ['nullable', 'integer'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'renews_package_id' => ['nullable', 'integer'],
            'idempotency_key' => ['nullable', 'string', 'min:8', 'max:80'],
        ]);
        $key = $data['idempotency_key'] ?? null;
        unset($data['idempotency_key']);
        $clinic = $this->clinicContext->clinic() ?? abort(403);
        if ($key === null) {
            $submissions->withoutKey($clinic, 'treatment.package.sell');
        }

        return $submissions->once($clinic, $request->user(), $key, "pkg-t:{$treatment->id}",
            fn (): JsonResponse => $this->attemptJson(fn () => ['data' => $this->packageData($this->packages->sell($treatment, $this->clinicContext->requireBranch(), $data, $this->performer()))], 201), atomic: true);
    }

    public function adjustPackage(Request $request, PatientTreatment $treatment, PatientSessionPackage $package): JsonResponse
    {
        $this->inActiveClinic($treatment, $package);
        $this->belongsTo($package, $treatment);
        abort_unless(SessionPackages::maySell($request->user()), 403, 'Adjusting a package needs the package-selling permission.');
        $this->access->assertCanView($request->user(), $treatment);
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'between:-200,200', 'not_in:0'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return $this->attemptJson(fn () => ['data' => $this->packageData($this->packages->adjust($package, (int) $data['quantity'], $data['reason'], $request->user()))]);
    }

    // ---------------------------------------------------------------- Helpers

    private function performer(): ClinicEmployee
    {
        return $this->clinicContext->currentEmployee() ?? abort(403, 'Only clinic staff can record treatment.');
    }

    /** A record addressed under a treatment that is not that treatment's does not exist here. */
    private function belongsTo(Model $record, PatientTreatment $treatment): void
    {
        abort_unless((int) $record->getAttribute('patient_treatment_id') === $treatment->id, 404);
    }

    private function encounterOf(PatientTreatment $treatment, int $encounterId): ClinicalEncounter
    {
        return ClinicalEncounter::query()->withoutGlobalScopes()->where('clinic_id', $treatment->clinic_id)
            ->where('patient_id', $treatment->patient_id)->findOrFail($encounterId);
    }

    /**
     * @return array<string, mixed>
     */
    private function planInput(Request $request): array
    {
        return $request->validate([
            'encounter_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:150'],
            'goals' => ['nullable', 'string', 'max:5000'],
            'interventions' => ['sometimes', 'array'],
            'interventions.*' => [Rule::in(array_keys(config('clinic_physio.modalities')))],
            'planned_sessions' => ['nullable', 'integer', 'between:1,200'],
            'frequency' => ['nullable', 'string', 'max:60'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'exercises' => ['sometimes', 'array', 'max:40'],
            'exercises.*.name' => ['required', 'string', 'max:150'],
            'exercises.*.sets' => ['nullable', 'integer', 'between:0,50'],
            'exercises.*.reps' => ['nullable', 'integer', 'between:0,500'],
            'exercises.*.hold_seconds' => ['nullable', 'integer', 'between:0,600'],
            'exercises.*.frequency' => ['nullable', 'string', 'max:60'],
            'exercises.*.instructions' => ['nullable', 'string', 'max:1000'],
            'exercises.*.is_home' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(PatientTreatment $treatment): array
    {
        return $treatment->only(['id', 'patient_id', 'title', 'goal', 'primary_diagnosis_id', 'specialty_key', 'source', 'status', 'sessions_planned', 'sessions_completed', 'practitioner_id']);
    }

    /**
     * @return array<string, mixed>
     */
    private function planData(PhysioCarePlan $plan): array
    {
        return [
            ...$plan->only(['id', 'patient_treatment_id', 'revision', 'supersedes_id', 'status', 'title', 'goals', 'planned_sessions', 'frequency']),
            'effective_from' => $plan->effective_from?->toDateString(),
            'exercises' => $plan->exercises()->get(['id', 'name', 'sets', 'reps', 'hold_seconds', 'frequency', 'instructions', 'is_home'])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionData(PhysioSession $session): array
    {
        return [
            ...$session->only(['id', 'patient_treatment_id', 'encounter_id', 'care_plan_id', 'status', 'session_number', 'package_id', 'subjective', 'objective', 'assessment', 'plan', 'pain_before', 'pain_after']),
            'exercises' => $session->performedExercises()->get(['id', 'physio_exercise_id', 'name', 'status', 'sets', 'reps', 'duration_seconds', 'intensity', 'notes'])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function packageData(PatientSessionPackage $package): array
    {
        return [
            ...$package->only(['id', 'patient_treatment_id', 'renews_package_id', 'name', 'status', 'sessions_total', 'sessions_used', 'sessions_adjusted']),
            'remaining' => $package->remaining(),
            'valid_until' => $package->valid_until?->toDateString(),
            'expired' => $package->isExpired(),
        ];
    }
}
