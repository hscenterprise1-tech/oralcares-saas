<?php

namespace App\Http\Controllers\Clinic;

use App\ClinicPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Patient\RegisterPatientRequest;
use App\Http\Requests\Patient\UpdatePatientRequest;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ConsentRecord;
use App\Models\Patient;
use App\Models\PatientDoctorAssignment;
use App\Models\PatientMerge;
use App\Models\PatientProfile;
use App\Models\PatientTimelineEvent;
use App\Models\User;
use App\PatientStatus;
use App\Services\Billing\CheckInCharges;
use App\Services\Clinical\ClinicalCatalogue;
use App\Services\Clinical\ClinicalContextResolver;
use App\Services\Clinical\ClinicalPrivacy;
use App\Services\ClinicContext;
use App\Services\MedicalHistoryService;
use App\Services\Patient\ClinicSpecialties;
use App\Services\Patient\PatientBranchAccess;
use App\Services\Patient\PatientClinicalEntries;
use App\Services\Patient\PatientDirectory;
use App\Services\Patient\PatientDirectoryAudience;
use App\Services\Patient\PatientRegistrar;
use App\Services\Patient\PatientSearch;
use App\Services\PatientDuplicateDetectionService;
use App\Services\PatientFieldConfigService;
use App\Services\Reception\IntakeOutcome;
use App\Services\Reception\IntakeSubmissions;
use App\Services\Reception\PatientFlowConfigService;
use App\Services\Reception\ReceptionCheckIn;
use App\Services\Reception\VisitContext;
use App\Support\Clinical\DefaultClinicalCatalogue;
use App\Support\ClinicClock;
use App\Support\ClinicFrontend;
use App\Support\Specialty\SpecialtyRegistry;
use App\VisitPriority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PatientController extends Controller
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected PatientDuplicateDetectionService $duplicateDetector,
        protected PatientRegistrar $registrar,
        protected PatientClinicalEntries $clinicalEntries,
        protected ClinicalCatalogue $catalogue,
        protected ClinicalPrivacy $privacy,
        protected MedicalHistoryService $medicalHistoryService,
        protected PatientFieldConfigService $fieldConfigService,
        protected PatientBranchAccess $branchAccess,
        protected PatientDirectory $directory,
        protected ClinicSpecialties $clinicSpecialties,
        protected SpecialtyRegistry $specialtyRegistry,
        protected ClinicalContextResolver $contextResolver,
    ) {}

    /**
     * Display the searchable, paginated Patient Directory.
     */
    /**
     * The patient directory: the clinic-wide record, searched and filtered on
     * the server (`PatientDirectory`: clinic-leading indexed queries, prefix
     * search, branch restriction, keyset pages, batched columns). No COUNT,
     * no offset paging, nothing filtered in the browser.
     */
    public function index(Request $request, PatientDirectoryAudience $audience): Response
    {
        $this->authorize('viewAny', Patient::class);

        $clinic = $this->clinicContext->clinic();
        if (! $clinic) {
            abort(403, 'No active clinic selected.');
        }

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'specialty' => ['nullable', 'string', 'max:50'],
            'branch_id' => ['nullable', 'integer'],
            'practitioner_id' => ['nullable', 'regex:/^(none|\d{1,18})$/'],
            'active_care' => ['nullable', 'boolean'],
            'view' => ['nullable', Rule::in([PatientDirectoryAudience::VIEW_MINE, PatientDirectoryAudience::VIEW_TODAY, PatientDirectoryAudience::VIEW_ALL])],
            // The earlier "My patients / All patients" switch, kept for old links.
            'tab' => ['nullable', Rule::in(['mine', 'all'])],
            'cursor' => ['nullable', 'string', 'max:500'],
        ]);

        $who = $audience->audience();
        $view = $audience->resolveView($validated['view'] ?? $validated['tab'] ?? null);
        $scope = $audience->filtersFor($view);
        // In a doctor's own list the doctor is the filter; nobody else's patients can be asked for.
        $practitioner = isset($scope['practitioner_id']) ? null : ($validated['practitioner_id'] ?? null);

        $filters = array_filter([
            'q' => trim((string) ($validated['q'] ?? '')),
            'specialty' => $validated['specialty'] ?? null,
            'branch_id' => isset($validated['branch_id']) ? (int) $validated['branch_id'] : null,
            'active_care' => ($validated['active_care'] ?? false) ? true : null,
            'practitioner_id' => $practitioner,
        ], fn ($value) => $value !== null && $value !== '');

        return Inertia::render('ClinicPortal/Patients/Index', [
            'patients' => $this->directory->page($clinic->id, [...$filters, ...$scope], $validated['cursor'] ?? null, PatientDirectory::PER_PAGE, $audience->canSeeContact($request->user())),
            'filters' => [
                'q' => $filters['q'] ?? '',
                // There is no patient-status filter; the key stays empty so the page's contract keeps its shape.
                'status' => '',
                'specialty' => $filters['specialty'] ?? '',
                'branch_id' => isset($filters['branch_id']) ? (string) $filters['branch_id'] : '',
                'practitioner_id' => isset($filters['practitioner_id']) ? (string) $filters['practitioner_id'] : '',
                'active_care' => isset($filters['active_care']) ? '1' : '',
                'view' => $view,
            ],
            'options' => [
                'specialties' => $this->specialtyOptions($clinic),
                // The directory lists the current patients; there is no other lifecycle view to choose.
                'statuses' => [],
                'views' => $audience->views(),
                'doctors' => collect(ClinicEmployee::activePractitioners($clinic->id))->map(fn (array $doctor) => ['id' => $doctor['id'], 'name' => $doctor['name']])->all(),
            ],
            'audience' => $who,
            'today' => ClinicClock::now()->toDateString(),
            'isPractitioner' => $who === PatientDirectoryAudience::DOCTOR,
            'can' => ['create' => $request->user()->can('create', Patient::class)],
            // Only pages that are built (`ClinicFrontend`); a page still to come is not linked.
            'links' => [
                'index' => route('clinic.patients.index'),
                'profile' => ClinicFrontend::available('clinic.patients.show') ? route('clinic.patients.show', ['patient' => '__ID__']) : null,
                'create' => ClinicFrontend::available('clinic.patients.create') && $request->user()->can('create', Patient::class) ? route('clinic.patients.create') : null,
            ],
        ]);
    }

    /**
     * The registration page: identity, contact, the clinic context (the
     * clinic's active specialties, the branch registering), medical flags.
     */
    public function create(): Response
    {
        $this->authorize('create', Patient::class);

        $clinic = $this->clinicContext->clinic();

        return Inertia::render('ClinicPortal/Patients/Create', [
            ...$this->formOptions($clinic),
            'branch' => ($branch = $this->clinicContext->branch()) ? ['id' => $branch->id, 'name' => $branch->display_name ?: $branch->name] : null,
            'form' => $clinic ? $this->registrationForm($clinic, request()->user()) : null,
            'links' => ['index' => route('clinic.patients.index')],
        ]);
    }

    /**
     * What the shared registration fields need, in the same shape as New
     * Case's configuration (`ReceptionController::intake`) but for the full
     * page: its field settings, the clinic's catalogue, the allergy-status
     * rule and whether this member may record clinical facts.
     *
     * @return array<string, mixed>
     */
    private function registrationForm(Clinic $clinic, User $user): array
    {
        $item = fn (array $entry) => collect($entry)->only(['key', 'name', 'category', 'severity', 'specialty_keys', 'practice_area_keys', 'required'])->all();
        $fieldSpecialties = collect($this->fieldConfigService->definitions($clinic))->map(fn (array $definition) => $definition['specialties']);
        $quickCare = app(PatientFlowConfigService::class)->quickCare($clinic);
        $canRecord = $this->privacy->canRecordAtRegistration($user);

        return [
            'fields' => collect($this->fieldConfigService->effective($clinic, $user, null, PatientFieldConfigService::SURFACE_FULL_PAGE))
                ->map(fn (array $field, string $name) => [...$field, 'specialties' => $fieldSpecialties[$name] ?? null])->all(),
            'catalogue' => [
                'conditions' => array_map($item, $this->catalogue->effective($clinic->id, DefaultClinicalCatalogue::KIND_CONDITION)),
                'allergies' => array_map($item, $this->catalogue->effective($clinic->id, DefaultClinicalCatalogue::KIND_ALLERGY)),
                'medications' => array_map($item, $this->catalogue->effective($clinic->id, DefaultClinicalCatalogue::KIND_MEDICATION)),
                'surgical_history' => array_map($item, $this->catalogue->effective($clinic->id, DefaultClinicalCatalogue::KIND_SURGERY)),
                'family_history' => array_map($item, $this->catalogue->effective($clinic->id, DefaultClinicalCatalogue::KIND_FAMILY_HISTORY)),
                'alerts' => $quickCare['alerts'] && $canRecord && $this->fieldConfigService->roleAllowed($user, $quickCare['alert_roles'])
                    ? array_map($item, $this->catalogue->effective($clinic->id, DefaultClinicalCatalogue::KIND_ALERT)) : [],
            ],
            'quick_care' => ['allergy_status' => $quickCare['allergy_status']],
            'can' => ['record_clinical' => $canRecord],
        ];
    }

    /**
     * The edit page: the same form as registration, pre-filled. Specialty
     * membership and status are shown, not edited here (membership comes from
     * explicit acts; status from governed actions). A merged record opens its
     * survivor.
     */
    public function edit(Patient $patient): Response|RedirectResponse
    {
        $clinic = $this->clinicContext->clinic();
        if (! $clinic || $patient->clinic_id !== $clinic->id) {
            abort(404);
        }
        if ($patient->status === PatientStatus::Merged) {
            $survivorId = PatientMerge::query()->where('clinic_id', $clinic->id)->where('source_patient_id', $patient->id)->value('survivor_patient_id');

            return redirect()->route('clinic.patients.show', $survivorId ?? $patient->id)->with('warning', 'That record was merged into this patient; edit this one instead.');
        }
        $this->authorize('update', $patient);

        $patient->load(['profile', 'medicalHistory', 'alerts', 'currentPractitioner']);
        $canViewClinical = $this->privacy->canViewDetail(request()->user());
        $enrolled = DB::table('patient_specialties as ps')->join('clinic_specialties as cs', 'cs.id', '=', 'ps.clinic_specialty_id')
            ->where('ps.clinic_id', $clinic->id)->where('ps.patient_id', $patient->id)->where('ps.status', 'active')
            ->pluck('cs.specialty_key')->values()->all();

        return Inertia::render('ClinicPortal/Patients/Edit', [
            'patient' => [
                'id' => $patient->id,
                'uhid' => $patient->uhid,
                'status' => $patient->status?->value ?? 'active',
                'first_name' => $patient->first_name ?? '',
                'middle_name' => $patient->middle_name ?? '',
                'last_name' => $patient->last_name ?? '',
                'gender' => $patient->gender ?? '',
                'date_of_birth' => $patient->date_of_birth?->format('Y-m-d') ?? '',
                // Age is derived from the date of birth when there is one; a stored age only stands in without it.
                'age' => $patient->age,
                'age_is_estimated' => $patient->date_of_birth === null && $patient->age !== null,
                'blood_group' => $patient->blood_group ?? '',
                'phone' => $patient->phone ?? '',
                'alternate_phone' => $patient->alternate_phone ?? '',
                'email' => $patient->email ?? '',
                'address' => $patient->profile?->address ?? '',
                'locality' => $patient->profile?->locality ?? '',
                'city' => $patient->profile?->city ?? '',
                'state' => $patient->profile?->state ?? '',
                'pincode' => $patient->profile?->pincode ?? '',
                'country' => $patient->profile?->country ?? '',
                'preferred_channel' => $patient->profile?->preferred_channel ?? '',
                'emergency_contact_name' => $patient->profile?->emergency_contact_name ?? '',
                'emergency_contact_phone' => $patient->profile?->emergency_contact_phone ?? '',
                // The clinical record goes only to roles that may see it; every
                // role that can open the patient gets the safety flags.
                'allergies' => $canViewClinical ? $this->clinicalEntries->allergyLabels($patient) : [],
                'medical_alerts' => $canViewClinical ? $patient->alerts->where('type', 'manual')->pluck('description')->values()->all() : [],
                'safety_flags' => $this->clinicalEntries->safetyFlags($clinic->id, [$patient->id])[$patient->id] ?? [],
                'specialties' => $enrolled,
                'current_practitioner_name' => $patient->currentPractitioner?->name,
                'registered_on' => $patient->created_at?->toDateString(),
            ],
            'clinical' => ['can_view' => $canViewClinical, 'can_edit' => $this->privacy->canEdit(request()->user())],
            ...$this->formOptions($clinic),
        ]);
    }

    /**
     * What both forms choose from. Bounded lists only: the clinic's active
     * specialties, its active practitioners, the clinic's enabled conditions
     * and allergies (cached catalogue), the field settings for this member.
     *
     * @return array<string, mixed>
     */
    private function formOptions(?Clinic $clinic): array
    {
        $catalogueItem = fn (array $item) => [
            'key' => $item['key'],
            'name' => $item['name'],
            'category' => $item['category'],
            'category_label' => $item['category'] ? str($item['category'])->headline()->toString() : 'Other',
            'severity' => $item['severity'],
            'specialty_keys' => $item['specialty_keys'],
        ];

        return [
            'practitioners' => $clinic ? ClinicEmployee::activePractitioners($clinic->id) : [],
            'allergyCatalogue' => $clinic ? array_map($catalogueItem, $this->catalogue->effective($clinic->id, DefaultClinicalCatalogue::KIND_ALLERGY)) : [],
            'conditionCatalogue' => $clinic ? array_map($catalogueItem, $this->catalogue->effective($clinic->id, DefaultClinicalCatalogue::KIND_CONDITION)) : [],
            'fieldConfig' => array_map(fn (array $field) => $field['state'], $this->fieldConfigService->effective($clinic, request()->user())),
            'specialties' => $clinic ? $this->specialtyOptions($clinic) : [],
            'channels' => PatientProfile::CHANNELS,
            'consentMethods' => ConsentRecord::METHODS,
        ];
    }

    /**
     * The clinic's active specialties (`clinic_specialties`, never the legacy JSON) with their labels.
     *
     * @return list<array{key: string, label: string}>
     */
    private function specialtyOptions(Clinic $clinic): array
    {
        return collect($this->clinicSpecialties->activeKeys($clinic))
            ->map(fn (string $key) => ['key' => $key, 'label' => $this->specialtyRegistry->get($key)['label'] ?? $key])
            ->values()->all();
    }

    /**
     * Real-time duplicate scan endpoint (checks phone, email, name+DOB).
     */
    public function checkDuplicate(Request $request): JsonResponse
    {
        $clinic = $this->clinicContext->clinic();
        if (! $clinic) {
            return response()->json(['has_duplicates' => false, 'matches' => []]);
        }

        $criteria = [
            'phone' => $request->input('phone'),
            'alternate_phone' => $request->input('alternate_phone'),
            'email' => $request->input('email'),
            'first_name' => $request->input('first_name'),
            'last_name' => $request->input('last_name'),
            'date_of_birth' => $request->input('date_of_birth'),
        ];

        $matches = $this->duplicateDetector->detectDuplicates($clinic, $criteria);

        return response()->json([
            'has_duplicates' => $matches->isNotEmpty(),
            'count' => $matches->count(),
            // The same privacy-safe shape as the registration gate: enough to
            // recognise the patient, never their full phone or email.
            'matches' => $this->duplicateDetector->present($matches),
        ]);
    }

    /**
     * Store a newly registered patient.
     */
    public function store(RegisterPatientRequest $request): RedirectResponse|JsonResponse
    {
        $this->authorize('create', Patient::class);

        $clinic = $this->clinicContext->clinic();
        if (! $clinic) {
            abort(403, 'No active clinic selected.');
        }

        // Both forms send a request key made when they opened: a repeated
        // submit returns the first registration instead of making a second.
        $key = $request->validated('idempotency_key');
        if ($key && $request->expectsJson()) {
            return app(IntakeSubmissions::class)->once($clinic, $request->user(), $key, 'register', fn () => $this->register($request, $clinic));
        }
        if ($key) {
            $registered = null;

            return app(IntakeSubmissions::class)->onceRedirect(
                $clinic, $request->user(), $key, 'register',
                function () use ($request, $clinic, &$registered) {
                    return $this->register($request, $clinic, $registered);
                },
                function () use (&$registered): bool {
                    return $registered !== null;
                },
            );
        }

        return $this->register($request, $clinic);
    }

    /**
     * Register under the clinic's registration lock: the duplicate check and
     * the insert run inside one transaction that first locks this clinic's
     * row (`SELECT … FOR UPDATE`), so two registrations at the same clinic —
     * from any application server — take turns, and the second sees the
     * first's patient in its duplicate check. Other clinics are not held up.
     */
    private function register(RegisterPatientRequest $request, Clinic $clinic, ?Patient &$registered = null): RedirectResponse|JsonResponse
    {
        return DB::transaction(function () use ($request, $clinic, &$registered) {
            Clinic::query()->withoutGlobalScopes()->whereKey($clinic->id)->lockForUpdate()->first(['id']);

            return $this->registerLocked($request, $clinic, $registered);
        });
    }

    private function registerLocked(RegisterPatientRequest $request, Clinic $clinic, ?Patient &$registered): RedirectResponse|JsonResponse
    {
        // Possible existing patients block registration on the server, not
        // just as a banner on the form. Registering anyway is allowed only by
        // acknowledging every match the user was shown, with a reason; it is audited.
        $gate = $this->registrar->gate($clinic, $request->duplicateCriteria(), $request->validated('duplicate_acknowledged_ids') ?? [], $request->validated('duplicate_reason'));
        if ($gate && $gate['reason'] === 'duplicates') {
            return $this->possibleDuplicates($request, $gate['matches']);
        }
        if ($gate && $gate['reason'] === 'reason_required') {
            $message = 'Say why this is a different person (for example, "twin sharing the family phone").';
            if ($request->expectsJson()) {
                return response()->json(['message' => $message, 'errors' => ['duplicate_reason' => [$message]], 'possible_duplicates' => $this->duplicateDetector->present($gate['matches'])], 422);
            }

            return back()->withInput()->withErrors(['duplicate_reason' => $message])->with('possible_duplicates', $this->duplicateDetector->present($gate['matches']));
        }

        $specialtyKeys = array_values($request->validated('specialties') ?? []);
        $branch = $this->clinicContext->branch();

        $checkIn = null;
        if ($request->boolean('check_in')) {
            abort_unless($request->user()->hasClinicPermission(ClinicPermission::QueueManage), 403, 'Checking patients in needs the queue permission.');
            // The same guard as Reception check-in: only a branch this member may open.
            $branch = $this->clinicContext->requireBranch();
            // A booking made for someone not yet registered: today's, here, unclaimed.
            $appointment = $request->validated('appointment_id') ? $this->prospectBooking($clinic, $branch, (int) $request->validated('appointment_id')) : null;
            // The chosen doctor decides the clinical context; the specialty the
            // form sent only picks among that doctor's own, and is refused if
            // the doctor does not practise it.
            $context = $this->contextResolver->resolve($clinic, $branch, [
                'practitioner' => $request->validated('doctor_id'),
                'appointment' => $appointment,
                'user' => $request->user(),
                'specialty' => $specialtyKeys[0] ?? null,
                'area' => $request->validated('visit_area'),
                'require' => true,
                'field' => 'doctor_id',
            ]);
            // The form was filled in under one module's field settings (its first specialty);
            // the visit must be for that module, or the settings applied were the wrong ones.
            $formModule = $request->primarySpecialty();
            if ($formModule !== null && $context->specialtyKey !== null && $formModule !== $context->specialtyKey) {
                throw ValidationException::withMessages(['specialties' => 'This visit is for '.($this->specialtyRegistry->get($context->specialtyKey)['label'] ?? $context->specialtyKey).', but the form was filled in for '.($this->specialtyRegistry->get($formModule)['label'] ?? $formModule).'. Check what the visit is for and submit again.']);
            }
            if ($context->clinicSpecialtyId !== null && ! in_array($context->specialtyKey, $specialtyKeys, true)) {
                array_unshift($specialtyKeys, $context->specialtyKey);
            }
            $visit = VisitContext::fromResolved($clinic, $context, [
                'visit_type' => $request->validated('visit_type'),
                'visit_reason' => $request->validated('visit_reason'),
                'visit_reason_key' => $request->validated('visit_reason_key'),
            ]);
            $checkIn = [
                'doctor' => $context->practitioner,
                'priority' => $this->priority($clinic, $request->validated('priority')),
                'performer' => $this->clinicContext->currentEmployee(),
                'context' => $visit,
                'appointment' => $appointment,
            ];
        }
        // Medical history, allergies, medications, past surgery and family history: only from a member who may record them.
        abort_if(array_filter($request->clinicalInput()) !== [] && ! $this->privacy->canRecordAtRegistration($request->user()), 403, 'You cannot record clinical details at registration.');
        $clinical = $this->registrar->prepareClinical($clinic, $request->clinicalInput(), $specialtyKeys);
        [$alertFlags, $allergyStatus] = $this->quickCareFlags($clinic, $request, $clinical);

        // One transaction: the patient, the visit and (when the clinic charges at
        // check-in) the consultation charge and payment stand or fall together.
        [$result, $charge] = DB::transaction(function () use ($request, $clinic, $branch, $specialtyKeys, $clinical, $gate, $checkIn, $alertFlags, $allergyStatus) {
            $result = $this->registrar->register(
                clinic: $clinic,
                branch: $branch,
                by: $request->user(),
                patientData: $request->patientData(),
                profileData: $request->profileData(),
                visitData: $request->visitData(),
                specialtyKeys: $specialtyKeys,
                clinical: $clinical,
                manualAlerts: array_values($request->validated('medical_alerts') ?? []),
                communicationConsent: (bool) $request->validated('communication_consent'),
                consentMethod: $request->validated('consent_capture_method'),
                overriddenMatchIds: $gate ? $gate['matches']->pluck('id')->all() : [],
                overrideReason: $request->validated('duplicate_reason'),
                checkIn: $checkIn,
                alertFlags: $alertFlags,
                allergyStatus: $allergyStatus,
            );
            $charge = $result['queue_item'] ? app(CheckInCharges::class)->chargeVisit(
                $clinic, $branch, $result['patient'], $result['queue_item']->patientVisit, $result['queue_item']->doctor, $request->user(), $this->clinicContext->currentEmployee(), null,
                // The amount the desk showed, checked against the server's own (sent by New Case; absent from older callers).
                verify: $request->has('expected_charge'),
                expected: $request->validated('expected_charge') !== null ? (float) $request->validated('expected_charge') : null,
            ) : null;

            return [$result, $charge];
        });
        $patient = $result['patient'];
        $queueItem = $result['queue_item'];
        $registered = $patient;

        if ($request->expectsJson()) {
            return response()->json(app(IntakeOutcome::class)->present(
                $clinic, $request->user(), $patient, $queueItem, $context ?? null, $visit ?? null,
                $queueItem ? "{$patient->short_name} registered and checked in." : "{$patient->short_name} registered.",
                app(CheckInCharges::class)->present($charge, $request->user()),
            ), 201);
        }

        return redirect()
            ->to($this->afterRegistration($patient))
            ->with('success', "Patient {$patient->full_name} registered successfully. UHID: {$patient->uhid}");
    }

    /** Where the full page goes after registering: the profile once that page is built, the directory until then. */
    private function afterRegistration(Patient $patient): string
    {
        return ClinicFrontend::available('clinic.patients.show')
            ? route('clinic.patients.show', $patient->id)
            : route('clinic.patients.index');
    }

    private function prospectBooking(Clinic $clinic, Branch $branch, int $appointmentId): Appointment
    {
        return app(ReceptionCheckIn::class)->prospectBooking($clinic, $branch, $appointmentId);
    }

    private function priority(Clinic $clinic, ?string $value): VisitPriority
    {
        return app(PatientFlowConfigService::class)->resolvePriority($clinic, $value);
    }

    /**
     * Quick Care's flags: the operational alerts chosen (from the clinic's
     * catalogue, only when it offers alerts and the member may record
     * clinical facts) and the allergy status ("no known allergies" cannot sit
     * beside recorded allergies; a required status needs one or the other).
     *
     * @param  array<string, list<array<string, mixed>>>  $clinical
     * @return array{0: list<array{label: string, severity: string}>, 1: ?string}
     */
    private function quickCareFlags(Clinic $clinic, RegisterPatientRequest $request, array $clinical): array
    {
        $settings = app(PatientFlowConfigService::class)->quickCare($clinic);
        $canRecord = $this->privacy->canRecordAtRegistration($request->user());
        $alertKeys = array_values($request->validated('alert_keys') ?? []);
        $status = $request->validated('allergy_status');

        if (($alertKeys !== [] || $status !== null) && ! $canRecord) {
            abort(403, 'You cannot record clinical details at registration.');
        }
        if ($alertKeys !== [] && (! $settings['alerts'] || ! $this->fieldConfigService->roleAllowed($request->user(), $settings['alert_roles']))) {
            throw ValidationException::withMessages(['alert_keys' => 'Alerts are not recorded at registration here.']);
        }
        $alerts = collect($this->catalogue->resolveKeys($clinic->id, DefaultClinicalCatalogue::KIND_ALERT, $alertKeys, 'alert_keys'))
            ->map(fn (array $item) => ['label' => $item['name'], 'severity' => $item['severity'] ?? 'moderate'])->values()->all();

        $hasAllergies = isset($clinical[DefaultClinicalCatalogue::KIND_ALLERGY]);
        if ($status !== null && $settings['allergy_status'] === PatientFieldConfigService::HIDDEN) {
            $status = null;
        }
        if ($status === 'none_known' && $hasAllergies) {
            throw ValidationException::withMessages(['allergy_status' => 'Remove the allergies, or untick "No known allergies".']);
        }
        if ($canRecord && $settings['allergy_status'] === PatientFieldConfigService::REQUIRED && ! $hasAllergies && $status === null) {
            throw ValidationException::withMessages(['allergy_status' => 'Record the allergies, or confirm there are no known allergies.']);
        }

        return [$alerts, $status];
    }

    /**
     * The "possible existing patient" answer: 409 with the matches for a JSON
     * caller; back to the form with an error and the matches otherwise.
     *
     * @param  Collection<int, Patient>  $matches
     */
    private function possibleDuplicates(RegisterPatientRequest $request, Collection $matches): RedirectResponse|JsonResponse
    {
        $presented = $this->duplicateDetector->present($matches);
        $message = 'Possible existing patient found. Open the existing record, or confirm this is a different person to continue.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'possible_duplicates' => $presented], 409);
        }

        return back()->withInput()->withErrors(['duplicate' => $message])->with('possible_duplicates', $presented);
    }

    /**
     * Update basic patient demographic info — same field-config enforcement
     * as registration, via `UpdatePatientRequest`.
     */
    public function update(UpdatePatientRequest $request, Patient $patient): RedirectResponse
    {
        $this->authorize('update', $patient);

        // Demographics are the desk's; the clinical record after registration
        // is clinical staff's. Sending clinical fields without the right is refused.
        $validated = $request->validated();
        // Only clinical lists that survived validation count: a hidden or role-restricted list is dropped, never cleared.
        $sendsClinical = array_key_exists('allergies', $validated) || array_key_exists('medical_alerts', $validated);
        abort_if($sendsClinical && ! $this->privacy->canEdit($request->user()), 403, 'Changing allergies or alerts needs clinical permission.');

        $clinic = $this->clinicContext->clinic();

        // Only what was sent and allowed (`UpdatePatientRequest`): a field the
        // form left out, hid or may not edit keeps its stored value.
        // `name` (the maintained full name) is rebuilt by the model.
        $patient->update(collect($validated)->only([
            'first_name', 'middle_name', 'last_name', 'phone', 'alternate_phone', 'email', 'gender', 'date_of_birth', 'age', 'blood_group',
        ])->all());

        // What actually changed, not just "something did" — the fields are
        // what makes this a usable medico-legal record rather than a bare
        // timestamp. `updated_at` always appears in getChanges() even when
        // nothing else did, so it's excluded from the audit trail below.
        $changedFields = array_keys(collect($patient->getChanges())->except('updated_at')->all());

        // The same for the profile: sent fields only; an explicit empty value clears, an omitted one is untouched.
        $profileData = collect($validated)->only([
            'address', 'locality', 'city', 'state', 'pincode', 'country', 'emergency_contact_name', 'emergency_contact_phone', 'preferred_channel',
        ])->all();

        if (! empty($profileData)) {
            $profile = $patient->profile()->updateOrCreate(['patient_id' => $patient->id], $profileData + ['organization_id' => $clinic->organization_id]);
            if ($profile->wasRecentlyCreated || $profile->wasChanged()) {
                $changedFields[] = 'profile';
            }
        }

        // Allergies and manual alerts are full-state inputs on the edit form
        // (an emptied list means "clear these"). Allergies are clinical
        // entries: removed ones are resolved, never deleted.
        if ($sendsClinical) {
            $this->clinicalEntries->adoptLegacy($patient, DefaultClinicalCatalogue::KIND_ALLERGY);
            $allergies = $this->clinicalEntries->prepare(
                $clinic->id,
                DefaultClinicalCatalogue::KIND_ALLERGY,
                collect($validated['allergies'] ?? [])->map(fn (string $name) => ['label' => $name])->all(),
                'allergies',
            );
            if ($this->clinicalEntries->replace($patient, DefaultClinicalCatalogue::KIND_ALLERGY, $allergies, 'clinical', $request->user())) {
                $changedFields[] = 'allergies';
            }

            if ($this->medicalHistoryService->syncManualAlerts($patient, array_values(array_filter($validated['medical_alerts'] ?? [])))) {
                $changedFields[] = 'medical_alerts';
            }
        }

        if (! empty($changedFields)) {
            PatientTimelineEvent::create([
                'organization_id' => $clinic->organization_id,
                'clinic_id' => $patient->clinic_id,
                'patient_id' => $patient->id,
                'actor_id' => auth()->id(),
                'event_type' => 'demographics_updated',
                'context' => [
                    'updated_fields' => $changedFields,
                    'updated_by' => auth()->user()?->name ?? 'Staff',
                ],
            ]);
        }

        return redirect()->route('clinic.patients.show', $patient->id)->with('success', 'Patient demographic details updated successfully.');
    }

    /**
     * Reassign a patient to a different practitioner — Reception/Admin
     * only (the existing `PatientEdit` permission already maps to exactly
     * that set, not Practitioner), writing both the live pointer and an
     * audit row so "who was this patient assigned to, and when" stays
     * answerable.
     */
    public function transfer(Request $request, Patient $patient): RedirectResponse
    {
        $this->authorize('update', $patient);

        $clinic = $this->clinicContext->clinic();
        if (! $clinic || $patient->clinic_id !== $clinic->id) {
            abort(403, 'Patient does not belong to the active clinic context.');
        }

        $validated = $request->validate([
            'to_practitioner_id' => [
                'required',
                'integer',
                Rule::exists('clinic_employees', 'id')->where('clinic_id', $clinic->id),
                // A patient's doctor is someone who practises here, not any member of staff.
                function (string $attribute, mixed $value, \Closure $fail) use ($clinic) {
                    $practises = ClinicEmployee::withoutGlobalScopes()->where('clinic_id', $clinic->id)->whereKey((int) $value)
                        ->whereHas('practitionerProfile', fn ($profile) => $profile->withoutGlobalScopes())->exists();
                    if (! $practises) {
                        $fail('Choose one of the clinic’s doctors.');
                    }
                },
            ],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        if ((int) $validated['to_practitioner_id'] === $patient->current_practitioner_id) {
            return back()->with('success', 'Patient is already assigned to that doctor.');
        }

        $fromPractitionerId = $patient->current_practitioner_id;

        DB::transaction(function () use ($patient, $validated, $clinic, $fromPractitionerId) {
            $patient->update(['current_practitioner_id' => $validated['to_practitioner_id']]);

            PatientDoctorAssignment::create([
                'patient_id' => $patient->id,
                'clinic_id' => $clinic->id,
                'from_practitioner_id' => $fromPractitionerId,
                'to_practitioner_id' => $validated['to_practitioner_id'],
                'assigned_by' => auth()->id(),
                'reason' => $validated['reason'] ?? null,
            ]);

            PatientTimelineEvent::create([
                'organization_id' => $clinic->organization_id,
                'clinic_id' => $patient->clinic_id,
                'patient_id' => $patient->id,
                'actor_id' => auth()->id(),
                'event_type' => 'doctor_transferred',
                'context' => [
                    'from_practitioner' => $fromPractitionerId ? ClinicEmployee::withoutGlobalScopes()->find($fromPractitionerId)?->name : null,
                    'to_practitioner' => ClinicEmployee::withoutGlobalScopes()->find($validated['to_practitioner_id'])?->name,
                    'reason' => $validated['reason'] ?? null,
                    'transferred_by' => auth()->user()?->name ?? 'Staff',
                ],
            ]);
        });

        return back()->with('success', 'Patient reassigned successfully.');
    }

    /**
     * Instant search API for fast autocomplete in modals.
     */
    public function search(Request $request): JsonResponse
    {
        $clinic = $this->clinicContext->clinic();
        if (! $clinic) {
            return response()->json([]);
        }

        // Any status: the command palette and the calendar may open a merged record too (it leads to its survivor).
        $patients = app(PatientSearch::class)
            ->find($clinic, (string) $request->input('q', ''), 10, workingOnly: false, columns: ['id', 'uhid', 'name', 'first_name', 'middle_name', 'last_name', 'phone', 'gender', 'age', 'date_of_birth'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'uhid' => $p->uhid,
                'name' => $p->full_name,
                'phone' => $p->phone,
                'gender' => $p->gender,
                'age' => $p->age,
                'url' => route('clinic.patients.show', $p->id, false),
            ]);

        return response()->json($patients);
    }
}
