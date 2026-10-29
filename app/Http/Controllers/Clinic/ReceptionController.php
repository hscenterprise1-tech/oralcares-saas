<?php

namespace App\Http\Controllers\Clinic;

use App\ClinicPermission;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueueItem;
use App\Models\Patient;
use App\Models\PatientTreatment;
use App\PatientStatus;
use App\PaymentMethod;
use App\Services\Billing\CheckInCharges;
use App\Services\Billing\EncounterCheckout;
use App\Services\Clinical\ClinicalCatalogue;
use App\Services\Clinical\ClinicalContextResolver;
use App\Services\Clinical\ClinicalPrivacy;
use App\Services\ClinicContext;
use App\Services\Patient\CaseNumberFormatter;
use App\Services\Patient\ClinicSpecialties;
use App\Services\Patient\Patient360\Patient360Access;
use App\Services\Patient\PatientBranchAccess;
use App\Services\PatientFieldConfigService;
use App\Services\PaymentService;
use App\Services\Physio\PhysioCareSummary;
use App\Services\Physio\PhysioRecord;
use App\Services\QueueStateMachine;
use App\Services\Reception\DoctorQueue;
use App\Services\Reception\FrontDesk;
use App\Services\Reception\IntakeOutcome;
use App\Services\Reception\IntakeSubmissions;
use App\Services\Reception\PatientFlowConfigService;
use App\Services\Reception\QueueCorrections;
use App\Services\Reception\ReceptionCheckIn;
use App\Services\Reception\ReceptionPatientLookup;
use App\Services\Reception\VisitContext;
use App\Support\ClinicAccess\ClinicAccess;
use App\Support\Clinical\DefaultClinicalCatalogue;
use App\Support\Specialty\PracticeAreas;
use App\Support\Specialty\SpecialtyRegistry;
use App\VisitPriority;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The Front Desk (Reception's workspace). Every route sits behind `clinic_permission:queue.manage`
 * and every queue item is checked to belong to the active clinic and branch
 * (another clinic's item is a 404, not a 403). All status changes go through
 * `QueueStateMachine`.
 */
class ReceptionController extends Controller
{
    /** What Reception may do to a queue entry (the doctor owns taking in and finishing). */
    private const RECEPTION_ACTIONS = ['call', 'recall', 'skip', 'requeue', 'cancel'];

    /** The actions that are queue control (see `queueControl`). */
    private const QUEUE_ACTIONS = ['call', 'recall', 'skip', 'requeue'];

    /** The older status-style requests, for the same actions. */
    private const STATUS_ACTIONS = [
        'called' => 'call',
        'skipped' => 'skip',
        'waiting' => 'requeue',
        'cancelled' => 'cancel',
    ];

    public function __construct(
        protected ClinicContext $clinicContext,
        protected QueueStateMachine $machine,
    ) {}

    /**
     * The Reception Control Center: the front office's home for the day. The
     * polled part (`desk`) and the money part (`billing`) are bounded
     * previews; `follow_ups` changes slowly and follows the page in (deferred). Every
     * section is composed from what this person may do (never from the name
     * of their role), so a doctor who also covers the desk sees the same
     * page. `intake` opens New Case or Repeat Case straight away when a quick
     * action or a search result asked for it.
     */
    public function index(Request $request, FrontDesk $desk, ClinicAccess $access, Patient360Access $patientAccess): Response
    {
        [$clinic, $branch] = $this->context();
        $user = $request->user();
        $intake = in_array($request->query('intake'), ['new', 'repeat'], true) ? $request->query('intake') : null;
        $canBook = $access->allows(['permissions' => [ClinicPermission::AppointmentCreate->value], 'module' => 'appointments', 'route' => 'clinic.appointments.index']);
        $followUps = $patientAccess->canManageFollowUps($user) && $access->allows(['module' => 'patients', 'route' => 'clinic.follow-ups.index']);

        return Inertia::render('ClinicPortal/FrontDesk/Index', [
            'branch' => ['id' => $branch->id, 'name' => $branch->display_name ?: $branch->name],
            'desk' => fn () => $desk->desk($clinic, $branch, $user),
            'billing' => fn () => $desk->billing($clinic, $branch, $user),
            // Slow-moving and below the fold: it follows the page in, behind a skeleton.
            'follow_ups' => $followUps ? Inertia::defer(fn () => $desk->followUps($clinic, $branch)) : null,
            'intake' => $intake ? ['mode' => $intake, 'query' => mb_substr(trim((string) $request->query('q', '')), 0, 60)] : null,
            'can' => [
                'register' => $user->hasClinicPermission(ClinicPermission::PatientCreate),
                'appointments' => $user->hasClinicPermission(ClinicPermission::AppointmentManage),
                'book' => $canBook,
                'queue' => $this->queueControl($access),
            ],
            'links' => [
                'lists' => collect(array_keys(FrontDesk::LISTS))->mapWithKeys(fn (string $list) => [$list => route('clinic.reception.list', $list)])->all(),
                'intake' => route('clinic.reception.intake'),
                'book' => $canBook ? route('clinic.appointments.index', ['book' => 1]) : null,
                // "View all" opens the Queue page (with the module), else the plain list.
                'queue' => $this->queueControl($access) ? route('clinic.queue') : null,
                'appointments' => $access->allows(['permissions' => [ClinicPermission::AppointmentView->value], 'module' => 'appointments', 'route' => 'clinic.appointments.index'])
                    ? route('clinic.appointments.index') : null,
            ],
        ]);
    }

    /**
     * Calling patients in order is the Queue module's: on for the clinic and
     * held by this person. Without it patients still check in and wait; the
     * doctor takes them in from Today.
     */
    private function queueControl(ClinicAccess $access): bool
    {
        return $access->allows(['clinic_module' => 'queue', 'permissions' => [ClinicPermission::QueueManage->value]]);
    }

    /** One Front Desk list in full (today, this branch), a cursor page at a time. */
    public function list(Request $request, FrontDesk $desk, ClinicAccess $access, string $list): Response
    {
        [$clinic, $branch] = $this->context();
        abort_unless(array_key_exists($list, FrontDesk::LISTS), 404);

        return Inertia::render('ClinicPortal/FrontDesk/List', [
            'branch' => ['id' => $branch->id, 'name' => $branch->display_name ?: $branch->name],
            'list' => $list,
            'page' => $desk->page($clinic, $branch, $request->user(), $list, $request->query('cursor')),
            'can' => ['queue' => $this->queueControl($access)],
            'links' => ['back' => route('clinic.reception'), 'self' => route('clinic.reception.list', $list)],
        ]);
    }

    /**
     * The Queue: today's patients at this branch by stage, one cursor-paged
     * list at a time, with a tab per stage. Opened from the top navigation
     * only with the Queue module (the route says so as well).
     */
    public function queue(Request $request, FrontDesk $desk, ClinicAccess $access): Response
    {
        [$clinic, $branch] = $this->context();
        $list = in_array($request->query('list'), array_keys(FrontDesk::LISTS), true) ? $request->query('list') : 'waiting';
        $counts = $desk->stageCounts($clinic, $branch);

        return Inertia::render('ClinicPortal/FrontDesk/List', [
            'branch' => ['id' => $branch->id, 'name' => $branch->display_name ?: $branch->name],
            'list' => $list,
            'page' => $desk->page($clinic, $branch, $request->user(), $list, $request->query('cursor')),
            'can' => ['queue' => $this->queueControl($access)],
            'tabs' => collect(array_keys(FrontDesk::LISTS))->map(fn (string $key) => [
                'key' => $key, 'count' => $counts[$key], 'href' => route('clinic.queue', ['list' => $key]),
            ])->values()->all(),
            'links' => ['back' => route('clinic.reception'), 'self' => route('clinic.queue', ['list' => $list])],
        ]);
    }

    /** Give a waiting patient to another doctor (before the consultation starts). */
    public function reassign(ClinicQueueItem $item, Request $request, QueueCorrections $corrections): RedirectResponse
    {
        [$clinic, $branch] = $this->context();
        $this->assertOwned($item, $clinic, $branch);
        $doctorId = (int) $request->validate(['practitioner_id' => ['required', 'integer']])['practitioner_id'];
        $doctor = ClinicEmployee::withoutGlobalScopes()->where('clinic_id', $clinic->id)->findOrFail($doctorId);

        return $this->correct(fn () => $corrections->reassign($clinic, $branch, $item, $doctor, $this->clinicContext->currentEmployee()),
            fn ($updated) => "{$updated->patientVisit?->patient?->short_name} will now see {$doctor->name}.");
    }

    /** Correct why the patient came (specialty, visit type, reason) before the consultation starts. */
    public function correctVisit(ClinicQueueItem $item, Request $request, QueueCorrections $corrections): RedirectResponse
    {
        [$clinic, $branch] = $this->context();
        $this->assertOwned($item, $clinic, $branch);
        $validated = $request->validate([
            'visit_specialty' => ['nullable', 'string', 'max:64'],
            'visit_type' => ['nullable', 'string', 'max:64'],
            'visit_reason' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->correct(fn () => $corrections->correctVisit($clinic, $item, $validated, $this->clinicContext->currentEmployee()),
            fn () => 'Visit details updated.');
    }

    /**
     * Raise the bill for a signed consultation at the clinic's prices and take
     * the payment, in one step at the desk. Discounts and price changes stay
     * on the full checkout.
     *
     * The bill is raised under the consultation's row lock with its lines
     * worked out again inside it (`EncounterCheckout::bill`), so a second
     * collection finds nothing left to bill. A request key made when the
     * desk opened the collection also answers a repeat with the first
     * result instead of an error.
     */
    public function collect(ClinicalEncounter $encounter, Request $request, EncounterCheckout $checkout, PaymentService $payments, IntakeSubmissions $submissions): RedirectResponse
    {
        [$clinic, $branch] = $this->context();
        abort_unless($encounter->clinic_id === $clinic->id && $encounter->branch_id === $branch->id, 404);
        abort_unless($request->user()->hasClinicPermission(ClinicPermission::BillingCreate), 403, 'You cannot raise bills.');
        $validated = $request->validate([
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['nullable', 'string', 'min:8', 'max:80'],
        ]);
        $employee = $this->clinicContext->currentEmployee();
        if (! $employee) {
            return back()->withErrors(['billing' => 'Your login has no staff record at this clinic, so it cannot raise bills. Ask the administrator to add you under Staff.']);
        }

        // By reference: the key is kept only when the payment was recorded.
        $collected = false;
        $take = function () use ($encounter, $checkout, $payments, $validated, $employee, &$collected): RedirectResponse {
            try {
                $payment = DB::transaction(function () use ($encounter, $checkout, $payments, $validated, $employee) {
                    $lines = $checkout->lines($encounter);
                    if ($lines === []) {
                        throw new DomainException('This consultation has nothing left to bill.');
                    }
                    $bill = $checkout->bill($encounter, collect($lines)->mapWithKeys(fn (array $line) => [$line['key'] => $line['unit_price']])->all(), $employee);

                    return $payments->recordPayment($bill->fresh(), PaymentMethod::from($validated['method']), (float) $validated['amount'], $validated['reference'] ?? null, $employee);
                });
            } catch (DomainException|InvalidArgumentException $e) {
                return back()->withErrors(['billing' => $e->getMessage()]);
            }
            $collected = true;

            return back()->with('success', "Payment received (receipt {$payment->receipt_number}).");
        };

        $key = $validated['idempotency_key'] ?? null;
        if ($key === null) {
            $submissions->withoutKey($clinic, 'reception.collect');

            return $take();
        }

        return $submissions->onceRedirect($clinic, $request->user(), $key, "collect:{$encounter->id}", $take, function () use (&$collected): bool {
            return $collected;
        }, 'This payment was already recorded; it was not taken twice.', atomic: true);
    }

    /**
     * Repeat Case search: matching patients (clinic-wide identity search,
     * at most eight) with today's booking at this branch, whether they are
     * already queued, who they would be sent to, and the safety flags the
     * member may see. No query, or under three characters: nothing.
     */
    /**
     * A patient's physiotherapy at a glance, for Repeat Case: open
     * treatments, sessions done, package balances, what they owe. Read-only;
     * null when the clinic does not practise physiotherapy.
     */
    public function physioSummary(Request $request, Patient $patient, PhysioCareSummary $summary): JsonResponse
    {
        [$clinic, $branch] = $this->context();
        abort_unless($patient->clinic_id === $clinic->id, 404);
        $this->authorize('view', $patient);

        return response()->json(['physio' => $summary->forPatient($patient, $request->user(), $branch)]);
    }

    /**
     * Confirm at the desk that the queued patient's physiotherapy session took
     * place, with the therapist they are queued with (who need not sign in),
     * and which of that treatment's packages paid for it — or none. Needs
     * `physio.session.confirm` (route) and an explicit confirmation; writes no
     * clinical notes and signs nothing (`PhysioRecord::confirmAtDesk`).
     */
    public function confirmPhysioSession(Request $request, ClinicQueueItem $item, PhysioRecord $physio, IntakeSubmissions $submissions): JsonResponse
    {
        [$clinic, $branch] = $this->context();
        $this->assertOwned($item, $clinic, $branch);
        $data = $request->validate([
            'patient_treatment_id' => ['required', 'integer'],
            'package_id' => ['nullable', 'integer'],
            'confirm' => ['accepted'],
            'idempotency_key' => ['nullable', 'string', 'min:8', 'max:80'],
        ]);
        $treatment = PatientTreatment::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->findOrFail($data['patient_treatment_id']);
        $confirmer = $this->clinicContext->currentEmployee() ?? abort(403, 'Only clinic staff can confirm sessions.');

        $confirm = function () use ($item, $treatment, $data, $confirmer, $request, $physio): JsonResponse {
            try {
                $session = $physio->confirmAtDesk($item, $treatment, isset($data['package_id']) ? (int) $data['package_id'] : null, $confirmer, $request->user());
            } catch (DomainException|InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage(), 'errors' => ['session' => [$e->getMessage()]]], 422);
            }

            return response()->json([
                'message' => "Session {$session->session_number} of {$treatment->title} confirmed.",
                'session' => ['id' => $session->id, 'number' => $session->session_number, 'package_id' => $session->package_id, 'completed_at' => $session->completed_at?->toIso8601String()],
            ], 201);
        };

        return isset($data['idempotency_key'])
            ? $submissions->once($clinic, $request->user(), $data['idempotency_key'], "pss:{$item->id}", $confirm)
            : $confirm();
    }

    public function search(Request $request, ReceptionPatientLookup $lookup): JsonResponse
    {
        [$clinic, $branch] = $this->context();
        $query = trim((string) $request->input('q', ''));

        if (mb_strlen($query) < 3) {
            return response()->json(['patients' => []]);
        }

        return response()->json(['patients' => $lookup->search($clinic, $branch, $query)]);
    }

    /**
     * New Case's booking check: today's open bookings here for someone not
     * yet registered, made with the mobile number being typed. Only what the
     * desk needs to recognise the booking; matching is exact, so a booking is
     * never attached on a guess.
     */
    public function bookingMatches(Request $request, ReceptionCheckIn $checkIn): JsonResponse
    {
        [$clinic, $branch] = $this->context();
        $phone = (string) $request->validate(['phone' => ['required', 'string', 'max:20']])['phone'];
        $areas = app(PracticeAreas::class);
        $bookings = $checkIn->prospectBookingsFor($clinic, $branch, $phone);
        $doctors = ClinicEmployee::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->whereIn('id', $bookings->pluck('practitioner_id')->filter())
            ->with(['practitionerProfile' => fn ($q) => $q->withoutGlobalScopes()])->get()->keyBy('id');

        return response()->json(['bookings' => $bookings->map(fn (Appointment $booking) => [
            'id' => $booking->id,
            'time' => $booking->scheduled_at?->format('H:i'),
            'name' => $booking->prospect_name,
            'doctor' => ($doctor = $doctors->get($booking->practitioner_id)) ? ['id' => $doctor->id, 'name' => $doctor->name, 'practice' => $areas->contextLabel($doctor->practitionerProfile?->primaryPracticeArea())] : null,
        ])->values()]);
    }

    /** Patients seen at this branch lately and not already queued today: a short, bounded list. */
    public function recent(ReceptionPatientLookup $lookup): JsonResponse
    {
        [$clinic, $branch] = $this->context();

        return response()->json(['patients' => $lookup->recent($clinic, $branch)]);
    }

    /**
     * Everything the New Case and Repeat Case dialogs need, in one payload:
     * the registration fields this member sees in New Case, the clinic's
     * specialties, today's doctors at this branch, the enabled conditions,
     * allergies and visit types (each with the specialties it applies to),
     * and the check-in settings. Read from cached configuration; no patient
     * data.
     */
    public function intake(Request $request, ClinicalContextResolver $resolver, ClinicalCatalogue $catalogue, PatientFieldConfigService $fields, PatientFlowConfigService $flow, ClinicSpecialties $clinicSpecialties, SpecialtyRegistry $registry, ClinicalPrivacy $privacy, ReceptionCheckIn $checkIn): JsonResponse
    {
        [$clinic, $branch] = $this->context();
        $user = $request->user();

        $item = fn (array $entry) => collect($entry)->only(['key', 'name', 'category', 'severity', 'specialty_keys', 'practice_area_keys', 'required'])->all();
        $definitions = $fields->effective($clinic, $user, null, PatientFieldConfigService::SURFACE_NEW_CASE);
        $fieldSpecialties = collect($fields->definitions($clinic))->map(fn (array $definition) => $definition['specialties']);
        $checkInSettings = $flow->checkIn($clinic);
        $quickCare = $flow->quickCare($clinic);
        $canRecord = $privacy->canRecordAtRegistration($user);

        // A prospect booking New Case was opened for (validated here and again on saving).
        $booking = $request->filled('appointment') ? $checkIn->prospectBooking($clinic, $branch, (int) $request->query('appointment')) : null;

        return response()->json([
            'branch' => ['id' => $branch->id, 'name' => $branch->display_name ?: $branch->name],
            'fields' => collect($definitions)->map(fn (array $field, string $name) => [...$field, 'specialties' => $fieldSpecialties[$name] ?? null])->all(),
            // What a visit can be for when no practitioner is chosen: what this
            // branch practises (within the clinic's active, entitled set).
            'specialties' => collect($resolver->practised($clinic, $branch) ?? $clinicSpecialties->activeKeys($clinic))
                ->map(fn (string $key) => ['key' => $key, 'label' => $registry->get($key)['label'] ?? $key])->values()->all(),
            // Today's practitioners, each with what choosing them resolves to.
            'doctors' => collect($resolver->practitionerOptions($clinic, $branch))
                // A fee that check-in will charge is always shown, whatever the display setting: no charge is a surprise.
                ->map(fn (array $doctor) => $checkInSettings['show_fee'] || ($checkInSettings['payment'] === PatientFlowConfigService::PAY_AT_CHECK_IN && $user->hasClinicPermission(ClinicPermission::BillingCreate)) ? $doctor : [...$doctor, 'fee' => null])->all(),
            'catalogue' => [
                'conditions' => array_map($item, $catalogue->effective($clinic->id, DefaultClinicalCatalogue::KIND_CONDITION)),
                'allergies' => array_map($item, $catalogue->effective($clinic->id, DefaultClinicalCatalogue::KIND_ALLERGY)),
                'medications' => array_map($item, $catalogue->effective($clinic->id, DefaultClinicalCatalogue::KIND_MEDICATION)),
                'surgical_history' => array_map($item, $catalogue->effective($clinic->id, DefaultClinicalCatalogue::KIND_SURGERY)),
                'family_history' => array_map($item, $catalogue->effective($clinic->id, DefaultClinicalCatalogue::KIND_FAMILY_HISTORY)),
                'visit_types' => array_map($item, $catalogue->effective($clinic->id, DefaultClinicalCatalogue::KIND_VISIT_TYPE)),
                // Each with the specialties and practice areas it is offered for; the server checks the choice again.
                'visit_reasons' => array_map($item, $catalogue->effective($clinic->id, DefaultClinicalCatalogue::KIND_VISIT_REASON)),
                'alerts' => $quickCare['alerts'] && $canRecord && $fields->roleAllowed($user, $quickCare['alert_roles']) ? array_map($item, $catalogue->effective($clinic->id, DefaultClinicalCatalogue::KIND_ALERT)) : [],
            ],
            'quick_care' => [
                'allergy_status' => $quickCare['allergy_status'],
            ],
            'check_in' => [
                ...$checkInSettings,
                'queue' => $flow->queueShown($clinic),
                // Modules that never charge at check-in (Physiotherapy bills its sessions and packages at their own stage).
                'no_charge_modules' => collect(config('clinic_specialties.modules', []))->filter(fn (array $module) => ($module['charges_at_check_in'] ?? true) === false)->keys()->values()->all(),
            ],
            // Paying at check-in: the methods this clinic accepts, and whether this member may take money.
            'payment' => [
                'methods' => app(PaymentService::class)->methodOptions($clinic, atDesk: true),
                'can_charge' => $user->hasClinicPermission(ClinicPermission::BillingCreate),
                'can_collect' => $user->hasClinicPermission(ClinicPermission::PaymentCollect),
            ],
            'priorities' => array_map(fn (VisitPriority $priority) => ['value' => $priority->value, 'label' => $priority->label(), 'rank' => $priority->rank()], $flow->priorities($clinic)),
            'booking' => $booking ? [
                'id' => $booking->id,
                'time' => $booking->scheduled_at?->format('H:i'),
                'doctor_id' => $booking->practitioner_id,
                'name' => $booking->prospect_name,
                'phone' => $booking->prospect_phone,
            ] : null,
            'can' => [
                'register' => $user->hasClinicPermission(ClinicPermission::PatientCreate),
                'record_clinical' => $canRecord,
            ],
        ]);
    }

    /**
     * Check a patient in at this branch: an appointment, or a walk-in from
     * Repeat Case with today's specialty, visit type and reason (validated
     * by `VisitContext`). Nothing about the patient themselves is asked again.
     */
    public function checkIn(Request $request, ReceptionCheckIn $checkIn, ClinicalContextResolver $resolver): RedirectResponse|JsonResponse
    {
        [$clinic] = $this->context();
        $key = $request->expectsJson() ? $request->validate(['idempotency_key' => ['nullable', 'string', 'min:8', 'max:80']])['idempotency_key'] ?? null : null;

        // The desk sends a request key: a repeated submit returns the first check-in.
        return $key !== null
            ? app(IntakeSubmissions::class)->once($clinic, $request->user(), $key, 'check_in', fn () => $this->performCheckIn($request, $checkIn, $resolver))
            : $this->performCheckIn($request, $checkIn, $resolver);
    }

    private function performCheckIn(Request $request, ReceptionCheckIn $checkIn, ClinicalContextResolver $resolver): RedirectResponse|JsonResponse
    {
        [$clinic, $branch] = $this->context();

        $validated = $request->validate([
            'appointment_id' => ['nullable', 'integer'],
            'patient_id' => ['required_without:appointment_id', 'nullable', 'integer'],
            'practitioner_id' => ['nullable', 'integer'],
            'priority' => ['nullable', 'string', 'max:30'],
            'visit_specialty' => ['nullable', 'string', 'max:64'],
            'visit_area' => ['nullable', 'string', 'max:60'],
            'visit_type' => ['nullable', 'string', 'max:64'],
            'visit_reason' => ['nullable', 'string', 'max:255'],
            'visit_reason_key' => ['nullable', 'string', 'max:64'],
            // Vitals are no longer taken at the desk: anything sent under that name is dropped.
            'vitals' => ['exclude'],
            // Money is never taken at check-in: the check-in stands on its own, and a charge it raised is
            // paid afterwards through the billing payment endpoint. A payment sent here is dropped.
            'payment' => ['exclude'],
            'expected_charge' => ['nullable', 'numeric', 'min:0'],
        ]);

        $appointment = isset($validated['appointment_id'])
            ? Appointment::withoutGlobalScopes()->where('clinic_id', $clinic->id)->findOrFail($validated['appointment_id'])
            : null;

        $patient = Patient::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->findOrFail($appointment?->patient_id ?? $validated['patient_id']);
        abort_unless(in_array($patient->status?->value ?? PatientStatus::Active->value, PatientStatus::working(), true), 422, 'Merged records cannot be checked in.');
        // A member limited to some branches checks in only patients of those
        // branches, or the patient of a booking made at this one; a masked
        // duplicate match from another branch is not a way in.
        abort_unless(
            app(PatientBranchAccess::class)->allows($patient) || ($appointment && (int) $appointment->branch_id === (int) $branch->id),
            403,
            'This patient is registered at a branch you cannot open.',
        );

        // The practitioner decides the clinical context; a specialty is only
        // taken from the request when no practitioner is known.
        $context = $resolver->resolve($clinic, $branch, [
            'practitioner' => $validated['practitioner_id'] ?? null,
            'appointment' => $appointment,
            'patient' => $patient,
            'user' => $request->user(),
            'specialty' => $validated['visit_specialty'] ?? null,
            'area' => $validated['visit_area'] ?? null,
            'require' => true,
        ]);
        $visit = VisitContext::fromResolved($clinic, $context, $validated, true, $request->user()->id);

        // The check-in and (when the clinic charges then) the consultation charge and payment stand or fall together.
        [$item, $charge] = DB::transaction(function () use ($clinic, $branch, $patient, $appointment, $context, $validated, $visit, $checkIn, $request) {
            $item = $checkIn->checkIn(
                $clinic,
                $branch,
                $patient,
                $appointment,
                $context->practitioner,
                app(PatientFlowConfigService::class)->resolvePriority($clinic, $validated['priority'] ?? null),
                $this->clinicContext->currentEmployee(),
                $visit,
            );

            return [$item, app(CheckInCharges::class)->chargeVisit(
                $clinic, $branch, $patient, $item->patientVisit, $item->doctor, $request->user(), $this->clinicContext->currentEmployee(), null,
                // The amount the desk showed, checked against the server's own (sent by Repeat Case; absent from older callers).
                verify: $request->has('expected_charge'),
                expected: isset($validated['expected_charge']) ? (float) $validated['expected_charge'] : null,
            )];
        });

        $caseShort = $item->patientVisit?->caseNumber ? ' · '.CaseNumberFormatter::shortLabel($item->patientVisit->caseNumber->sequence) : '';
        $doctorName = $item->doctor?->name ?? 'the next free doctor';
        $message = "{$patient->short_name}{$caseShort} checked in for {$doctorName}.";

        if ($request->expectsJson()) {
            return response()->json(app(IntakeOutcome::class)->present($clinic, $request->user(), $patient, $item, $context, $visit, $message, app(CheckInCharges::class)->present($charge, $request->user())), 201);
        }

        return back()->with('success', $message);
    }

    /**
     * Kept for the older walk-in form: a walk-in is a check-in without an
     * appointment.
     */
    public function walkIn(Request $request, ReceptionCheckIn $checkIn, ClinicalContextResolver $resolver): RedirectResponse|JsonResponse
    {
        $request->request->remove('appointment_id');

        return $this->checkIn($request, $checkIn, $resolver);
    }

    /**
     * Reception's queue actions: call, call again, not present, back to the
     * queue, left. Taking a patient in and finishing the visit are the
     * doctor's (Start visit opens the consultation; signing it ends the visit),
     * so the queue and the consultation can never disagree.
     */
    public function updateQueueStatus(ClinicQueueItem $item, Request $request): RedirectResponse
    {
        [$clinic, $branch] = $this->context();
        $this->assertOwned($item, $clinic, $branch);

        $validated = $request->validate([
            'action' => ['nullable', Rule::in(self::RECEPTION_ACTIONS)],
            'status' => ['required_without:action', 'nullable', Rule::in(array_keys(self::STATUS_ACTIONS))],
        ], [
            'action.in' => 'The doctor takes the patient in from Today and finishes the visit by signing it.',
            'status.in' => 'The doctor takes the patient in from Today and finishes the visit by signing it.',
        ]);

        $action = $validated['action'] ?? self::STATUS_ACTIONS[$validated['status']];
        // Calling and its corrections are queue control; a patient leaving is not.
        abort_if(in_array($action, self::QUEUE_ACTIONS, true) && ! $this->queueControl(app(ClinicAccess::class)), 403, 'Calling patients needs the Queue module.');
        $item = $this->machine->{$action}($item, $this->clinicContext->currentEmployee());

        return back()->with('success', $this->confirmation($action, $item));
    }

    public function updatePriority(ClinicQueueItem $item, Request $request): RedirectResponse
    {
        [$clinic, $branch] = $this->context();
        $this->assertOwned($item, $clinic, $branch);

        $validated = $request->validate(['priority' => ['required', Rule::enum(VisitPriority::class)]]);
        $item = $this->machine->setPriority($item, VisitPriority::from($validated['priority']), $this->clinicContext->currentEmployee());

        $name = $item->patientVisit?->patient?->short_name ?? 'Patient';

        return back()->with('success', $item->priority === VisitPriority::Emergency
            ? "{$name} marked as an emergency."
            : "{$name}'s priority is now ".mb_strtolower($item->priority->label()).'.');
    }

    public function callNext(DoctorQueue $doctorQueue): RedirectResponse
    {
        [$clinic, $branch] = $this->context();
        $doctor = $this->clinicContext->currentEmployee();
        abort_unless($doctor?->practitionerProfile, 403, 'Only a doctor can call their next patient.');

        $item = $doctorQueue->callNext($clinic, $branch, $doctor);

        return back()->with('success', "{$item->patientVisit?->patient?->short_name} has been called.");
    }

    /**
     * Run a correction, answering a refusal (the doctor got there first, the
     * wrong doctor) as a message on the page.
     *
     * @param  \Closure(): mixed  $action
     * @param  \Closure(mixed): string  $message
     */
    private function correct(\Closure $action, \Closure $message): RedirectResponse
    {
        try {
            $result = $action();
        } catch (DomainException|InvalidArgumentException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('success', $message($result));
    }

    /**
     * @return array{0: Clinic, 1: Branch}
     */
    private function context(): array
    {
        $clinic = $this->clinicContext->clinic() ?? abort(403, 'No active clinic selected.');
        // Only a branch this member may open: never the clinic's main branch
        // on behalf of someone with no branch access.
        $branch = $this->clinicContext->requireBranch();

        return [$clinic, $branch];
    }

    private function assertOwned(ClinicQueueItem $item, Clinic $clinic, Branch $branch): void
    {
        $queue = $item->queue()->withoutGlobalScopes()->first();

        abort_unless($queue && $queue->clinic_id === $clinic->id && $queue->branch_id === $branch->id, 404);
    }

    private function confirmation(string $action, ClinicQueueItem $item): string
    {
        $name = $item->patientVisit?->patient?->short_name ?? 'Patient';

        return match ($action) {
            'call' => "{$name} has been called.",
            'recall' => "{$name} has been called again.",
            'start' => "{$name} is in consultation.",
            'complete' => "{$name} is done.",
            'skip' => "{$name} marked as not present.",
            'requeue' => "{$name} is back in the queue.",
            'cancel' => "{$name} has left the queue.",
            default => 'Queue updated.',
        };
    }
}
