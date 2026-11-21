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
use App\Models\Branch;
use App\Models\Clinic;
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
use App\Models\PatientTreatment;
use App\Models\PatientVisit;
use App\Models\PhysioAssessment;
use App\Models\PhysioCarePlan;
use App\Models\PhysioExercise;
use App\Models\PhysioSession;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\TreatmentPlan;
use App\Models\TreatmentRecord;
use App\Models\User;
use App\QueueItemStatus;
use App\Services\Clinical\ClinicalCatalogue;
use App\Services\Clinical\DoctorToday;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\Clinical\PrescriptionAllergyCheck;
use App\Services\Clinical\StaleEncounterException;
use App\Services\ClinicContext;
use App\Services\Dental\DentalConsultation;
use App\Services\Dental\DentalRecord;
use App\Services\Dental\DeskDental;
use App\Services\Patient\CrossBranchClinicalAccess;
use App\Services\Patient\Patient360\Patient360Access;
use App\Services\Patient\PatientClinicalEntries;
use App\Services\Physio\DeskPhysio;
use App\Services\Physio\PhysioCareSummary;
use App\Services\Physio\PhysioConsultation;
use App\Services\Physio\PhysioRecord;
use App\Services\Physio\SessionPackages;
use App\Services\Reception\FrontDesk;
use App\Services\Security\SecurityAudit;
use App\Services\Treatment\ConsultationCare;
use App\Support\ClinicAccess\ClinicAccess;
use App\Support\Clinical\DefaultClinicalCatalogue;
use App\Support\ClinicClock;
use App\Support\ClinicFrontend;
use App\Support\ClinicNavigation;
use App\Support\Specialty\SpecialtyRegistry;
use App\VisitStatus;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Closure;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
    public function index(Request $request, DoctorToday $today): Response
    {
        $this->authorize('viewAny', ClinicalEncounter::class);
        $clinic = $this->clinicContext->clinic() ?? abort(403);
        $branch = $this->clinicContext->requireBranch();
        $me = $this->clinicContext->currentEmployee();
        $isDoctor = $me?->practitionerProfile !== null;
        // The appointments panel's day (`?date=`), within a year either side of today; the queue is always today's.
        $clinicToday = CarbonImmutable::parse(ClinicClock::today()->toDateString(), ClinicClock::timezone());
        $requested = is_string($request->query('date')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->query('date')) ? CarbonImmutable::createFromFormat('!Y-m-d', $request->query('date'), ClinicClock::timezone()) : null;
        $day = $requested && abs($requested->diffInDays($clinicToday)) <= 366 ? $requested : $clinicToday;
        $calendar = $isDoctor && ClinicFrontend::available('clinic.appointments.index') && app(ClinicAccess::class)->allows(ClinicNavigation::CALENDAR);

        return Inertia::render('ClinicPortal/Today/Index', [
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'is_doctor' => $isDoctor,
            'doctor' => $isDoctor ? ['name' => $me->name, 'practice' => $me->practitionerProfile?->specialty] : null,
            'today' => fn () => $today->build($clinic, $branch, $isDoctor ? $me : null),
            // The doctor's booked day for the panel beside the queue; the day changes without reloading the queue.
            'schedule' => $calendar ? [
                'date' => $day->toDateString(),
                'today' => $clinicToday->toDateString(),
                'appointments' => $today->appointments($clinic, $branch, $me, $day),
            ] : null,
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
            // Results back for the orders they placed, waiting for their review.
            'results' => $isDoctor ? fn () => $today->resultsToReview($clinic, request()->user()) : null,
            'encounter_label' => $this->specialties->encounterLabel($this->specialties->forBranch($branch, $clinic)),
            'links' => [
                'self' => route('clinic.consultations.index'),
                'call_next' => $isDoctor && app(ClinicAccess::class)->moduleEnabled('queue') ? route('clinic.queue.call-next') : null,
                'calendar' => $calendar ? route('clinic.appointments.index', ['view' => 'day', 'date' => $day->toDateString()]) : null,
                // Linked only once their page is built (`ClinicFrontend::AVAILABLE`).
                'follow_ups' => ClinicFrontend::available('clinic.follow-ups.index') ? route('clinic.follow-ups.index') : null,
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
        $branch = $this->clinicContext->requireBranch();

        // A patient checked in here today is seen from their queue entry, so the
        // consultation, today's visit (and its check-in charge) and the queue stay one.
        $queued = $this->todaysQueueEntry($patient, $branch);
        if ($queued !== null) {
            $doctor = $this->doctor();
            if ($queued->doctor_id !== null && $queued->doctor_id !== $doctor->id) {
                return back()->withErrors(['consultation' => 'This patient is waiting for another doctor today. Ask the front desk to reassign them first.']);
            }

            return $this->attempt(fn () => redirect()->route('clinic.consultations.show', $this->workspace->startFromQueue($queued, $doctor, $validated['specialty'] ?? null)));
        }

        return $this->attempt(fn () => redirect()->route('clinic.consultations.show', $this->workspace->startForPatient(
            $patient,
            $branch,
            $this->doctor(),
            null,
            $validated['specialty'] ?? null,
        )));
    }

    /**
     * A physiotherapy visit: held in physiotherapy, or at a branch that
     * practises physiotherapy and no dentistry (a physio-only clinic).
     *
     * @param  list<string>  $branchSpecialties
     */
    private function isPhysioVisit(ClinicalEncounter $encounter, array $branchSpecialties): bool
    {
        return $encounter->specialty === PhysioCareSummary::SPECIALTY
            || ($encounter->specialty === null && in_array(PhysioCareSummary::SPECIALTY, $branchSpecialties, true) && ! in_array('dental', $branchSpecialties, true));
    }

    /**
     * A dental visit has its own consultation (`DentalConsultation`): the visit was started for dentistry, or it has no specialty
     * and the branch practises dentistry without physiotherapy.
     *
     * @param  list<string>  $branchSpecialties
     */
    private function isDentalVisit(ClinicalEncounter $encounter, array $branchSpecialties): bool
    {
        // The branch practises dentistry exactly when the odontogram extension is open to the user (the same gate the dental writes use).
        return request()->user()->can('viewExtension', [$encounter, 'odontogram'])
            && ($encounter->specialty === DentalConsultation::SPECIALTY
                || ($encounter->specialty === null && ! in_array(PhysioCareSummary::SPECIALTY, $branchSpecialties, true)));
    }

    /**
     * The dental workspace: complaint, tooth findings, plan, work done today, next visits (`DentalConsultation::payload`),
     * for the dentist or (`$desk`) the desk working for a dentist who has no login (`DeskDental`).
     */
    private function renderDental(ClinicalEncounter $encounter, ConsultationCare $care, bool $desk = false): Response
    {
        $user = request()->user();
        $patient = $encounter->patient;
        $me = $desk ? ClinicEmployee::query()->withoutGlobalScopes()->findOrFail($encounter->doctor_id) : $this->clinicContext->currentEmployee();
        $canEdit = $desk ? $encounter->isEditable() : ($user->can('update', $encounter) && $me?->practitionerProfile !== null && $user->can('writeExtension', [$encounter, 'odontogram']));
        $caseNumber = $encounter->patient_visit_id
            ? PatientVisit::query()->withoutGlobalScopes()->where('clinic_id', $encounter->clinic_id)->with('caseNumber')->find($encounter->patient_visit_id)?->caseNumber?->case_number
            : null;

        return Inertia::render('ClinicPortal/Consultations/Dental', [
            'encounter' => [
                'id' => $encounter->id, 'number' => $encounter->encounter_number, 'started_at' => $encounter->started_at?->toIso8601String(),
                'doctor' => $encounter->doctor?->name, 'branch' => $encounter->branch?->name, 'case_number' => $caseNumber,
            ],
            'patient' => [
                'id' => $patient->id, 'name' => $patient->full_name, 'uhid' => $patient->uhid, 'age' => $patient->age, 'gender' => $patient->gender,
            ],
            'visit' => $this->visitContext($encounter),
            'dental' => app(DentalConsultation::class)->payload($encounter, $user, $me),
            // The treatments running for the patient (packages, stages, discharge), as in the full record; partial reloads ask for `care` alone.
            'care' => fn () => $care->build($encounter, $patient, $user, $canEdit && ! $encounter->isFinalized()),
            'can_edit' => $canEdit,
            // The desk working for a dentist without a login: shown so, and the work is in the dentist's name.
            'desk' => $desk ? ['dentist' => $me->name] : null,
            'links' => [
                'finish' => route($desk ? 'clinic.desk-dental.finish' : 'clinic.consultations.dental.finish', $encounter),
                // The full record is the clinical team's; the desk has Patient 360 instead.
                'record' => $desk ? null : route('clinic.consultations.show', $encounter).'?record=1',
                'patient' => route('clinic.patients.show', $patient->id),
                // What the patient takes home: the visit summary, printable.
                'summary' => route($desk ? 'clinic.desk-dental.summary' : 'clinic.consultations.dental.summary', $encounter),
                'calendar' => route('clinic.appointments.index'),
                'today' => $desk ? route('clinic.reception') : route('clinic.consultations.index'),
                'checkout' => $user->hasClinicPermission(ClinicPermission::BillingCreate) ? route('clinic.billing.checkout', $encounter) : null,
            ],
        ]);
    }

    /** Finish a dental visit in one step: findings, plan, work done, signature, next visits. */
    public function finishDental(Request $request, ClinicalEncounter $encounter, DentalConsultation $consultation): RedirectResponse
    {
        $me = $this->dentalGate($encounter);
        $surfaces = Rule::in(array_keys(config('clinic_dental.surfaces')));
        $data = $request->validate([
            'consultation' => ['nullable', 'array'],
            'consultation.complaint' => ['nullable', 'string', 'max:2000'],
            'consultation.findings' => ['nullable', 'string', 'max:5000'],
            'consultation.diagnosis' => ['nullable', 'string', 'max:255'],
            'consultation.advice' => ['nullable', 'string', 'max:2000'],
            'findings' => ['nullable', 'array', 'max:40'],
            'findings.*.tooth' => ['required', 'integer', 'between:11,85'],
            'findings.*.condition' => ['required', Rule::in(array_keys(config('clinic_dental.conditions')))],
            'findings.*.surfaces' => ['nullable', 'array'],
            'findings.*.surfaces.*' => [$surfaces],
            'findings.*.note' => ['nullable', 'string', 'max:500'],
            'work' => ['nullable', 'array', 'max:40'],
            'work.*.id' => ['nullable', 'integer'],
            'work.*.procedure_id' => ['required_without:work.*.id', 'nullable', 'integer'],
            'work.*.tooth' => ['nullable', 'integer', 'between:11,85'],
            'work.*.surfaces' => ['nullable', 'array'],
            'work.*.surfaces.*' => [$surfaces],
            'work.*.notes' => ['nullable', 'string', 'max:255'],
            'work.*.done' => ['boolean'],
            'work.*.visits' => ['nullable', 'integer', 'between:1,8'],
            'work.*.sitting_note' => ['nullable', 'string', 'max:500'],
            'work.*.condition' => ['nullable', Rule::in(array_keys(config('clinic_dental.conditions')))],
            'booking' => ['nullable', 'array'],
            'booking.date' => ['nullable', 'date_format:Y-m-d'],
            'booking.time' => ['nullable', 'date_format:H:i'],
            'booking.step' => ['nullable', 'integer', 'min:1', 'max:60'],
            'booking.count' => ['nullable', 'integer', 'min:1', 'max:12'],
            'prescription' => ['nullable', 'array'],
            'prescription.items' => ['nullable', 'array', 'max:15'],
            'prescription.items.*.drug_name' => ['required', 'string', 'max:150'],
            'prescription.items.*.dosage' => ['required', 'string', 'max:60'],
            'prescription.items.*.frequency' => ['required', 'string', 'max:30'],
            'prescription.items.*.duration' => ['required', 'string', 'max:40'],
            'prescription.items.*.instructions' => ['nullable', 'string', 'max:255'],
            'prescription.allergy_fingerprint' => ['nullable', 'string', 'max:128'],
        ]);

        try {
            $result = $consultation->finish($encounter, $data, $me, $request->user());
        } catch (DomainException|InvalidArgumentException $e) {
            return back()->withErrors(['dental' => $e->getMessage()]);
        }

        $message = 'Visit saved'.($result['done'] > 0 ? ' · '.$result['done'].' '.($result['done'] === 1 ? 'treatment' : 'treatments').' done' : '')
            .($result['planned'] > 0 ? ' · '.$result['planned'].' planned' : '')
            .($result['booked'] > 0 ? ' · '.$result['booked'].' '.($result['booked'] === 1 ? 'visit' : 'visits').' booked' : '').'.';
        $response = back()->with('success', $message);

        return $result['booking_problem'] ? $response->withErrors(['booking' => $result['booking_problem']]) : $response;
    }

    /**
     * The dental visit summary the patient takes home (printable): why they came, what was found, what was done today,
     * the advice and the visits already booked. Read by the dentist's team or by the desk running the visit.
     */
    public function dentalSummary(ClinicalEncounter $encounter): HttpResponse
    {
        if (! app(DeskDental::class)->runs(request()->user(), $encounter)) {
            $this->authorize('view', $encounter);
        }
        $encounter->load(['patient' => fn ($query) => $query->withoutGlobalScopes(), 'doctor:id,name', 'branch', 'diagnoses' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('id')]);
        $patient = $encounter->patient;
        abort_unless($encounter->clinic_id === $this->clinicContext->clinic()?->id, 404);

        $next = Appointment::withoutGlobalScopes()->where('clinic_id', $encounter->clinic_id)->where('patient_id', $patient->id)
            ->where('scheduled_at', '>=', now())->whereIn('status', array_map(fn (AppointmentStatus $status) => $status->value, AppointmentStatus::checkable()))
            ->with('practitioner:id,name')->orderBy('scheduled_at')->limit(8)->get()
            ->map(fn ($appointment) => ['when' => ClinicClock::fromUtc($appointment->scheduled_at)->format('D, d M Y, g:i A'), 'with' => $appointment->practitioner?->name])->all();

        app(SecurityAudit::class)->record('dental.visit_summary_printed', $encounter, ['patient_id' => $patient->id]);

        return response()->view('clinic.dental_visit_summary_printable', [
            'clinic' => Clinic::query()->findOrFail($encounter->clinic_id),
            'branch' => $encounter->branch,
            'patient' => $patient,
            'encounter' => $encounter,
            'dentist' => $encounter->doctor?->name,
            'diagnoses' => $encounter->diagnoses->pluck('description')->filter()->values()->all(),
            'done' => $encounter->procedures()->withoutGlobalScopes()->orderBy('id')->get(['name', 'details'])
                ->map(fn ($procedure) => ['name' => $procedure->name, 'tooth' => $procedure->details['tooth_number'] ?? null])->all(),
            'next' => $next,
        ]);
    }

    /**
     * Who does the dental work on this visit, after checking they may: the treating dentist, or the desk working for a
     * dentist who has no login (`DeskDental`), in whose name the work is recorded.
     */
    private function dentalGate(ClinicalEncounter $encounter): ClinicEmployee
    {
        if (app(DeskDental::class)->runs(request()->user(), $encounter)) {
            abort_if(! $encounter->isEditable(), 403, 'This visit is finished.');

            return ClinicEmployee::query()->withoutGlobalScopes()->findOrFail($encounter->doctor_id);
        }
        $this->authorize('update', $encounter);
        $this->authorize('writeExtension', [$encounter, 'odontogram']);

        return $this->doctor();
    }

    /**
     * The desk opens today's dental visit of a checked-in patient for a dentist who has no login, and works it as the
     * dentist would (findings, work done, next visits, the bill).
     */
    public function deskStartDental(ClinicQueueItem $item): RedirectResponse
    {
        $clinic = $this->clinicContext->clinic() ?? abort(403);
        $branch = $this->clinicContext->requireBranch();
        $queue = $item->queue()->withoutGlobalScopes()->first();
        abort_unless($queue && $queue->clinic_id === $clinic->id && $queue->branch_id === $branch->id, 404);
        $doctor = $item->doctor_id ? ClinicEmployee::query()->withoutGlobalScopes()->find($item->doctor_id) : null;
        abort_unless(app(DeskDental::class)->mayRunFor(request()->user(), $doctor), 403, 'The desk opens the visit only for a dentist who has no login.');

        return $this->attempt(function () use ($item, $doctor) {
            $encounter = $this->workspace->startFromQueue($item, $doctor, DentalConsultation::SPECIALTY);
            if ($encounter->specialty === null) {
                $encounter->forceFill(['specialty' => DentalConsultation::SPECIALTY])->save();
            }

            return redirect()->route('clinic.desk-dental.show', $encounter);
        });
    }

    /** The dental workspace, run by the desk for a dentist without a login. */
    public function deskDental(ClinicalEncounter $encounter, ConsultationCare $care): Response
    {
        abort_unless(app(DeskDental::class)->runs(request()->user(), $encounter), 403);

        return $this->renderDental($encounter->load(['patient' => fn ($query) => $query->withoutGlobalScopes(), 'doctor:id,name', 'branch:id,name']), $care, true);
    }

    /**
     * Finish a physiotherapy visit (`PhysioConsultation::finish`): each
     * course's session, its payment, the signature, the next visit.
     */
    public function finishPhysio(Request $request, ClinicalEncounter $encounter, PhysioConsultation $consultation): RedirectResponse
    {
        $me = $this->physioGate($encounter, true);
        $data = $request->validate([
            // What the consultation found: a visit may be a consultation only, with no treatment given.
            'consultation' => ['nullable', 'array'],
            'consultation.complaint' => ['nullable', 'string', 'max:2000'],
            'consultation.history' => ['nullable', 'string', 'max:5000'],
            'consultation.findings' => ['nullable', 'string', 'max:5000'],
            'consultation.diagnosis' => ['nullable', 'string', 'max:255'],
            'consultation.advice' => ['nullable', 'string', 'max:2000'],
            // Each started course whose session was done today: its type (if that day was different), add-ons, who gave it.
            'courses' => ['present', 'array', 'max:8'],
            'courses.*.id' => ['required', 'integer'],
            'courses.*.done' => ['boolean'],
            'courses.*.type' => ['nullable', 'string', 'max:64'],
            'courses.*.given' => ['nullable', 'array', 'max:20'],
            'courses.*.given.*.key' => ['required', 'string', 'max:64'],
            'courses.*.given.*.minutes' => ['required', 'integer', 'min:1', 'max:180'],
            'courses.*.given.*.status' => ['nullable', 'in:'.implode(',', array_keys(PhysioConsultation::TREATMENT_STATUSES))],
            'courses.*.addons' => ['nullable', 'array', 'max:10'],
            'courses.*.addons.*' => ['string', 'max:64'],
            'courses.*.therapist_id' => ['nullable', 'integer'],
            'courses.*.feel' => ['nullable', 'in:better,same,worse'],
            'courses.*.note' => ['nullable', 'string', 'max:1000'],
            // The prescribed exercises as done today; those not sent were done as prescribed.
            'courses.*.exercises' => ['nullable', 'array', 'max:30'],
            'courses.*.exercises.*.id' => ['required', 'integer'],
            'courses.*.exercises.*.status' => ['required', 'in:performed,partial,skipped'],
            'courses.*.exercises.*.sets' => ['nullable', 'integer', 'min:0', 'max:50'],
            'courses.*.exercises.*.reps' => ['nullable', 'integer', 'min:0', 'max:500'],
            // The coming visits, booked by the frequency from the first day at one time.
            'booking' => ['nullable', 'array'],
            'booking.date' => ['nullable', 'date_format:Y-m-d'],
            'booking.time' => ['nullable', 'date_format:H:i'],
            'booking.frequency' => ['nullable', 'string', 'max:20'],
            'booking.count' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        try {
            $result = $consultation->finish($encounter, $data, $me, $request->user());
        } catch (DomainException|InvalidArgumentException $e) {
            return back()->withErrors(['physio' => $e->getMessage()]);
        }

        $message = $result['sessions'].' '.($result['sessions'] === 1 ? 'session' : 'sessions').' saved'
            .($result['booked'] > 0 ? ' · '.$result['booked'].' '.($result['booked'] === 1 ? 'visit' : 'visits').' booked' : '').'.';

        $response = back()->with('success', $message);

        return $result['booking_problem'] ? $response->withErrors(['booking' => $result['booking_problem']]) : $response;
    }

    /** A problem found in a physio consultation: a course suggested for it, on a side (`PhysioConsultation::suggest`). */
    public function suggestPhysio(Request $request, ClinicalEncounter $encounter, PhysioConsultation $consultation): RedirectResponse
    {
        $me = $this->physioGate($encounter, true);
        $data = $request->validate(['problem' => ['required', 'string', 'max:64'], 'side' => ['nullable', 'in:left,right,both']]);

        return $this->physioStep(fn () => $consultation->suggest($encounter, $data['problem'], $data['side'] ?? null, $me, $request->user()), 'Problem added.');
    }

    /**
     * A problem's whole plan in one step — found, assessed, planned and
     * (`start`) started — or a running treatment's plan changed
     * (`PhysioConsultation::plan`).
     */
    public function physioPlan(Request $request, ClinicalEncounter $encounter, PhysioConsultation $consultation): RedirectResponse
    {
        $me = $this->physioGate($encounter, true);
        $keys = fn (array $options) => 'in:'.implode(',', array_keys($options));
        $data = $request->validate([
            'course_id' => ['nullable', 'integer'],
            'problem' => ['required_without:course_id', 'nullable', 'string', 'max:64'],
            'side' => ['nullable', 'in:left,right,both'],
            'complaint' => ['sometimes', 'array'],
            'complaint.duration' => ['nullable', $keys(PhysioConsultation::DURATIONS)],
            'complaint.severity' => ['nullable', $keys(PhysioConsultation::SEVERITIES)],
            'complaint.difficulties' => ['nullable', 'array', 'max:8'],
            'complaint.difficulties.*' => [$keys(PhysioConsultation::DIFFICULTIES)],
            'complaint.previous' => ['nullable', 'array', 'max:5'],
            'complaint.previous.*' => [$keys(PhysioConsultation::PREVIOUS)],
            'complaint.description' => ['nullable', 'string', 'max:2000'],
            'complaint.remarks' => ['nullable', 'string', 'max:2000'],
            'diagnosis' => ['nullable', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', $keys(PhysioConsultation::CATEGORIES)],
            'goals' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'type' => ['nullable', 'string', 'max:64'],
            'frequency' => ['nullable', 'string', 'max:20'],
            'sessions' => ['nullable', 'integer', 'min:1', 'max:500'],
            'package' => ['nullable', 'string', 'max:20'],
            'start' => ['boolean'],
            // The exercises prescribed, from the clinic's library (the list as it should now be).
            'exercises' => ['sometimes', 'array', 'max:30'],
            'exercises.*.key' => ['required', 'string', 'max:64'],
            'exercises.*.sets' => ['nullable', 'integer', 'min:0', 'max:50'],
            'exercises.*.reps' => ['nullable', 'integer', 'min:0', 'max:500'],
            'exercises.*.hold' => ['nullable', 'integer', 'min:0', 'max:600'],
            'exercises.*.per_day' => ['nullable', 'integer', 'min:1', 'max:10'],
            'exercises.*.instructions' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->physioStep(fn () => $consultation->plan($encounter, $data, $me, $request->user()), ! empty($data['start']) ? 'Treatment started.' : 'Plan saved.');
    }

    /** A problem's progress: a goal reached, a progress note or review, the next review date (`PhysioConsultation::progress`). */
    public function physioProgress(Request $request, ClinicalEncounter $encounter, PatientTreatment $course, PhysioConsultation $consultation): RedirectResponse
    {
        $me = $this->physioGate($encounter, true);
        abort_unless($course->clinic_id === $encounter->clinic_id && $course->patient_id === $encounter->patient_id, 404);
        $data = $request->validate([
            'goal' => ['nullable', 'string', 'max:1000'],
            'done' => ['boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
            'review' => ['boolean'],
            'review_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ]);

        return $this->physioStep(fn () => $consultation->progress($encounter, $course, $data, $me), 'Progress saved.');
    }

    /**
     * One physiotherapy report of a problem, made only from what is recorded
     * (spec §22): the assessment and diagnosis, the treatment plan, a session
     * (the latest, or `?session=`), the progress, or the packages — to
     * preview and print, or (`?download=1`) as a PDF. The same record the
     * workspace shows (`PhysioConsultation::payload`), nothing typed into it.
     */
    public function physioReport(Request $request, ClinicalEncounter $encounter, PatientTreatment $course, string $kind): HttpResponse
    {
        $me = $this->physioGate($encounter, false);
        abort_unless($course->clinic_id === $encounter->clinic_id && $course->patient_id === $encounter->patient_id, 404);
        abort_unless(in_array($kind, PhysioConsultation::REPORTS, true), 404);
        $physio = app(PhysioConsultation::class)->payload($encounter, $request->user(), $me);
        $entry = collect($physio['courses'])->firstWhere('id', $course->id) ?? abort(404);
        $sessions = collect($physio['history'])->where('course_id', $course->id)->values();
        $session = $kind === 'session' ? ($request->filled('session') ? $sessions->firstWhere('id', (int) $request->query('session')) : $sessions->first()) : null;
        abort_if($kind === 'session' && ! $session, 404);
        $labels = collect($physio['choices'])->map(fn (array $options) => collect($options)->pluck('label', 'key')->all())->all();
        $problem = collect($physio['problems'])->firstWhere('key', $course->physio_problem);
        $treatments = collect($physio['treatments'])->keyBy('key');

        $data = [
            'kind' => $kind,
            'title' => PhysioConsultation::REPORT_TITLES[$kind],
            'clinic' => Clinic::query()->findOrFail($course->clinic_id),
            'branch' => Branch::query()->withoutGlobalScopes()->find($encounter->branch_id),
            'patient' => Patient::query()->withoutGlobalScopes()->findOrFail($course->patient_id),
            'course' => $entry,
            'type' => collect($problem['types'] ?? [])->firstWhere('key', $entry['type']),
            'treatments' => $treatments,
            'frequency' => collect($physio['frequencies'])->firstWhere('key', $entry['frequency'])['label'] ?? null,
            'labels' => $labels,
            'sessions' => $sessions,
            'session' => $session,
            'therapist' => $me->name,
            'printedOn' => ClinicClock::now(),
            'forDownload' => $request->boolean('download'),
        ];
        app(SecurityAudit::class)->record('physio.report_printed', $course, ['kind' => $kind, 'download' => $data['forDownload']]);

        return $data['forDownload']
            ? Pdf::loadView('clinic.physio_report', $data)->setPaper('a4')->download(Str::slug("{$entry['title']}-{$kind}").'.pdf')
            : response()->view('clinic.physio_report', $data);
    }

    /** The patient's home exercise sheet for one problem: the exercises prescribed now, ready to print. */
    public function physioExerciseSheet(ClinicalEncounter $encounter, PatientTreatment $course): HttpResponse
    {
        $me = $this->physioGate($encounter, false);
        abort_unless($course->clinic_id === $encounter->clinic_id && $course->patient_id === $encounter->patient_id, 404);
        $plan = PhysioCarePlan::query()->withoutGlobalScopes()->where('clinic_id', $course->clinic_id)->where('patient_treatment_id', $course->id)
            ->where('status', PhysioCarePlan::ACTIVE)->first();

        return response()->view('clinic.physio_exercise_sheet', [
            'clinic' => Clinic::query()->findOrFail($course->clinic_id),
            'branch' => Branch::query()->withoutGlobalScopes()->find($encounter->branch_id),
            'patient' => Patient::query()->withoutGlobalScopes()->findOrFail($course->patient_id),
            'course' => $course,
            'exercises' => $plan ? PhysioExercise::query()->withoutGlobalScopes()->where('care_plan_id', $plan->id)->orderBy('sort_order')->orderBy('id')->get() : collect(),
            'diagnosis' => $course->primary_diagnosis_id ? ClinicalDiagnosis::query()->withoutGlobalScopes()->whereKey($course->primary_diagnosis_id)->value('description') : null,
            'goals' => $course->goal,
            'advice' => $course->physio_details['plan']['instructions'] ?? null,
            'therapist' => $course->practitioner_id ? ClinicEmployee::query()->withoutGlobalScopes()->whereKey($course->practitioner_id)->value('name') : null,
            'printedOn' => ClinicClock::now(),
        ]);
    }

    /** A problem's complaint, diagnosis and plan, as tapped (`PhysioConsultation::saveDetails`). */
    public function physioDetails(Request $request, ClinicalEncounter $encounter, PatientTreatment $course, PhysioConsultation $consultation): RedirectResponse
    {
        $me = $this->physioGate($encounter, true);
        abort_unless($course->clinic_id === $encounter->clinic_id && $course->patient_id === $encounter->patient_id, 404);
        $keys = fn (array $options) => 'in:'.implode(',', array_keys($options));
        $data = $request->validate([
            'complaint' => ['sometimes', 'array'],
            'complaint.duration' => ['nullable', $keys(PhysioConsultation::DURATIONS)],
            'complaint.severity' => ['nullable', $keys(PhysioConsultation::SEVERITIES)],
            'complaint.difficulties' => ['nullable', 'array', 'max:8'],
            'complaint.difficulties.*' => [$keys(PhysioConsultation::DIFFICULTIES)],
            'complaint.previous' => ['nullable', 'array', 'max:5'],
            'complaint.previous.*' => [$keys(PhysioConsultation::PREVIOUS)],
            'complaint.description' => ['nullable', 'string', 'max:2000'],
            'complaint.remarks' => ['nullable', 'string', 'max:2000'],
            'diagnosis' => ['nullable', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', $keys(PhysioConsultation::CATEGORIES)],
            'goals' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        return $this->physioStep(fn () => $consultation->saveDetails($encounter, $course, $data, $me), 'Saved.');
    }

    /** A package of the course's problem, bought while it runs or to renew one that ran out (`PhysioConsultation::buyPackage`). */
    public function physioPackage(Request $request, ClinicalEncounter $encounter, PatientTreatment $course, PhysioConsultation $consultation): RedirectResponse
    {
        $me = $this->physioGate($encounter, true);
        abort_unless($course->clinic_id === $encounter->clinic_id && $course->patient_id === $encounter->patient_id, 404);
        $package = $request->validate(['package' => ['required', 'string', 'max:20']])['package'];

        return $this->physioStep(fn () => $consultation->buyPackage($course, $package, $me, $request->user(), $encounter), 'Package added. Its bill is at the desk.');
    }

    /** Start a suggested course: its session type, frequency, sessions and package (`PhysioConsultation::start`). */
    public function startPhysio(Request $request, ClinicalEncounter $encounter, PatientTreatment $course, PhysioConsultation $consultation): RedirectResponse
    {
        $me = $this->physioGate($encounter, true);
        abort_unless($course->clinic_id === $encounter->clinic_id && $course->patient_id === $encounter->patient_id, 404);
        $data = $request->validate([
            'type' => ['required', 'string', 'max:64'], 'frequency' => ['nullable', 'string', 'max:20'],
            'sessions' => ['nullable', 'integer', 'min:1', 'max:500'], 'package' => ['nullable', 'string', 'max:20'],
        ]);

        return $this->physioStep(fn () => $consultation->start($course, $data, $me, $request->user(), $encounter), 'Course started.');
    }

    /** From now on the course runs at another session type (`PhysioConsultation::changeType`). */
    public function physioType(Request $request, ClinicalEncounter $encounter, PatientTreatment $course, PhysioConsultation $consultation): RedirectResponse
    {
        $me = $this->physioGate($encounter, true);
        abort_unless($course->clinic_id === $encounter->clinic_id && $course->patient_id === $encounter->patient_id, 404);
        $type = $request->validate(['type' => ['required', 'string', 'max:64']])['type'];

        return $this->physioStep(fn () => $consultation->changeType($course, $type), 'Session type changed.');
    }

    /** @param  Closure(): mixed  $step */
    private function physioStep(Closure $step, string $success): RedirectResponse
    {
        try {
            $step();
        } catch (DomainException|InvalidArgumentException $e) {
            return back()->withErrors(['physio' => $e->getMessage()]);
        }

        return back()->with('success', $success);
    }

    /**
     * Who does the physiotherapy work on this visit, after checking they may:
     * the treating physiotherapist, or the desk working for a therapist who
     * has no login (`DeskPhysio`), in whose name the work is recorded.
     */
    private function physioGate(ClinicalEncounter $encounter, bool $write): ClinicEmployee
    {
        if (app(DeskPhysio::class)->runs(request()->user(), $encounter)) {
            abort_if($write && ! $encounter->isEditable(), 403, 'This visit is finished.');

            return ClinicEmployee::query()->withoutGlobalScopes()->findOrFail($encounter->doctor_id);
        }
        $this->authorize($write ? 'update' : 'view', $encounter);

        return $this->clinicContext->currentEmployee() ?? ($write ? abort(403) : ClinicEmployee::query()->withoutGlobalScopes()->findOrFail($encounter->doctor_id));
    }

    /**
     * The desk opens today's physiotherapy visit of a checked-in patient for
     * a therapist who has no login, and works it as the therapist would.
     */
    public function deskStartPhysio(ClinicQueueItem $item): RedirectResponse
    {
        $clinic = $this->clinicContext->clinic() ?? abort(403);
        $branch = $this->clinicContext->requireBranch();
        $queue = $item->queue()->withoutGlobalScopes()->first();
        abort_unless($queue && $queue->clinic_id === $clinic->id && $queue->branch_id === $branch->id, 404);
        $doctor = $item->doctor_id ? ClinicEmployee::query()->withoutGlobalScopes()->find($item->doctor_id) : null;
        abort_unless(app(DeskPhysio::class)->mayRunFor(request()->user(), $doctor), 403, 'The desk opens the visit only for a physiotherapist who has no login.');

        return $this->attempt(function () use ($item, $doctor) {
            $encounter = $this->workspace->startFromQueue($item, $doctor);
            if ($encounter->specialty === null) {
                $encounter->forceFill(['specialty' => PhysioCareSummary::SPECIALTY])->save();
            }

            return redirect()->route('clinic.desk-physio.show', $encounter);
        });
    }

    /** The physiotherapy workspace, run by the desk for a therapist without a login. */
    public function deskPhysio(ClinicalEncounter $encounter): Response
    {
        abort_unless(app(DeskPhysio::class)->runs(request()->user(), $encounter), 403);

        return $this->renderPhysio($encounter->load(['patient' => fn ($query) => $query->withoutGlobalScopes(), 'doctor:id,name', 'branch:id,name']), true);
    }

    /** The physiotherapy workspace (`PhysioConsultation::payload`), for the therapist or (`$desk`) the desk working for one. */
    private function renderPhysio(ClinicalEncounter $encounter, bool $desk): Response
    {
        $user = request()->user();
        $patient = $encounter->patient;
        $me = $desk ? ClinicEmployee::query()->withoutGlobalScopes()->findOrFail($encounter->doctor_id) : $this->clinicContext->currentEmployee();
        $canEdit = $desk ? $encounter->isEditable() : ($user->can('update', $encounter) && $me?->practitionerProfile !== null);
        // The same actions on either path; the desk's are under the front desk, gated by its own permission.
        $r = $desk ? 'clinic.desk-physio.' : 'clinic.consultations.physio.';

        // This year's case number (one per patient per year; the UHID is for life), through today's visit.
        $caseNumber = $encounter->patient_visit_id
            ? PatientVisit::query()->withoutGlobalScopes()->where('clinic_id', $encounter->clinic_id)->with('caseNumber')->find($encounter->patient_visit_id)?->caseNumber?->case_number
            : null;

        return Inertia::render('ClinicPortal/Consultations/Physio', [
            'encounter' => [
                'id' => $encounter->id, 'number' => $encounter->encounter_number, 'started_at' => $encounter->started_at?->toIso8601String(),
                'doctor' => $encounter->doctor?->name, 'branch' => $encounter->branch?->name, 'case_number' => $caseNumber,
            ],
            'patient' => [
                'id' => $patient->id, 'name' => $patient->full_name, 'uhid' => $patient->uhid, 'age' => $patient->age, 'gender' => $patient->gender,
                'phone' => $patient->phone ? substr($patient->phone, 0, 2).'••••'.substr($patient->phone, -4) : null,
                'registered_on' => $patient->created_at?->toDateString(),
            ],
            // Why the patient came today (reason, specialty), as the desk recorded it.
            'visit' => $this->visitContext($encounter),
            'physio' => app(PhysioConsultation::class)->payload($encounter, $user, $me),
            'can_edit' => $canEdit,
            // What the desk collects from this page once the visit is finished (null for the therapist's own page).
            'payment' => $desk && $encounter->isFinalized() ? app(FrontDesk::class)->visitPayment($encounter, $user) : null,
            // The desk working for a therapist without a login: shown so, and the work is in the therapist's name.
            'desk' => $desk ? ['therapist' => $me->name] : null,
            'links' => [
                'finish' => route($r.'finish', $encounter),
                'patient' => route('clinic.patients.show', $patient->id),
                // The whole plan of a problem in one step: found, assessed, planned, started.
                'plan' => route($r.'plan', $encounter),
                // With the course id in place of 0.
                'package' => route($r.'courses.package', [$encounter, 0]),
                'sheet' => route($r.'courses.sheet', [$encounter, 0]),
                'progress' => route($r.'courses.progress', [$encounter, 0]),
                'report' => route($r.'courses.report', [$encounter, 0, 'assessment']),
                // Supporting documents live in Patient 360's documents.
                'documents' => route('clinic.patients.show', ['patient' => $patient->id, 'zone' => 'docs']),
                // A follow-up through the patient's own follow-ups; the coming visits are moved in the calendar.
                'follow_up' => app(Patient360Access::class)->canManageFollowUps($user) ? route('clinic.patients.follow-ups.store', $patient->id) : null,
                'calendar' => route('clinic.appointments.index'),
                'today' => $desk ? route('clinic.reception') : route('clinic.consultations.index'),
                // Once the visit is finished, its bill is taken here (the clinic's one checkout), by whoever may bill.
                'checkout' => $user->hasClinicPermission(ClinicPermission::BillingCreate) ? route('clinic.billing.checkout', $encounter) : null,
            ],
        ]);
    }

    public function show(ClinicalEncounter $encounter, ConsultationCare $care): Response
    {
        $this->authorize('view', $encounter);
        app(CrossBranchClinicalAccess::class)->record(request()->user(), $encounter);

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
        // A physiotherapy visit has its own consultation: courses, the day's treatments, packages, the next visit.
        if ($this->isPhysioVisit($encounter, $branchSpecialties)) {
            return $this->renderPhysio($encounter, false);
        }
        // `?record=1` opens the full consultation record (prescriptions, investigations, addenda) of a dental visit.
        if (! request()->boolean('record') && $this->isDentalVisit($encounter, $branchSpecialties)) {
            return $this->renderDental($encounter, $care);
        }

        // The patient's allergy record, once, for the prescriptions' signing check (clinical roles reach this page).
        $allergyState = app(PrescriptionAllergyCheck::class)->stateFor($patient);

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
                // The clinical safety picture for this visit: allergies as recorded (documented / none known / not recorded) and medical conditions.
                'allergies' => collect($allergyState)->except('fingerprint')->all(),
                'conditions' => app(PatientClinicalEntries::class)->labelsFor($patient, DefaultClinicalCatalogue::KIND_CONDITION),
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
                // The allergy check for signing: a draft shows the current record (and whether it has been
                // reviewed against it); a signed prescription shows what was known when it was signed.
                'allergies' => $rx->isDraft() ? (function () use ($rx, $allergyState) {
                    $current = $allergyState;

                    return [
                        ...$current,
                        'review_required' => $current['status'] === PrescriptionAllergyCheck::DOCUMENTED,
                        'reviewed' => $rx->allergy_reviewed_at !== null && hash_equals($current['fingerprint'], (string) ($rx->allergy_review['fingerprint'] ?? '')),
                        'reviewed_at' => $rx->allergy_reviewed_at?->toIso8601String(),
                    ];
                })() : ($rx->allergy_review ? collect($rx->allergy_review)->except('fingerprint')->all() + ['reviewed_at' => $rx->allergy_reviewed_at?->toIso8601String()] : null),
                'links' => [
                    'self' => route('clinic.prescriptions.update', $rx),
                    'allergy_review' => route('clinic.prescriptions.allergy-review', $rx),
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
                    'url' => ClinicFrontend::available('clinic.orders.show') ? route('clinic.orders.show', $order) : null,
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
                // Gum charting and laboratory work, recorded in this consultation (`DentalController`, `DentalSpecialtyController`).
                'periodontal' => route('clinic.consultations.dental.periodontal', $encounter),
                'lab' => route('clinic.consultations.dental.lab.store', $encounter),
                'periodontal_vocabulary' => [
                    'stages' => config('clinic_dental.periodontal.stages', []),
                    'grades' => config('clinic_dental.periodontal.grades', []),
                    'extents' => config('clinic_dental.periodontal.extents', []),
                ],
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
            'can_sell_package' => in_array('session_packages', $extensions, true) && SessionPackages::maySell($user),
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

    /** The patient's open entry in today's queue at this branch (waiting, called or being seen), if any. */
    private function todaysQueueEntry(Patient $patient, Branch $branch): ?ClinicQueueItem
    {
        return ClinicQueueItem::query()->withoutGlobalScopes()
            ->whereIn('status', [QueueItemStatus::Waiting, QueueItemStatus::Called, QueueItemStatus::InConsultation])
            ->whereHas('queue', fn ($queue) => $queue->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->where('branch_id', $branch->id)
                ->whereOnDate('queue_date', ClinicClock::now()->toDateString()))
            ->whereHas('patientVisit', fn ($visit) => $visit->withoutGlobalScopes()->where('patient_id', $patient->id))
            ->latest('id')->first();
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
