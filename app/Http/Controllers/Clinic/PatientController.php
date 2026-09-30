<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Patient\RegisterPatientRequest;
use App\Http\Requests\Patient\UpdatePatientRequest;
use App\Models\AllergyMaster;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientDoctorAssignment;
use App\Models\PatientTimelineEvent;
use App\Services\ClinicContext;
use App\Services\MedicalHistoryService;
use App\Services\Patient\PatientRegistrationService;
use App\Services\PatientDuplicateDetectionService;
use App\Services\PatientFieldConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PatientController extends Controller
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected PatientDuplicateDetectionService $duplicateDetector,
        protected PatientRegistrationService $registrationService,
        protected MedicalHistoryService $medicalHistoryService,
        protected PatientFieldConfigService $fieldConfigService
    ) {}

    /**
     * Display the searchable, paginated Patient Directory.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Patient::class);

        $clinic = $this->clinicContext->clinic();
        if (! $clinic) {
            abort(403, 'No active clinic selected.');
        }

        $search = trim((string) $request->input('q', ''));
        $status = $request->input('status');
        $gender = $request->input('gender');

        // "My Patients" only makes sense for someone who has patients
        // assigned to them at all — a practitioner. Everyone else (Reception,
        // Admin, Billing, Staff) sees only "All Patients", identical to
        // this page's behaviour before assignment existed.
        $employee = $this->clinicContext->currentEmployee();
        $isPractitioner = $employee?->practitionerProfile !== null;
        $tab = $isPractitioner && $request->input('tab') !== 'all' ? 'mine' : 'all';

        $query = Patient::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->with(['profile', 'visits' => fn ($q) => $q->latest()->limit(1)])
            ->withCount(['alerts as critical_alert_count' => fn ($q) => $q->whereIn('severity', ['critical', 'high'])])
            ->when($search !== '', fn ($q) => $q->matchingSearch($search))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($gender, fn ($q) => $q->where('gender', $gender))
            ->when($tab === 'mine', fn ($q) => $q->assignedTo($employee->id))
            ->orderBy('created_at', 'desc');

        $patients = $query->paginate(15)->through(function (Patient $patient) {
            $lastVisit = $patient->visits->first();

            return [
                'id' => $patient->id,
                'uhid' => $patient->uhid ?? '—',
                'name' => $patient->full_name,
                'first_name' => $patient->first_name,
                'last_name' => $patient->last_name,
                'phone' => $patient->phone,
                'email' => $patient->email,
                'age' => $patient->age,
                'gender' => $patient->gender,
                'blood_group' => $patient->blood_group,
                'status' => $patient->status?->value ?? 'active',
                'created_at' => $patient->created_at?->format('d M Y'),
                'last_visit_date' => $lastVisit?->visit_date?->format('d M Y') ?? 'Never',
                'last_visit_is_recent' => $lastVisit?->visit_date?->greaterThanOrEqualTo(now()->subDays(30)) ?? false,
                'city' => $patient->profile?->city ?? '—',
                'has_critical_alert' => $patient->critical_alert_count > 0,
            ];
        });

        // Summary counts
        $totalCount = Patient::withoutGlobalScopes()->where('clinic_id', $clinic->id)->count();
        $registeredTodayCount = Patient::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->whereDate('created_at', today())
            ->count();
        $myPatientsCount = $isPractitioner
            ? Patient::withoutGlobalScopes()->where('clinic_id', $clinic->id)->assignedTo($employee->id)->count()
            : 0;

        return Inertia::render('Clinic/Patients/Index', [
            'patients' => $patients,
            'filters' => [
                'q' => $search,
                'status' => $status,
                'gender' => $gender,
                'tab' => $tab,
            ],
            'isPractitioner' => $isPractitioner,
            'metrics' => [
                'total' => $totalCount,
                'registeredToday' => $registeredTodayCount,
                'mine' => $myPatientsCount,
            ],
            // For the "Add patient" quick-add modal — it opens over this
            // page rather than navigating to `create()`, so it needs the
            // same picker data that page would otherwise have fetched.
            'practitioners' => ClinicEmployee::activePractitioners($clinic->id),
            'allergyCatalogue' => AllergyMaster::catalogue(),
            'fieldConfig' => $this->fieldConfigService->resolve($clinic),
        ]);
    }

    /**
     * Show the quick patient registration form.
     */
    public function create(): Response
    {
        $this->authorize('create', Patient::class);

        $clinic = $this->clinicContext->clinic();

        return Inertia::render('Clinic/Patients/Create', [
            'practitioners' => $clinic ? ClinicEmployee::activePractitioners($clinic->id) : [],
            'allergyCatalogue' => AllergyMaster::catalogue(),
            'fieldConfig' => $this->fieldConfigService->resolve($clinic),
        ]);
    }

    /**
     * Show the edit form — the same `PatientFields` component Create uses,
     * pre-filled, with the same field-config enforcement.
     */
    public function edit(Patient $patient): Response
    {
        $this->authorize('update', $patient);

        $clinic = $this->clinicContext->clinic();
        if (! $clinic || $patient->clinic_id !== $clinic->id) {
            abort(403, 'Patient does not belong to the active clinic context.');
        }

        $patient->load(['profile', 'medicalHistory', 'alerts', 'currentPractitioner']);

        return Inertia::render('Clinic/Patients/Edit', [
            'patient' => [
                'id' => $patient->id,
                'uhid' => $patient->uhid ?? '—',
                'first_name' => $patient->first_name ?? '',
                'last_name' => $patient->last_name ?? '',
                'gender' => $patient->gender ?? '',
                'date_of_birth' => $patient->date_of_birth?->format('Y-m-d') ?? '',
                'age' => $patient->age !== null ? (string) $patient->age : '',
                'blood_group' => $patient->blood_group ?? '',
                'phone' => $patient->phone ?? '',
                'alternate_phone' => $patient->alternate_phone ?? '',
                'email' => $patient->email ?? '',
                'address' => $patient->profile?->address ?? '',
                'city' => $patient->profile?->city ?? '',
                'state' => $patient->profile?->state ?? '',
                'pincode' => $patient->profile?->pincode ?? '',
                'emergency_contact_name' => $patient->profile?->emergency_contact_name ?? '',
                'emergency_contact_phone' => $patient->profile?->emergency_contact_phone ?? '',
                'allergies' => $patient->medicalHistory?->allergies ?? [],
                'medical_alerts' => $patient->alerts->where('type', 'manual')->pluck('description')->values()->all(),
                'current_practitioner_name' => $patient->currentPractitioner?->name,
            ],
            'practitioners' => ClinicEmployee::activePractitioners($clinic->id),
            'allergyCatalogue' => AllergyMaster::catalogue(),
            'fieldConfig' => $this->fieldConfigService->resolve($clinic),
        ]);
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
            'email' => $request->input('email'),
            'name' => trim(($request->input('first_name', '')).' '.($request->input('last_name', ''))),
            'date_of_birth' => $request->input('date_of_birth'),
        ];

        // Filter out empty search parameters
        if (empty($criteria['phone']) && empty($criteria['email']) && (empty($criteria['name']) || empty($criteria['date_of_birth']))) {
            return response()->json(['has_duplicates' => false, 'matches' => []]);
        }

        $matches = $this->duplicateDetector->detectDuplicates($clinic, $criteria);

        return response()->json([
            'has_duplicates' => $matches->isNotEmpty(),
            'count' => $matches->count(),
            'matches' => $matches->map(fn (Patient $p) => [
                'id' => $p->id,
                'uhid' => $p->uhid,
                'name' => $p->full_name,
                'phone' => $p->phone,
                'email' => $p->email,
                'gender' => $p->gender,
                'age' => $p->age,
                'date_of_birth' => $p->date_of_birth?->format('Y-m-d'),
                'created_at' => $p->created_at?->format('d M Y'),
            ]),
        ]);
    }

    /**
     * Store a newly registered patient.
     */
    public function store(RegisterPatientRequest $request): RedirectResponse
    {
        $this->authorize('create', Patient::class);

        $clinic = $this->clinicContext->clinic();
        if (! $clinic) {
            abort(403, 'No active clinic selected.');
        }

        $doctorId = $request->validated('doctor_id');

        if ($doctorId) {
            // Assigning a doctor at registration means this patient also
            // needs a first visit — `patients` itself has no doctor
            // reference of its own, an assignment only exists as a
            // `PatientVisit.doctor_id` row (see `Reception\PatientController`
            // for the only other caller of this path).
            $branch = $this->clinicContext->branch();
            if (! $branch) {
                abort(403, 'No active branch selected.');
            }

            $visit = $this->registrationService->registerNewPatient(
                patientData: $request->patientData(),
                visitData: $request->visitData(),
                clinic: $clinic,
                branch: $branch
            );
            $patient = $visit->patient;

            $profileData = array_filter($request->profileData());
            if (! empty($profileData)) {
                $patient->profile()->create($profileData + ['organization_id' => $clinic->organization_id]);
            }
        } else {
            $patient = $this->registrationService->createPatientOnly(
                patientData: $request->patientData(),
                profileData: $request->profileData(),
                clinic: $clinic
            );
        }

        // Allergies go through the medical-history service, which already
        // knows how to turn them into the same `PatientAlert` rows the
        // directory's "Alert" badge and Show.tsx's coral chips read —
        // registering an allergy here is indistinguishable downstream from
        // one recorded later on the clinical side.
        $allergies = array_values(array_filter($request->input('allergies', [])));
        if (! empty($allergies)) {
            $this->medicalHistoryService->updateMedicalHistory($patient, [
                'organization_id' => $clinic->organization_id,
                'allergies' => $allergies,
            ]);
        }

        // Free-text safety alerts ("Fall risk", "Pacemaker") aren't derived
        // from a structured medical-history field the service already
        // knows how to convert, so they're written directly as the same
        // shape `MedicalHistoryService` uses for an allergy.
        foreach (array_values(array_filter($request->input('medical_alerts', []))) as $alertText) {
            $patient->alerts()->create([
                'type' => 'manual',
                'description' => $alertText,
                'severity' => 'high',
            ]);
        }

        return redirect()
            ->route('clinic.patients.show', $patient->id)
            ->with('success', "Patient {$patient->full_name} registered successfully. UHID: {$patient->uhid}");
    }

    /**
     * Update basic patient demographic info — same field-config enforcement
     * as registration, via `UpdatePatientRequest`.
     */
    public function update(UpdatePatientRequest $request, Patient $patient): RedirectResponse
    {
        $this->authorize('update', $patient);

        $validated = $request->validated();
        $clinic = $this->clinicContext->clinic();

        $patient->update([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'] ?? null,
            'name' => trim("{$validated['first_name']} ".($validated['last_name'] ?? '')),
            'phone' => $validated['phone'],
            'alternate_phone' => $validated['alternate_phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'gender' => $validated['gender'] ?? null,
            'date_of_birth' => $validated['date_of_birth'] ?? null,
            'age' => $validated['age'] ?? null,
            'blood_group' => $validated['blood_group'] ?? null,
        ]);

        // What actually changed, not just "something did" — the fields are
        // what makes this a usable medico-legal record rather than a bare
        // timestamp. `updated_at` always appears in getChanges() even when
        // nothing else did, so it's excluded from the audit trail below.
        $changedFields = array_keys(collect($patient->getChanges())->except('updated_at')->all());

        $profileData = array_filter([
            'address' => $validated['address'] ?? null,
            'city' => $validated['city'] ?? null,
            'state' => $validated['state'] ?? null,
            'pincode' => $validated['pincode'] ?? null,
            'emergency_contact_name' => $validated['emergency_contact_name'] ?? null,
            'emergency_contact_phone' => $validated['emergency_contact_phone'] ?? null,
        ]);

        if (! empty($profileData)) {
            $profile = $patient->profile()->updateOrCreate(['patient_id' => $patient->id], $profileData + ['organization_id' => $clinic->organization_id]);
            if ($profile->wasRecentlyCreated || $profile->wasChanged()) {
                $changedFields[] = 'profile';
            }
        }

        // Allergies and manual alerts are full-state controlled inputs on
        // the edit form (an emptied `AllergyPicker`/`TagChipInput` means
        // "clear these"), so both are always synced rather than only
        // touched when non-empty — unlike `store()`, where there is never
        // a prior value to clear. `updateMedicalHistory()` wipes and
        // rebuilds every alert from the history record, so the "before"
        // snapshots below are taken first, and the manual alerts are
        // re-added after it runs, exactly mirroring `store()`'s order.
        $allergiesChanged = ($patient->medicalHistory?->allergies ?? []) !== ($validated['allergies'] ?? []);
        $previousManualAlerts = $patient->alerts()->where('type', 'manual')->pluck('description')->sort()->values()->all();
        $nextManualAlerts = array_values(array_filter($validated['medical_alerts'] ?? []));

        $this->medicalHistoryService->updateMedicalHistory($patient, [
            'organization_id' => $clinic->organization_id,
            'allergies' => $validated['allergies'] ?? [],
        ]);
        if ($allergiesChanged) {
            $changedFields[] = 'allergies';
        }

        foreach ($nextManualAlerts as $alertText) {
            $patient->alerts()->create([
                'type' => 'manual',
                'description' => $alertText,
                'severity' => 'high',
            ]);
        }
        if ($previousManualAlerts !== collect($nextManualAlerts)->sort()->values()->all()) {
            $changedFields[] = 'medical_alerts';
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

        $query = trim((string) $request->input('q', ''));
        if (strlen($query) < 2) {
            return response()->json([]);
        }

        $patients = Patient::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->matchingSearch($query)
            ->limit(10)
            ->get(['id', 'uhid', 'name', 'first_name', 'last_name', 'phone', 'gender', 'age'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'uhid' => $p->uhid,
                'name' => $p->full_name,
                'phone' => $p->phone,
                'gender' => $p->gender,
                'age' => $p->age,
            ]);

        return response()->json($patients);
    }
}
