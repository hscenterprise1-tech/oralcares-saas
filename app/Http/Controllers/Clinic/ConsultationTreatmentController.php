<?php

namespace App\Http\Controllers\Clinic;

use App\ClinicPermission;
use App\Http\Controllers\Concerns\AnswersDomainErrors;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CatalogTreatmentVersion;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientTreatment;
use App\Services\ClinicContext;
use App\Services\Physio\PhysioRecord;
use App\Services\Physio\SessionPackages;
use App\Services\Reception\IntakeSubmissions;
use App\Services\Treatment\ConsultationCare;
use App\Services\Treatment\PatientTreatments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Start a treatment from inside a consultation, in one step: the treatment
 * (from the catalogue, or ad hoc), and for a session-based specialty its
 * first plan of care, a package and today's session, all or nothing.
 *
 * Only orchestration: every part is written by its 4E.2 writer
 * (`PatientTreatments`, `PhysioRecord`, `SessionPackages`), which authorise
 * and validate it themselves; this wraps them in one transaction so a refused
 * package never leaves a treatment behind.
 */
class ConsultationTreatmentController extends Controller
{
    use AnswersDomainErrors;

    public function __construct(
        protected ClinicContext $clinicContext,
        protected PatientTreatments $treatments,
        protected PhysioRecord $physio,
        protected SessionPackages $packages,
        protected ConsultationCare $care,
    ) {}

    public function store(Request $request, ClinicalEncounter $encounter, IntakeSubmissions $submissions): JsonResponse
    {
        $clinicId = $this->clinicContext->clinic()?->id ?? abort(403);
        abort_unless($encounter->clinic_id === $clinicId, 404);
        $this->authorize('update', $encounter);

        $data = $request->validate([
            'mode' => ['required', Rule::in(['catalog', 'adhoc'])],
            'version_id' => ['required_if:mode,catalog', 'nullable', 'integer'],
            'targets' => ['nullable', 'array', 'max:5'],
            'targets.*' => ['array', 'max:32'],
            'targets.*.*' => ['array'],
            'specialty_key' => ['required_if:mode,adhoc', 'nullable', 'string', 'max:64'],
            'title' => ['required_if:mode,adhoc', 'nullable', 'string', 'max:150'],
            'primary_diagnosis_id' => ['nullable', 'integer'],
            'goal' => ['nullable', 'string', 'max:2000'],
            'sessions_planned' => ['nullable', 'integer', 'between:1,500'],
            'plan' => ['nullable', 'array'],
            'plan.title' => ['nullable', 'string', 'max:150'],
            'plan.planned_sessions' => ['nullable', 'integer', 'between:1,200'],
            'plan.frequency' => ['nullable', 'string', 'max:60'],
            'plan.exercises' => ['nullable', 'array', 'max:40'],
            'plan.exercises.*.name' => ['required', 'string', 'max:150'],
            'plan.exercises.*.sets' => ['nullable', 'integer', 'between:0,50'],
            'plan.exercises.*.reps' => ['nullable', 'integer', 'between:0,500'],
            'plan.exercises.*.hold_seconds' => ['nullable', 'integer', 'between:0,3600'],
            'plan.exercises.*.is_home' => ['nullable', 'boolean'],
            'package' => ['nullable', 'array'],
            'package.name' => ['required_with:package', 'string', 'max:150'],
            'package.sessions_total' => ['required_with:package', 'integer', 'between:1,200'],
            'package.price' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'package.valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'open_session' => ['nullable', 'boolean'],
            'idempotency_key' => ['nullable', 'string', 'min:8', 'max:80'],
        ]);

        // Selling a package is billing work; refuse before anything is written.
        abort_if(! empty($data['package']) && ! $request->user()->hasClinicPermission(ClinicPermission::BillingCreate), 403, 'Selling a package needs billing rights.');

        // A request key made when the dialog opened starts the treatment (and
        // sells its package) once, however often the request is sent.
        $key = $data['idempotency_key'] ?? null;
        if ($key === null && ! empty($data['package'])) {
            $submissions->withoutKey($this->clinicContext->clinic(), 'consultation.treatment.package');
        }

        $me = $this->clinicContext->currentEmployee() ?? abort(403, 'Only clinic staff can start treatment.');
        $patient = Patient::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->findOrFail($encounter->patient_id);
        $branch = Branch::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->findOrFail($encounter->branch_id);
        $version = $data['mode'] === 'catalog'
            ? CatalogTreatmentVersion::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->findOrFail($data['version_id'])
            : null;

        return $submissions->once($this->clinicContext->clinic(), $request->user(), $key, "treat:{$encounter->id}", fn (): JsonResponse => $this->attemptJson(function () use ($request, $encounter, $patient, $branch, $version, $me, $data) {
            $treatment = DB::transaction(function () use ($request, $encounter, $patient, $branch, $version, $me, $data) {
                $context = ['encounter' => $encounter, 'primary_diagnosis_id' => $data['primary_diagnosis_id'] ?? null, 'goal' => $data['goal'] ?? null];
                $treatment = $version
                    ? $this->treatments->start($patient, $version, $me, $branch, $request->user(), [...$context, 'targets' => (array) ($data['targets'] ?? [])])
                    : $this->treatments->startAdHoc($patient, (string) $data['specialty_key'], (string) $data['title'], $me, $branch, $request->user(), [...$context, 'sessions_planned' => $data['sessions_planned'] ?? null]);

                $this->followUp($treatment, $encounter, $branch, $me, $data, $request);

                return $treatment;
            });

            return ['data' => ['id' => $treatment->id]];
        }, 201), atomic: true);
    }

    /**
     * The parts a session-based treatment starts with today: its plan, a
     * package for it, and this consultation's session.
     *
     * @param  array<string, mixed>  $data
     */
    private function followUp(PatientTreatment $treatment, ClinicalEncounter $encounter, Branch $branch, ClinicEmployee $me, array $data, Request $request): void
    {
        if (! $this->care->runsSessions($treatment->specialty_key)) {
            return;
        }
        $plan = $data['plan'] ?? null;
        if (is_array($plan) && (filled($plan['title'] ?? null) || ! empty($plan['exercises']))) {
            $this->physio->startPlan($treatment, [
                'title' => $plan['title'] ?? $treatment->title,
                'planned_sessions' => $plan['planned_sessions'] ?? $data['sessions_planned'] ?? null,
                'frequency' => $plan['frequency'] ?? null,
            ], array_values((array) ($plan['exercises'] ?? [])), $me, $request->user(), $encounter);
        }
        if (! empty($data['package'])) {
            $this->packages->sell($treatment, $branch, [
                'name' => $data['package']['name'],
                'sessions_total' => (int) $data['package']['sessions_total'],
                'price' => $data['package']['price'] ?? null,
                'valid_until' => $data['package']['valid_until'] ?? null,
            ], $me);
        }
        if (! empty($data['open_session'])) {
            $this->physio->openSession($treatment, $encounter, $me, $request->user());
        }
    }
}
