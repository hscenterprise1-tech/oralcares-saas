<?php

namespace App\Http\Controllers\Clinic;

use App\AppointmentStatus;
use App\ClinicPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\Consultation\AutosaveEncounterRequest;
use App\Http\Requests\Clinic\Consultation\StoreEncounterAddendumRequest;
use App\Http\Requests\Clinic\Consultation\StoreEncounterDiagnosisRequest;
use App\Http\Requests\Clinic\Consultation\StoreEncounterNoteRequest;
use App\Http\Requests\Clinic\Consultation\StoreEncounterProcedureRequest;
use App\Models\Appointment;
use App\Models\AttachedDocument;
use App\Models\ClinicalDiagnosis;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalEncounterAddendum;
use App\Models\ClinicalNote;
use App\Models\ClinicalOrder;
use App\Models\ClinicalOrderItem;
use App\Models\ClinicalProcedure;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueueItem;
use App\Models\ClinicRoom;
use App\Models\ClinicSpecialty;
use App\Models\Patient;
use App\Models\PatientDentalChart;
use App\Models\PatientFollowUp;
use App\Models\PatientProcedure;
use App\Models\PatientSessionPackage;
use App\Models\PatientVisit;
use App\Models\PhysioAssessment;
use App\Models\PhysioCarePlan;
use App\Models\PhysioSession;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\TreatmentPlan;
use App\Models\TreatmentRecord;
use App\Models\User;
use App\Services\Clinical\ClinicalCatalogue;
use App\Services\Clinical\DoctorToday;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\Clinical\StaleEncounterException;
use App\Services\ClinicContext;
use App\Services\Dental\DentalRecord;
use App\Services\Physio\PhysioRecord;
use App\Services\Treatment\ConsultationCare;
use App\Support\ClinicAccess\ClinicAccess;
use App\Support\Clinical\DefaultClinicalCatalogue;
use App\Support\ClinicClock;
use App\Support\Specialty\SpecialtyRegistry;
use App\VisitStatus;
use Closure;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The doctor's consultation workspace: their consultations list and the one
 * consultation screen, on `clinical_encounters`.
 *
 * Routes sit behind `clinic_permission:clinical.view` (writes also
 * `clinical.edit`); each action is then authorised on the record by
 * `ClinicalEncounterPolicy`. Route binding is clinic-scoped, so another
 * clinic's encounter is a 404. Every write goes through `EncounterWorkspace`.
 */
class ConsultationController extends Controller
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected EncounterWorkspace $workspace,
        protected SpecialtyRegistry $specialties,
    ) {}

    /**
     * The doctor's Today workspace: who is waiting, who is with them and who
     * is done, from Reception's check-ins. Polled for new arrivals; each row
     * starts or continues the visit in one step.
     */
    public function index(DoctorToday $today): Response
    {
        $this->authorize('viewAny', ClinicalEncounter::class);
        $clinic = $this->clinicContext->clinic() ?? abort(403);
        $branch = $this->clinicContext->requireBranch();
        $me = $this->clinicContext->currentEmployee();
        $isDoctor = $me?->practitionerProfile !== null;

        return Inertia::render('ClinicPortal/Today/Index', [
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'is_doctor' => $isDoctor,
            'today' => fn () => $today->build($clinic, $branch, $isDoctor ? $me : null),
            // The practitioner's own patients (what the old dashboard's "My day" band showed): two counts and the way to them.
            'mine' => $isDoctor ? fn () => [
                'assigned' => Patient::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('current_practitioner_id', $me->id)->count(),
                'booked_today' => Appointment::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('branch_id', $branch->id)
                    ->where('practitioner_id', $me->id)->whereOnDate('scheduled_at', ClinicClock::now())
                    ->whereNotIn('status', [AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value])->count(),
                'patients_url' => route('clinic.patients.index', ['tab' => 'mine']),
                // Their follow-ups due today or overdue, not yet booked.
                'follow_ups_due' => PatientFollowUp::query()->where('clinic_id', $clinic->id)->where('practitioner_id', $me->id)
                    ->where('status', PatientFollowUp::DUE)->where('due_on', '<=', ClinicClock::now()->toDateString())->limit(1000)->count(),
                'follow_ups_url' => route('clinic.follow-ups.index', ['tab' => 'overdue', 'mine' => '1']),
            ] : null,
            'encounter_label' => $this->specialties->encounterLabel($this->specialties->forBranch($branch, $clinic)),
            'links' => [
                'self' => route('clinic.consultations.index'),
                'call_next' => $isDoctor && app(ClinicAccess::class)->moduleEnabled('queue') ? route('clinic.queue.call-next') : null,
            ],
        ]);
    }

    /**
     * Take the called (or next waiting) patient in and open their consultation.
     */
    public function startFromQueue(ClinicQueueItem $item): RedirectResponse
    {
        $clinic = $this->clinicContext->clinic() ?? abort(403);
        $branch = $this->clinicContext->requireBranch();
        $queue = $item->queue()->withoutGlobalScopes()->first();
        abort_unless($queue && $queue->clinic_id === $clinic->id && $queue->branch_id === $branch->id, 404);

        $patient = $item->patientVisit()->withoutGlobalScopes()->first()?->patient()->withoutGlobalScopes()->first() ?? abort(404);
        $this->authorize('start', [ClinicalEncounter::class, $patient]);
        $doctor = $this->doctor();
        abort_if($item->doctor_id !== null && $item->doctor_id !== $doctor->id, 403, 'This patient is in another doctor\'s queue.');

        return $this->attempt(fn () => redirect()->route('clinic.consultations.show', $this->workspace->startFromQueue($item, $doctor)));
    }

    /**
     * Open a consultation for a patient without the queue (a review, a
     * session booked straight to the practitioner).
     */
    public function startForPatient(Request $request, Patient $patient): RedirectResponse
    {
        $this->authorize('start', [ClinicalEncounter::class, $patient]);
        $validated = $request->validate(['specialty' => ['nullable', 'string', 'max:40']]);

        return $this->attempt(fn () => redirect()->route('clinic.consultations.show', $this->workspace->startForPatient(
            $patient,
            $this->clinicContext->requireBranch(),
            $this->doctor(),
            null,
            $validated['specialty'] ?? null,
        )));
    }

    public function show(ClinicalEncounter $encounter, ConsultationCare $care): Response
    {
        $this->authorize('view', $encounter);

        $encounter->load([
            'patient' => fn ($query) => $query->withoutGlobalScopes()->with('alerts'),
            'doctor:id,name', 'finalizer:id,name', 'branch:id,name',
            'diagnoses' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('id'),
            'procedures' => fn ($query) => $query->orderBy('id'),
            'clinicalNotes' => fn ($query) => $query->with('author:id,name')->orderBy('id'),
            'documents' => fn ($query) => $query->latest('id'),
            'addenda' => fn ($query) => $query->with('author:id,name')->orderBy('id'),
            'prescriptions' => fn ($query) => $query->with('items')->latest('id'),
        ]);

        $user = request()->user();
        $me = $this->clinicContext->currentEmployee();
        $patient = $encounter->patient;
        $branchSpecialties = $encounter->branch ? $this->specialties->forBranch($encounter->branch) : [];
        $canEdit = $user->can('update', $encounter);

        return Inertia::render('ClinicPortal/Consultations/Workspace', [
            'encounter' => [
                'id' => $encounter->id,
                'number' => $encounter->encounter_number,
                'label' => $this->specialties->encounterLabel($encounter->specialty ? [$encounter->specialty] : $branchSpecialties),
                'specialty' => $encounter->specialty ? ($this->specialties->get($encounter->specialty)['label'] ?? null) : null,
                'status' => $encounter->status->value,
                'is_finalized' => $encounter->isFinalized(),
                'started_at' => $encounter->started_at?->toIso8601String(),
                'finalized_at' => $encounter->finalized_at?->toIso8601String(),
                'finalized_by' => $encounter->finalizer?->name,
                'signature_intact' => $encounter->isFinalized() ? $this->workspace->signatureIsIntact($encounter) : null,
                'doctor' => $encounter->doctor?->name,
                'branch' => $encounter->branch?->name,
                'lock_version' => $encounter->lock_version,
                'last_autosaved_at' => $encounter->last_autosaved_at?->toIso8601String(),
                'narrative' => $encounter->isFinalized() ? $this->workspace->effectiveNarrative($encounter) : collect(ClinicalEncounter::NARRATIVE)->mapWithKeys(fn ($f) => [$f => $encounter->getAttribute($f)])->all(),
                'follow_up_on' => $encounter->follow_up_on?->toDateString(),
            ],
            'patient' => [
                'id' => $patient->id,
                'name' => $patient->full_name,
                'uhid' => $patient->uhid,
                'gender' => $patient->gender,
                'age' => $patient->age,
                'blood_group' => $patient->blood_group,
                'alerts' => $patient->alerts->map(fn ($alert) => ['type' => $alert->type, 'description' => $alert->description, 'severity' => $alert->severity])->values(),
            ],
            'diagnoses' => $encounter->diagnoses->map(fn (ClinicalDiagnosis $d) => [
                'id' => $d->id, 'code' => $d->code, 'description' => $d->description, 'status' => $d->status, 'is_primary' => $d->is_primary,
            ])->values(),
            'procedures' => $encounter->procedures->map(fn (PatientProcedure $p) => [
                'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'quantity' => $p->quantity, 'notes' => $p->description,
            ])->values(),
            'notes' => $encounter->clinicalNotes->map(fn (ClinicalNote $n) => [
                'id' => $n->id, 'type' => $n->note_type, 'type_label' => ClinicalNote::TYPES[$n->note_type] ?? 'Note', 'text' => $n->content['text'] ?? '',
                'author' => $n->author?->name, 'status' => $n->status, 'created_at' => $n->created_at?->toIso8601String(),
            ])->values(),
            'documents' => $encounter->documents->map(fn (AttachedDocument $doc) => [
                'id' => $doc->id, 'name' => $doc->file_name, 'type' => $doc->document_type, 'uploaded_at' => $doc->created_at?->toIso8601String(),
                'url' => route('clinic.patients.documents.download', [$patient->id, $doc->id]),
            ])->values(),
            'prescriptions' => $encounter->prescriptions->map(fn (Prescription $rx) => [
                'id' => $rx->id,
                'number' => $rx->prescription_number,
                'status' => $rx->status,
                'notes' => $rx->notes,
                'finalized_at' => $rx->finalized_at?->toIso8601String(),
                'cancellation_reason' => $rx->cancellation_reason,
                'items' => $rx->items->map(fn (PrescriptionItem $line) => $line->only(['id', 'medicine_id', 'drug_name', 'form', 'strength', 'dosage', 'frequency', 'duration', 'route', 'timing', 'instructions']))->values(),
                'links' => [
                    'self' => route('clinic.prescriptions.update', $rx),
                    'items' => route('clinic.prescriptions.items.store', $rx),
                    'finalize' => route('clinic.prescriptions.finalize', $rx),
                    'cancel' => route('clinic.prescriptions.cancel', $rx),
                    'print' => route('clinic.prescriptions.print', $rx),
                    'download' => route('clinic.prescriptions.print', [$rx, 'download' => 1]),
                ],
                'can' => ['edit' => $user->can('update', $rx), 'cancel' => $user->can('cancel', $rx), 'print' => $user->can('print', $rx)],
            ])->values(),
            'orders' => $encounter->orders()->with('items:id,clinical_order_id,item_name,category,status,body_site')->latest('id')->get()
                ->map(fn (ClinicalOrder $order) => [
                    'id' => $order->id,
                    'number' => $order->order_number,
                    'status' => $order->status,
                    'priority' => $order->priority,
                    'url' => route('clinic.orders.show', $order),
                    'items' => $order->items->map(fn (ClinicalOrderItem $item) => $item->only(['id', 'item_name', 'category', 'status', 'body_site']))->values(),
                ])->values(),
            'order_categories' => collect(ClinicalOrderItem::CATEGORIES)->map(fn (array $category) => $category['label']),
            'dental' => $user->can('viewExtension', [$encounter, 'odontogram']) ? $this->dentalPayload($encounter, $patient, $user->can('writeExtension', [$encounter, 'odontogram'])) : null,
            'physio' => $this->physioPayload($encounter, $patient, $user),
            // Each open treatment as its own card, with its session in this consultation (partial reloads ask for `care` alone).
            'care' => fn () => $care->build($encounter, $patient, $user, $canEdit && ! $encounter->isFinalized()),
            'visit' => $this->visitContext($encounter),
            'prescription_options' => [
                'frequencies' => config('clinic_prescriptions.frequencies'),
                'timings' => config('clinic_prescriptions.timings'),
                'routes' => config('clinic_prescriptions.routes'),
                'forms' => config('clinic_prescriptions.forms'),
            ],
            'addenda' => $encounter->addenda->map(fn (ClinicalEncounterAddendum $a) => [
                'id' => $a->id, 'kind' => $a->kind, 'section' => $a->section, 'content' => $a->content, 'previous_content' => $a->previous_content,
                'reason' => $a->reason, 'author' => $a->author?->name, 'signed_at' => $a->signed_at?->toIso8601String(),
            ])->values(),
            'history' => ClinicalEncounter::query()->where('clinic_id', $encounter->clinic_id)->where('patient_id', $patient->id)
                ->whereKeyNot($encounter->id)->with('doctor:id,name')->latest('started_at')->limit(5)->get()
                ->map(fn (ClinicalEncounter $e) => $this->listRow($e, includePatient: false))->values(),
            // The consultation's specialty's services and the general ones; all of them when it has no specialty.
            'services' => $canEdit ? ClinicalProcedure::query()->where('clinic_id', $encounter->clinic_id)->where('is_active', true)->withinPlan($this->clinicContext->clinic())
                ->when($encounter->specialty, fn ($query) => $query->where(fn ($own) => $own->whereNull('specialty_key')->orWhere('specialty_key', $encounter->specialty)))
                ->orderBy('name')->limit(500)->get(['id', 'code', 'name', 'category'])->values() : [],
            'options' => [
                'diagnosis_statuses' => ClinicalDiagnosis::STATUSES,
                'note_types' => ClinicalNote::TYPES,
                'narrative' => ClinicalEncounter::NARRATIVE,
            ],
            'links' => [
                'index' => route('clinic.consultations.index'),
                'self' => route('clinic.consultations.show', $encounter),
                'autosave' => route('clinic.consultations.autosave', $encounter),
                'diagnoses' => route('clinic.consultations.diagnoses.store', $encounter),
                'procedures' => route('clinic.consultations.procedures.store', $encounter),
                'notes' => route('clinic.consultations.notes.store', $encounter),
                'finalize' => route('clinic.consultations.finalize', $encounter),
                'addenda' => route('clinic.consultations.addenda.store', $encounter),
                'patient' => route('clinic.patients.show', $patient->id),
                'upload' => route('clinic.patients.documents.store', $patient->id),
                'prescription' => route('clinic.consultations.prescription.store', $encounter),
                'medicines' => route('clinic.medicines.search'),
                'orders' => route('clinic.consultations.orders.store', $encounter),
                'investigations' => route('clinic.investigations.search'),
            ],
            'can' => [
                'edit' => $canEdit,
                'finalize' => $user->can('finalize', $encounter),
                'addendum' => $user->can('addendum', $encounter),
                'amend' => $user->can('addendum', $encounter) && $me?->id === $encounter->finalized_by,
                'upload' => $canEdit && $user->hasClinicPermission(ClinicPermission::DocumentsManage),
                'prescribe' => $user->can('create', [Prescription::class, $encounter]),
                'order' => $user->can('place', [ClinicalOrder::class, $encounter]),
            ],
        ]);
    }

    /**
     * Save the draft. 409 with the current version when another screen saved
     * first, so nothing is silently overwritten.
     */
    public function autosave(AutosaveEncounterRequest $request, ClinicalEncounter $encounter): JsonResponse
    {
        $this->authorize('update', $encounter);

        try {
            $saved = $this->workspace->autosave($encounter, $request->safe()->except('lock_version'), $request->integer('lock_version'));
        } catch (StaleEncounterException $e) {
            return response()->json(['message' => $e->getMessage(), 'lock_version' => $e->current->lock_version], 409);
        } catch (DomainException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['lock_version' => $saved->lock_version, 'saved_at' => $saved->last_autosaved_at?->toIso8601String()]);
    }

    public function storeDiagnosis(StoreEncounterDiagnosisRequest $request, ClinicalEncounter $encounter): RedirectResponse
    {
        $this->authorize('update', $encounter);

        return $this->attempt(function () use ($request, $encounter) {
            $this->workspace->addDiagnosis($encounter, $request->validated());

            return back()->with('success', 'Diagnosis added.');
        });
    }

    public function destroyDiagnosis(ClinicalEncounter $encounter, ClinicalDiagnosis $diagnosis): RedirectResponse
    {
        $this->authorize('update', $encounter);

        return $this->attempt(function () use ($encounter, $diagnosis) {
            $this->workspace->removeDiagnosis($encounter, $diagnosis);

            return back()->with('success', 'Diagnosis removed.');
        });
    }

    public function storeProcedure(StoreEncounterProcedureRequest $request, ClinicalEncounter $encounter): RedirectResponse
    {
        $this->authorize('update', $encounter);

        return $this->attempt(function () use ($request, $encounter) {
            $this->workspace->addProcedure($encounter, $request->validated());

            return back()->with('success', 'Procedure recorded.');
        });
    }

    public function destroyProcedure(ClinicalEncounter $encounter, PatientProcedure $procedure): RedirectResponse
    {
        $this->authorize('update', $encounter);

        return $this->attempt(function () use ($encounter, $procedure) {
            $this->workspace->removeProcedure($encounter, $procedure);

            return back()->with('success', 'Procedure removed.');
        });
    }

    public function storeNote(StoreEncounterNoteRequest $request, ClinicalEncounter $encounter): RedirectResponse
    {
        $this->authorize('update', $encounter);

        return $this->attempt(function () use ($request, $encounter) {
            $this->workspace->addNote($encounter, $request->validated('note_type'), $request->validated('content'));

            return back()->with('success', 'Note added.');
        });
    }

    public function finalize(ClinicalEncounter $encounter): RedirectResponse
    {
        $this->authorize('finalize', $encounter);

        return $this->attempt(function () use ($encounter) {
            $signed = $this->workspace->finalize($encounter, $this->doctor());

            return redirect()->route('clinic.consultations.show', $signed)->with('success', "{$signed->encounter_number} signed.");
        });
    }

    public function storeAddendum(StoreEncounterAddendumRequest $request, ClinicalEncounter $encounter): RedirectResponse
    {
        $this->authorize('addendum', $encounter);
        $author = $this->clinicContext->currentEmployee() ?? abort(403);

        return $this->attempt(function () use ($request, $encounter, $author) {
            $addendum = $this->workspace->addAddendum(
                $encounter,
                $author,
                $request->validated('content'),
                $request->validated('kind'),
                $request->validated('section'),
                $request->validated('reason'),
            );

            return back()->with('success', $addendum->kind === ClinicalEncounterAddendum::AMENDMENT ? 'Amendment signed.' : 'Addendum signed.');
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function listRow(ClinicalEncounter $encounter, bool $includePatient = true): array
    {
        return [
            'id' => $encounter->id,
            'url' => route('clinic.consultations.show', $encounter),
            'number' => $encounter->encounter_number,
            'status' => $encounter->isFinalized() ? 'signed' : ($encounter->status === VisitStatus::Cancelled ? 'cancelled' : 'draft'),
            'started_at' => $encounter->started_at?->toIso8601String(),
            'finalized_at' => $encounter->finalized_at?->toIso8601String(),
            'doctor' => $encounter->doctor?->name,
            'chief_complaint' => $encounter->chief_complaint,
            'patient' => $includePatient && $encounter->patient ? ['id' => $encounter->patient->id, 'name' => $encounter->patient->short_name, 'uhid' => $encounter->patient->uhid] : null,
        ];
    }

    /**
     * The dental chart and open treatment plans, for a consultation at a
     * branch whose specialty brings the `odontogram` extension.
     *
     * @return array<string, mixed>
     */
    private function dentalPayload(ClinicalEncounter $encounter, Patient $patient, bool $canWrite): array
    {
        $plans = TreatmentPlan::query()->where('clinic_id', $encounter->clinic_id)->where('patient_id', $patient->id)
            ->whereIn('status', [TreatmentPlan::PROPOSED, TreatmentPlan::ACCEPTED])
            ->with(['records' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
            ->latest('id')->limit(10)->get();

        return [
            'teeth' => app(DentalRecord::class)->chart($patient)->map(fn (PatientDentalChart $tooth) => [
                'tooth' => $tooth->tooth_number, 'status' => $tooth->status, 'surfaces' => $tooth->surfaces ?? [], 'notes' => $tooth->notes,
            ])->values(),
            'conditions' => config('clinic_dental.conditions'),
            'surfaces' => config('clinic_dental.surfaces'),
            'dentitions' => config('clinic_dental.dentitions'),
            'plans' => $plans->map(fn (TreatmentPlan $plan) => [
                'id' => $plan->id,
                'title' => $plan->title,
                'status' => $plan->status,
                'notes' => $plan->notes,
                'accept_url' => route('clinic.consultations.dental.plans.accept', [$encounter, $plan]),
                'items' => $plan->records->map(fn (TreatmentRecord $item) => [
                    'id' => $item->id,
                    'description' => $item->description,
                    'tooth' => $item->tooth_number,
                    'surfaces' => $item->surfaces ?? [],
                    'status' => $item->status?->value,
                    'complete_url' => route('clinic.consultations.dental.plans.items.complete', [$encounter, $plan, $item]),
                    'cancel_url' => route('clinic.consultations.dental.plans.items.cancel', [$encounter, $plan, $item]),
                ])->values(),
            ])->values(),
            'links' => [
                'chart' => route('clinic.consultations.dental.chart', $encounter),
                'plans' => route('clinic.consultations.dental.plans.store', $encounter),
                'tooth' => route('clinic.consultations.dental.tooth', [$encounter, 0]),
            ],
            'can_write' => $canWrite,
        ];
    }

    /**
     * The physiotherapy parts of a session, for a branch whose specialty
     * brings any of the physio extensions; null otherwise.
     *
     * @return array<string, mixed>|null
     */
    private function physioPayload(ClinicalEncounter $encounter, Patient $patient, User $user): ?array
    {
        $extensions = array_values(array_filter(
            ['physio_assessment', 'soap_notes', 'exercise_plan', 'session_packages'],
            fn (string $extension) => $user->can('viewExtension', [$encounter, $extension]),
        ));
        if ($extensions === []) {
            return null;
        }

        $assessment = PhysioAssessment::query()->where('encounter_id', $encounter->id)->first();
        // A visit may hold one session per treatment; a patient one active plan per treatment.
        $sessions = PhysioSession::query()->where('clinic_id', $encounter->clinic_id)->where('encounter_id', $encounter->id)->orderBy('id')->limit(10)->get();
        $session = $sessions->first();
        $activePlans = PhysioCarePlan::query()->where('clinic_id', $encounter->clinic_id)->where('patient_id', $patient->id)
            ->where('status', PhysioCarePlan::ACTIVE)->latest('id')->limit(10)->get(['id', 'patient_treatment_id', 'title', 'revision']);
        $plan = PhysioCarePlan::query()->where('clinic_id', $encounter->clinic_id)->where('patient_id', $patient->id)
            ->where('status', PhysioCarePlan::ACTIVE)->latest('id')->with(['exercises' => fn ($query) => $query->where('is_active', true)])->first();

        return [
            'extensions' => $extensions,
            'can_write' => collect($extensions)->mapWithKeys(fn (string $extension) => [$extension => $user->can('writeExtension', [$encounter, $extension])]),
            'assessment' => $assessment?->only(['presenting_problem', 'body_chart', 'pain_at_rest', 'pain_on_activity', 'range_of_motion', 'strength', 'posture', 'functional_measures', 'functional_notes', 'goals', 'kind', 'pain_location', 'onset', 'aggravating_factors', 'relieving_factors', 'objective_findings', 'gait', 'special_tests', 'clinical_impression', 'diagnosis']),
            'session' => $session?->only(['patient_treatment_id', 'status', 'session_number', 'subjective', 'objective', 'assessment', 'plan', 'pain_before', 'pain_after', 'modalities', 'room_id', 'package_id', 'treatment_performed', 'exercises_performed', 'patient_response', 'home_program', 'next_step']),
            'sessions' => $sessions->map(fn (PhysioSession $s) => $s->only(['id', 'patient_treatment_id', 'status', 'session_number', 'package_id']))->values(),
            'plans' => $activePlans->map(fn (PhysioCarePlan $p) => $p->only(['id', 'patient_treatment_id', 'title', 'revision']))->values(),
            'plan' => $plan ? [
                'id' => $plan->id,
                'patient_treatment_id' => $plan->patient_treatment_id,
                'revision' => $plan->revision,
                'title' => $plan->title,
                'goals' => $plan->goals,
                'interventions' => $plan->interventions ?? [],
                'planned_sessions' => $plan->planned_sessions,
                'frequency' => $plan->frequency,
                'sessions_done' => PhysioSession::query()->where('clinic_id', $plan->clinic_id)->where('patient_treatment_id', $plan->patient_treatment_id)->where('status', PhysioSession::COMPLETED)->count(),
                'exercises' => $plan->exercises->map(fn ($exercise) => $exercise->only(['id', 'name', 'sets', 'reps', 'hold_seconds', 'frequency', 'instructions', 'is_home']))->values(),
                'exercises_url' => route('clinic.consultations.physio.plans.exercises', [$encounter, $plan]),
                'close_url' => route('clinic.consultations.physio.plans.close', [$encounter, $plan]),
            ] : null,
            'packages' => PatientSessionPackage::query()->where('clinic_id', $encounter->clinic_id)->where('patient_id', $patient->id)
                ->whereIn('status', [PatientSessionPackage::ACTIVE, PatientSessionPackage::EXHAUSTED])->latest('id')->limit(10)->get()
                ->map(fn (PatientSessionPackage $package) => [
                    'id' => $package->id, 'name' => $package->name, 'status' => $package->status, 'patient_treatment_id' => $package->patient_treatment_id,
                    'sessions_total' => $package->sessions_total, 'sessions_used' => $package->sessions_used, 'remaining' => $package->remaining(),
                    'valid_until' => $package->valid_until?->toDateString(), 'expired' => $package->isExpired(),
                ])->values(),
            'rooms' => ClinicRoom::query()->where('branch_id', $encounter->branch_id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'type'])->values(),
            'progress' => app(PhysioRecord::class)->progress($patient),
            'vocabulary' => [
                'body_regions' => config('clinic_physio.body_regions'),
                'sides' => config('clinic_physio.sides'),
                'pain_types' => config('clinic_physio.pain_types'),
                'functional_measures' => config('clinic_physio.functional_measures'),
                'modalities' => config('clinic_physio.modalities'),
            ],
            'links' => [
                'assessment' => route('clinic.consultations.physio.assessment', $encounter),
                'session' => route('clinic.consultations.physio.session', $encounter),
                'plans' => route('clinic.consultations.physio.plans.store', $encounter),
                'sell_package' => route('clinic.patients.session-packages.store', $patient->id),
            ],
            'can_sell_package' => in_array('session_packages', $extensions, true) && $user->hasClinicPermission(ClinicPermission::BillingCreate),
        ];
    }

    /**
     * Why the patient is here today, as Reception recorded it at check-in.
     *
     * @return array{specialty: ?string, type: ?string, reason: ?string}
     */
    private function visitContext(ClinicalEncounter $encounter): array
    {
        $visit = $encounter->patient_visit_id ? PatientVisit::query()->withoutGlobalScopes()->where('clinic_id', $encounter->clinic_id)->find($encounter->patient_visit_id) : null;
        $specialtyKey = $visit?->clinic_specialty_id ? ClinicSpecialty::query()->withoutGlobalScopes()->whereKey($visit->clinic_specialty_id)->value('specialty_key') : $encounter->specialty;
        $type = $visit?->visit_type_key
            ? collect(app(ClinicalCatalogue::class)->effective($encounter->clinic_id, DefaultClinicalCatalogue::KIND_VISIT_TYPE, null, true))->firstWhere('key', $visit->visit_type_key)
            : null;

        return [
            'specialty' => $specialtyKey ? ($this->specialties->get($specialtyKey)['label'] ?? null) : null,
            'type' => $type['name'] ?? null,
            'reason' => $visit?->visit_reason,
        ];
    }

    private function doctor(): ClinicEmployee
    {
        $doctor = $this->clinicContext->currentEmployee();
        abort_unless($doctor?->practitionerProfile, 403, 'Only a practitioner can run a consultation.');

        return $doctor;
    }

    /**
     * Run a workspace action, turning its refusals into a message on the
     * form rather than a server error.
     *
     * @param  Closure(): RedirectResponse  $action
     */
    private function attempt(Closure $action): RedirectResponse
    {
        try {
            return $action();
        } catch (DomainException|InvalidArgumentException $e) {
            return back()->withErrors(['consultation' => $e->getMessage()]);
        }
    }
}
