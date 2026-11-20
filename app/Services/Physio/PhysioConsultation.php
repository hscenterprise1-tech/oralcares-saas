<?php

namespace App\Services\Physio;

use App\AppointmentSource;
use App\AppointmentStatus;
use App\BillingStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalDiagnosis;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientBilling;
use App\Models\PatientBillingItem;
use App\Models\PatientFollowUp;
use App\Models\PatientProcedure;
use App\Models\PatientSessionPackage;
use App\Models\PatientTreatment;
use App\Models\PhysioCarePlan;
use App\Models\PhysioExercise;
use App\Models\PhysioSession;
use App\Models\PhysioSessionExercise;
use App\Models\User;
use App\Services\Appointments\AppointmentAvailabilityService;
use App\Services\Appointments\AppointmentBookingService;
use App\Services\Appointments\AppointmentSettingsService;
use App\Services\Billing\EncounterCheckout;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\ClinicalProcedureService;
use App\Services\Reception\DoctorRoster;
use App\Services\Treatment\PatientTreatments;
use App\Services\Treatment\TreatmentReadModel;
use App\Support\ClinicClock;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * The physio consultation, by problem (`PhysioCatalogue`):
 *
 * 1. the doctor finds the patient's problems — Knee pain (right), Shoulder
 *    pain (left) — each a course, first only *suggested*;
 * 2. the patient chooses which to start now; a started course runs at a
 *    session type ("Full treatment" ₹250, later "Exercise only" ₹200), with a
 *    package of that problem or paying per visit;
 * 3. each visit is one click — "session done" gives the type's treatments
 *   (changed only when that day was different), with any add-ons (cupping);
 * 4. the next visits are booked by the course's frequency, at one time.
 *
 * Charges are what the owner set: the type's price per visit unless the
 * course's package covers that type (then one package session), plus each
 * add-on. They are recorded as priced procedures, so the desk bills them at
 * checkout exactly as they were. Built only on the existing writers
 * (`PhysioRecord`, `SessionPackages`, `ClinicalProcedureService`,
 * `EncounterWorkspace`, `AppointmentBookingService`).
 */
class PhysioConsultation
{
    public const FEELS = ['better' => 'Better', 'same' => 'Same', 'worse' => 'Worse'];

    public const PERFORMED = 'performed';

    public const NOT_PERFORMED = 'not_performed';

    /** The complaint, recorded by tapping (spec §7, §13): how long, how bad, what it stops, what was tried. */
    public const DURATIONS = ['days' => 'A few days', 'weeks' => '1–3 weeks', 'month' => '1–3 months', 'months' => '3–6 months', 'long' => 'Over 6 months'];

    public const SEVERITIES = ['mild' => 'Mild', 'moderate' => 'Moderate', 'severe' => 'Severe'];

    public const DIFFICULTIES = ['walking' => 'Walking', 'sitting' => 'Sitting', 'standing' => 'Standing', 'stairs' => 'Stair climbing',
        'bending' => 'Bending', 'lifting' => 'Lifting', 'daily' => 'Daily activities', 'other' => 'Other'];

    public const PREVIOUS = ['none' => 'Nothing yet', 'painkillers' => 'Painkillers', 'physio' => 'Physio before', 'injection' => 'Injection', 'surgery' => 'Surgery'];

    /** A plan's aim (spec §15). */
    public const CATEGORIES = ['pain' => 'Pain management', 'strengthening' => 'Strengthening', 'mobility' => 'Mobility improvement', 'rehabilitation' => 'Rehabilitation',
        'posture' => 'Posture improvement', 'walking' => 'Walking improvement', 'functional' => 'Functional training', 'other' => 'Other'];

    /** The reports of one problem (spec §22), each made from its record; no discharge summary (the owner's decision). */
    public const REPORTS = ['assessment', 'plan', 'session', 'progress', 'package'];

    public const REPORT_TITLES = ['assessment' => 'Assessment & diagnosis', 'plan' => 'Treatment plan', 'session' => 'Session report', 'progress' => 'Progress report', 'package' => 'Package summary'];

    /** What became of each planned treatment in a session; only those given are charged. */
    public const TREATMENT_STATUSES = [self::PERFORMED => 'Performed', 'partial' => 'Partly performed', self::NOT_PERFORMED => 'Not performed'];

    /** Days between visits, by the course's frequency. */
    public const FREQUENCIES = ['daily' => ['label' => 'Daily', 'step' => 1], 'alternate' => ['label' => 'Alternate days', 'step' => 2],
        'thrice_weekly' => ['label' => '3 times a week', 'step' => 2], 'twice_weekly' => ['label' => 'Twice a week', 'step' => 3], 'weekly' => ['label' => 'Weekly', 'step' => 7]];

    private const BOOKING_DAYS = 14;

    public function __construct(
        protected PhysioCatalogue $catalogue,
        protected PhysioRecord $physio,
        protected SessionPackages $packages,
        protected DoctorRoster $roster,
        protected AppointmentAvailabilityService $availability,
        protected AppointmentSettingsService $appointmentSettings,
    ) {}

    /**
     * Everything the screen shows for this visit.
     *
     * @return array<string, mixed>
     */
    public function payload(ClinicalEncounter $encounter, User $user, ?ClinicEmployee $me): array
    {
        $clinic = Clinic::query()->findOrFail($encounter->clinic_id);
        $branch = Branch::query()->withoutGlobalScopes()->findOrFail($encounter->branch_id);
        $patient = Patient::query()->withoutGlobalScopes()->findOrFail($encounter->patient_id);
        $catalogue = $this->catalogue->resolve($clinic);
        $problems = collect($catalogue['problems'])->where('active', true)->keyBy('key');
        $sessions = PhysioSession::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('encounter_id', $encounter->id)->get()->keyBy('patient_treatment_id');
        $names = ClinicEmployee::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->pluck('name', 'id');
        $courses = $this->courses($patient);
        $diagnoses = ClinicalDiagnosis::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)
            ->whereIn('id', $courses->pluck('primary_diagnosis_id')->filter())->pluck('description', 'id');
        $history = $this->history($patient, $names->all());
        $prescriptions = PhysioExercise::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)
            ->whereIn('care_plan_id', PhysioCarePlan::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)
                ->whereIn('patient_treatment_id', $courses->pluck('id'))->where('status', PhysioCarePlan::ACTIVE)->select('id'))
            ->orderBy('sort_order')->orderBy('id')->get()->groupBy('patient_treatment_id');

        return [
            'consultation' => [
                'complaint' => $encounter->chief_complaint,
                'history' => $encounter->history,
                'findings' => $encounter->examination,
                'advice' => $encounter->follow_up_note,
                'diagnoses' => $encounter->diagnoses()->withoutGlobalScopes()->orderByDesc('is_primary')->pluck('description')->all(),
            ],
            'courses' => $courses->map(function (PatientTreatment $course) use ($sessions, $problems, $diagnoses, $prescriptions, $history) {
                $package = $this->packages->eligible($course)->first();
                // With no package running: the last one, so the screen can say it ran out (and offer the renewal).
                $last = $package ? null : PatientSessionPackage::query()->withoutGlobalScopes()->where('clinic_id', $course->clinic_id)
                    ->where('patient_treatment_id', $course->id)->latest('id')->first();
                $today = $sessions->get($course->id);
                $problem = $problems->get($course->physio_problem);

                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'problem' => $course->physio_problem,
                    'side' => $course->body_side,
                    'type' => $course->session_type,
                    'status' => $course->status === PatientTreatment::PLANNED ? 'suggested' : 'active',
                    'frequency' => $course->visit_frequency,
                    // On the clinic's calendar: a course started after midnight IST is not "yesterday" in UTC.
                    'started' => $course->started_at ? ClinicClock::fromUtc($course->started_at->toImmutable())->toDateString() : null,
                    'done' => (int) $course->sessions_completed,
                    'planned' => $course->sessions_planned ? (int) $course->sessions_planned : null,
                    'known_problem' => $problem !== null,
                    // The complaint and plan as recorded, the diagnosis naming the plan, the goals.
                    'details' => [
                        'complaint' => $course->physio_details['complaint'] ?? null,
                        'plan' => $course->physio_details['plan'] ?? null,
                        'recorded_at' => $course->physio_details['recorded_at'] ?? null,
                        'recorded_by' => $course->physio_details['recorded_by'] ?? null,
                        'revisions' => count((array) ($course->physio_details['revisions'] ?? [])),
                    ],
                    'diagnosis' => $course->primary_diagnosis_id ? ($diagnoses[$course->primary_diagnosis_id] ?? null) : null,
                    'goals' => $course->goal,
                    // The exercises prescribed now (the plan's current revision), as they were copied from the library.
                    'progress' => $this->progressOf($course, $history),
                    'packages' => $this->packagesOf($course),
                    'exercises' => ($prescriptions[$course->id] ?? collect())->map(fn (PhysioExercise $e) => [
                        'id' => $e->id, 'name' => $e->name, 'sets' => $e->sets, 'reps' => $e->reps, 'hold' => $e->hold_seconds,
                        'per_day' => preg_match('/^(\d+)×/u', (string) $e->frequency, $m) ? (int) $m[1] : null, 'instructions' => $e->instructions,
                    ])->values()->all(),
                    'package' => $package ? [
                        'id' => $package['package']->id, 'name' => $package['package']->name,
                        'total' => (int) $package['package']->sessions_total, 'left' => $package['remaining'],
                        'valid_until' => $package['package']->valid_until?->toDateString(),
                        'types' => array_values((array) ($package['package']->includes ?? [])),
                    ] : null,
                    'package_ended' => $last ? [
                        'name' => $last->name, 'total' => (int) $last->sessions_total,
                        'used' => (int) $last->sessions_total - max(0, $this->packages->balance($last)),
                        'expired' => $last->isExpired(),
                    ] : null,
                    'today' => $today ? [
                        'status' => $today->status, 'type' => $today->session_type,
                        'given' => array_values((array) ($today->given ?? [])), 'addons' => array_values((array) ($today->addons ?? [])),
                        'charge' => $today->charge !== null ? (float) $today->charge : null,
                        'feel' => array_search($today->patient_response, self::FEELS, true) ?: null, 'note' => $today->treatment_performed,
                    ] : null,
                ];
            })->values()->all(),
            'problems' => $problems->values()->all(),
            'treatments' => array_values($this->catalogue->treatments($clinic)),
            'addons' => array_values($this->catalogue->addons($clinic)),
            'sides' => PhysioCatalogue::SIDES,
            'frequencies' => collect(self::FREQUENCIES)->map(fn (array $row, string $key) => ['key' => $key, 'label' => $row['label']])->values()->all(),
            // The clinic's exercise library, to prescribe from.
            'exercises' => array_values($this->catalogue->exercises($clinic)),
            'choices' => collect(['durations' => self::DURATIONS, 'severities' => self::SEVERITIES, 'difficulties' => self::DIFFICULTIES, 'previous' => self::PREVIOUS, 'categories' => self::CATEGORIES, 'exercise_categories' => PhysioCatalogue::EXERCISE_CATEGORIES])
                ->map(fn (array $options) => collect($options)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values()->all())->all(),
            'can_sell_package' => SessionPackages::maySell($user),
            'therapists' => $this->therapists($clinic, $branch),
            'me' => $me?->id,
            'history' => $history,
            // The patient's open follow-ups, newest due first.
            'follow_ups' => PatientFollowUp::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('patient_id', $patient->id)
                ->whereIn('status', [PatientFollowUp::DUE, PatientFollowUp::BOOKED])->orderBy('due_on')->limit(20)->get(['id', 'due_on', 'reason', 'status'])
                ->map(fn (PatientFollowUp $f) => ['id' => $f->id, 'due_on' => $f->due_on?->toDateString(), 'reason' => $f->reason, 'status' => $f->status])->all(),
            'stats' => $this->stats($patient),
            'booking' => $this->slots($clinic, $branch, $me, $patient),
            'finished' => $encounter->isFinalized(),
            // What a finished visit still has to bill (the checkout's own lines), so payment is asked for only when something is owed.
            'to_bill' => $encounter->isFinalized()
                ? round(collect(app(EncounterCheckout::class)->lines($encounter))->sum(fn (array $line) => $line['quantity'] * $line['unit_price']), 2)
                : 0.0,
        ];
    }

    /**
     * A problem found: a course suggested for it (not started), on a side.
     */
    public function suggest(ClinicalEncounter $encounter, string $problemKey, ?string $side, ClinicEmployee $me, User $by): PatientTreatment
    {
        return $this->suggestFor(Patient::query()->withoutGlobalScopes()->findOrFail($encounter->patient_id),
            Branch::query()->withoutGlobalScopes()->findOrFail($encounter->branch_id), $problemKey, $side, $me, $by, $encounter);
    }

    /**
     * The problem the desk chose at check-in (`PhysioCatalogue::visitReasons`,
     * e.g. "Knee pain · Right"): suggested for the doctor, unless that
     * problem on that side is already being treated.
     */
    public function suggestFromVisitReason(Patient $patient, Branch $branch, string $reasonKey, ClinicEmployee $doctor, User $by): ?PatientTreatment
    {
        $clinic = Clinic::query()->findOrFail($patient->clinic_id);
        $reason = $this->catalogue->visitReason($clinic, $reasonKey);
        if ($reason === null) {
            return null;
        }

        // The check-in never fails for it: already treated, or not the desk's to start, leaves the doctor the visit reason.
        try {
            return $this->suggestFor($patient, $branch, $reason['problem'], $reason['side'], $doctor, $by);
        } catch (DomainException|InvalidArgumentException|AuthorizationException) {
            return null;
        }
    }

    private function suggestFor(Patient $patient, Branch $branch, string $problemKey, ?string $side, ClinicEmployee $me, User $by, ?ClinicalEncounter $encounter = null): PatientTreatment
    {
        $clinic = Clinic::query()->findOrFail($patient->clinic_id);
        $problem = $this->catalogue->problem($clinic, $problemKey) ?? throw new InvalidArgumentException('That problem is not on the clinic’s list.');
        if ($problem['sided'] && ! isset(PhysioCatalogue::SIDES[(string) $side])) {
            throw new InvalidArgumentException('Choose the side: left, right or both.');
        }
        $side = $problem['sided'] ? $side : null;
        $duplicate = PatientTreatment::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('patient_id', $patient->id)
            ->where('physio_problem', $problemKey)->where('body_side', $side)->whereIn('status', TreatmentReadModel::OPEN)->exists();
        if ($duplicate) {
            throw new DomainException('This problem is already being treated.');
        }

        return DB::transaction(function () use ($encounter, $patient, $branch, $problem, $side, $me, $by) {
            $title = $problem['label'].($side ? ' · '.PhysioCatalogue::SIDES[$side] : '');
            $course = app(PatientTreatments::class)->startAdHoc($patient, PhysioCareSummary::SPECIALTY, $title, $me, $branch, $by, $encounter ? ['encounter' => $encounter] : ['at_desk' => true]);
            $course->forceFill(['physio_problem' => $problem['key'], 'body_side' => $side, 'session_type' => $problem['types'][0]['key'] ?? null,
                'status' => PatientTreatment::PLANNED, 'started_at' => null])->save();

            return $course;
        });
    }

    /**
     * The doctor's whole plan for one problem in one step (the workspace's
     * "New problem" and "Plan" flow): the problem and side (or a problem
     * already found), its complaint and diagnosis, its plan — session type,
     * how often, how many, aim, goals, instructions — and, when `start` is
     * set, the treatment started, with a package if one is chosen. A running
     * treatment's plan is changed the same way. All or nothing.
     *
     * @param  array<string, mixed>  $data
     */
    public function plan(ClinicalEncounter $encounter, array $data, ClinicEmployee $me, User $by): PatientTreatment
    {
        return DB::transaction(function () use ($encounter, $data, $me, $by) {
            $course = ! empty($data['course_id'])
                ? PatientTreatment::query()->withoutGlobalScopes()->where('clinic_id', $encounter->clinic_id)->where('patient_id', $encounter->patient_id)
                    ->whereIn('status', TreatmentReadModel::OPEN)->findOrFail((int) $data['course_id'])
                : $this->suggest($encounter, (string) ($data['problem'] ?? ''), $data['side'] ?? null, $me, $by);

            $this->saveDetails($encounter, $course, array_intersect_key($data, array_flip(['complaint', 'diagnosis', 'category', 'goals', 'instructions'])), $me);
            $course->refresh();
            if (array_key_exists('exercises', $data)) {
                $this->prescribe($course, (array) $data['exercises'], $me, $by, $encounter);
            }

            if (! empty($data['type'])) {
                $plan = ['type' => (string) $data['type'], 'frequency' => $data['frequency'] ?? null, 'sessions' => $data['sessions'] ?? null];
                if ($course->status === PatientTreatment::PLANNED && empty($data['start'])) {
                    // Planned now, started on a later visit: the plan is kept as it was set.
                    $clinic = Clinic::query()->findOrFail($course->clinic_id);
                    $type = $this->catalogue->type($clinic, $course->physio_problem, $plan['type']) ?? throw new InvalidArgumentException('Choose a session type of this problem.');
                    $course->forceFill([
                        'session_type' => $type['key'],
                        'visit_frequency' => isset(self::FREQUENCIES[$plan['frequency'] ?? '']) ? $plan['frequency'] : $course->visit_frequency,
                        'sessions_planned' => $plan['sessions'] ? max(1, min(500, (int) $plan['sessions'])) : $course->sessions_planned,
                    ])->save();
                } elseif ($course->status === PatientTreatment::PLANNED) {
                    $this->start($course, [...$plan, 'package' => $data['package'] ?? null], $me, $by, $encounter);
                } else {
                    $this->start($course, $plan, $me, $by, $encounter);
                    if (! empty($data['package'])) {
                        $this->buyPackage($course->fresh(), (string) $data['package'], $me, $by, $encounter);
                    }
                }
            }

            return $course->fresh();
        });
    }

    /**
     * The exercises prescribed for a problem, from the clinic's library:
     * each copied with its sets, reps, hold, times a day and instructions
     * (`PhysioRecord` care plan), so a later library change never changes a
     * prescription. A changed list is a new revision of the plan; the old one
     * is kept as it was. The same list again changes nothing.
     *
     * @param  list<array{key?: string, sets?: ?int, reps?: ?int, hold?: ?int, per_day?: ?int, instructions?: ?string}>  $rows
     */
    private function prescribe(PatientTreatment $course, array $rows, ClinicEmployee $me, User $by, ClinicalEncounter $encounter): void
    {
        $library = $this->catalogue->exercises(Clinic::query()->findOrFail($course->clinic_id));
        $number = fn (mixed $value, int $max) => $value === null || $value === '' ? null : max(0, min($max, (int) $value));
        $exercises = array_values(array_map(function (array $row) use ($library, $number) {
            $item = $library[$row['key'] ?? ''] ?? throw new InvalidArgumentException('That exercise is not in the clinic’s library.');
            $perDay = $number($row['per_day'] ?? null, 10);

            return [
                'name' => $item['label'],
                'sets' => $number($row['sets'] ?? $item['sets'], 50),
                'reps' => $number($row['reps'] ?? $item['reps'], 500),
                'hold_seconds' => $number($row['hold'] ?? $item['hold'], 600),
                'frequency' => $perDay ? "{$perDay}× a day" : null,
                'instructions' => trim((string) ($row['instructions'] ?? '')) ?: $item['instructions'],
                'is_home' => true,
            ];
        }, array_slice($rows, 0, 30)));

        $current = PhysioCarePlan::query()->withoutGlobalScopes()->where('clinic_id', $course->clinic_id)->where('patient_treatment_id', $course->id)
            ->where('status', PhysioCarePlan::ACTIVE)->with(['exercises' => fn ($query) => $query->withoutGlobalScopes()])->first();
        if ($current) {
            $shape = fn (array $rows) => array_map(fn (array $row) => [$row['name'], $row['sets'], $row['reps'], $row['hold_seconds'], $row['frequency'], $row['instructions']], $rows);
            $existing = $current->exercises->sortBy('sort_order')->map(fn (PhysioExercise $e) => ['name' => $e->name, 'sets' => $e->sets, 'reps' => $e->reps, 'hold_seconds' => $e->hold_seconds, 'frequency' => $e->frequency, 'instructions' => $e->instructions])->values()->all();
            if ($shape($existing) !== $shape($exercises)) {
                $this->physio->revisePlan($current, [], $exercises, $me, $by);
            }

            return;
        }
        if ($exercises !== []) {
            $this->physio->startPlan($course, ['title' => $course->title, 'goals' => $course->goal, 'planned_sessions' => $course->sessions_planned], $exercises, $me, $by, $encounter);
        }
    }

    /**
     * Start a suggested course: its session type, how often, how many
     * sessions, and a package of its problem where one is chosen (its bill
     * goes to the desk).
     *
     * @param  array{type: string, frequency?: ?string, sessions?: ?int, package?: ?string}  $data
     */
    public function start(PatientTreatment $course, array $data, ClinicEmployee $me, User $by, ?ClinicalEncounter $encounter = null): PatientTreatment
    {
        $clinic = Clinic::query()->findOrFail($course->clinic_id);
        $type = $this->catalogue->type($clinic, $course->physio_problem, $data['type']) ?? throw new InvalidArgumentException('Choose a session type of this problem.');
        $template = ! empty($data['package']) ? ($this->catalogue->package($clinic, $course->physio_problem, (string) $data['package']) ?? throw new InvalidArgumentException('That package is no longer offered.')) : null;
        if ($template && ! SessionPackages::maySell($by)) {
            throw new DomainException('Selling a package needs the package-selling permission.');
        }

        return DB::transaction(function () use ($course, $data, $type, $template, $me, $encounter) {
            $course = PatientTreatment::query()->withoutGlobalScopes()->whereKey($course->id)->lockForUpdate()->firstOrFail();
            $sessions = (int) ($data['sessions'] ?? 0) ?: ($template['sessions'] ?? null);
            $course->forceFill([
                'status' => PatientTreatment::IN_PROGRESS, 'started_at' => $course->started_at ?? now(), 'session_type' => $type['key'],
                'sessions_planned' => $sessions ? max(1, min(500, $sessions)) : $course->sessions_planned,
                // The frequency is kept with the course (its visits are booked by it).
                'visit_frequency' => isset(self::FREQUENCIES[$data['frequency'] ?? '']) ? $data['frequency'] : $course->visit_frequency,
            ])->save();

            if ($template) {
                $this->sellTemplate($course, $template, $me, $encounter);
            }

            return $course;
        });
    }

    /**
     * A package of the course's problem bought while the course runs — the
     * patient paid per visit so far, or the last package ran out (a renewal).
     * The course's planned sessions grow to cover it.
     */
    public function buyPackage(PatientTreatment $course, string $packageId, ClinicEmployee $me, User $by, ?ClinicalEncounter $encounter = null): PatientSessionPackage
    {
        $clinic = Clinic::query()->findOrFail($course->clinic_id);
        if ($course->status !== PatientTreatment::IN_PROGRESS) {
            throw new DomainException('Start the course before selling it a package.');
        }
        $template = $this->catalogue->package($clinic, $course->physio_problem, $packageId) ?? throw new InvalidArgumentException('That package is not offered for this problem.');
        if (! SessionPackages::maySell($by)) {
            throw new DomainException('Selling a package needs the package-selling permission.');
        }

        return DB::transaction(function () use ($course, $template, $me, $encounter) {
            $course = PatientTreatment::query()->withoutGlobalScopes()->whereKey($course->id)->lockForUpdate()->firstOrFail();
            $sold = $this->sellTemplate($course, $template, $me, $encounter);
            $course->forceFill(['sessions_planned' => min(500, max((int) $course->sessions_planned, (int) $course->sessions_completed + $template['sessions']))])->save();

            return $sold;
        });
    }

    /**
     * Sell one of the owner's packages for the course: its bill to the desk,
     * the session types it covers, and the package it renews, if any. Sold
     * during a visit, the bill is that visit's.
     *
     * @param  array{name: string, sessions: int, price: float, valid_days: int, types: list<string>}  $template
     */
    private function sellTemplate(PatientTreatment $course, array $template, ClinicEmployee $me, ?ClinicalEncounter $encounter = null): PatientSessionPackage
    {
        $branch = Branch::query()->withoutGlobalScopes()->findOrFail($course->branch_id);
        $previous = PatientSessionPackage::query()->withoutGlobalScopes()->where('clinic_id', $course->clinic_id)
            ->where('patient_treatment_id', $course->id)->latest('id')->value('id');
        $sold = $this->packages->sell($course, $branch, ['name' => $template['name'], 'sessions_total' => $template['sessions'], 'price' => $template['price'],
            'valid_until' => ClinicClock::now()->addDays($template['valid_days'])->toDateString(), 'renews_package_id' => $previous], $me, $encounter);
        $sold->forceFill(['includes' => $template['types'] ?: null])->save();

        return $sold;
    }

    /**
     * A problem's complaint, diagnosis and plan, as the doctor taps them: the
     * complaint (duration, severity, difficulties, what was tried, a note),
     * the diagnosis from the clinic's list (or typed) on this consultation,
     * the plan's category, goals and instructions. A change keeps the
     * version it replaces; nothing recorded is silently overwritten.
     *
     * @param  array{complaint?: array<string, mixed>, diagnosis?: ?string, category?: ?string, goals?: ?string, instructions?: ?string}  $data
     */
    public function saveDetails(ClinicalEncounter $encounter, PatientTreatment $course, array $data, ClinicEmployee $me): PatientTreatment
    {
        if ($encounter->isFinalized()) {
            throw new DomainException('This visit is finished; open today’s visit to change the record.');
        }

        return DB::transaction(function () use ($encounter, $course, $data, $me) {
            $course = PatientTreatment::query()->withoutGlobalScopes()->whereKey($course->id)->lockForUpdate()->firstOrFail();
            $before = (array) ($course->physio_details ?? []);
            $complaint = array_key_exists('complaint', $data) ? $this->complaint((array) $data['complaint']) : ($before['complaint'] ?? null);
            $plan = [
                'category' => array_key_exists('category', $data) ? ($data['category'] ?: null) : ($before['plan']['category'] ?? null),
                'instructions' => array_key_exists('instructions', $data) ? (trim((string) $data['instructions']) ?: null) : ($before['plan']['instructions'] ?? null),
            ];
            $revisions = (array) ($before['revisions'] ?? []);
            if (isset($before['recorded_at']) && ($complaint !== ($before['complaint'] ?? null) || $plan !== ($before['plan'] ?? null))) {
                $revisions[] = ['complaint' => $before['complaint'] ?? null, 'plan' => $before['plan'] ?? null, 'recorded_at' => $before['recorded_at'], 'recorded_by' => $before['recorded_by'] ?? null];
            }

            // Everything else recorded on the problem (progress notes, goals reached, the review date) stays as it is.
            $fill = ['physio_details' => [...$before, 'complaint' => $complaint, 'plan' => $plan, 'recorded_at' => now()->toIso8601String(), 'recorded_by' => $me->name, 'revisions' => array_slice($revisions, -20)]];
            if (array_key_exists('goals', $data)) {
                $fill['goal'] = trim((string) $data['goals']) ?: null;
            }

            // The diagnosis goes on this consultation (Patient 360 keeps it) and names the problem's plan.
            $diagnosis = trim((string) ($data['diagnosis'] ?? ''));
            $current = $course->primary_diagnosis_id ? ClinicalDiagnosis::query()->withoutGlobalScopes()->whereKey($course->primary_diagnosis_id)->value('description') : null;
            if ($diagnosis !== '' && $diagnosis !== $current) {
                $workspace = app(EncounterWorkspace::class);
                $fresh = ClinicalEncounter::query()->withoutGlobalScopes()->findOrFail($encounter->id);
                $recorded = $fresh->diagnoses()->withoutGlobalScopes()->where('description', $diagnosis)->first()
                    ?? $workspace->addDiagnosis($fresh, ['description' => mb_substr($diagnosis, 0, 255), 'status' => 'confirmed']);
                $recorded->forceFill(['details' => [...(array) ($recorded->details ?? []), 'patient_treatment_id' => $course->id, 'problem' => $course->title]])->save();
                $fill['primary_diagnosis_id'] = $recorded->id;
            }

            $course->forceFill($fill)->save();

            return $course;
        });
    }

    /**
     * The problem's progress as the doctor records it (spec §19): a goal
     * reached (or not yet), a progress note (a review, when it is one), the
     * next review date. Notes are only added, never changed; nothing is
     * measured or made up.
     *
     * @param  array{goal?: ?string, done?: ?bool, note?: ?string, review?: ?bool, review_on?: ?string}  $data
     */
    public function progress(ClinicalEncounter $encounter, PatientTreatment $course, array $data, ClinicEmployee $me): PatientTreatment
    {
        if ($encounter->isFinalized()) {
            throw new DomainException('This visit is finished; open today’s visit to record progress.');
        }

        return DB::transaction(function () use ($course, $data, $me) {
            $course = PatientTreatment::query()->withoutGlobalScopes()->whereKey($course->id)->lockForUpdate()->firstOrFail();
            $details = (array) ($course->physio_details ?? []);
            $today = ClinicClock::now()->toDateString();

            $goal = trim((string) ($data['goal'] ?? ''));
            if ($goal !== '') {
                if (! in_array($goal, self::goals($course->goal), true)) {
                    throw new InvalidArgumentException('That goal is not on this plan.');
                }
                $done = (array) ($details['goals_done'] ?? []);
                if (! empty($data['done'])) {
                    $done[$goal] = $done[$goal] ?? $today;
                } else {
                    unset($done[$goal]);
                }
                $details['goals_done'] = $done;
            }

            $note = mb_substr(trim((string) ($data['note'] ?? '')), 0, 2000);
            if ($note !== '') {
                $details['notes'] = array_slice([...(array) ($details['notes'] ?? []), ['on' => $today, 'text' => $note, 'by' => $me->name, 'review' => ! empty($data['review'])]], -100);
            }

            if (array_key_exists('review_on', $data)) {
                $details['review_on'] = $data['review_on'] ?: null;
            }

            $course->forceFill(['physio_details' => $details])->save();

            return $course;
        });
    }

    /** @return list<string> a plan's goals, one per line, comma or semicolon */
    public static function goals(?string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n;,]+/', (string) $text) ?: [])));
    }

    /**
     * Where a problem stands, from what was recorded: the complaint then and
     * now, the goals and which are reached, the notes, the next review, how
     * the patient felt visit by visit, and the sessions.
     *
     * @param  list<array<string, mixed>>  $history
     * @return array<string, mixed>
     */
    private function progressOf(PatientTreatment $course, array $history): array
    {
        $details = (array) ($course->physio_details ?? []);
        $first = ((array) ($details['revisions'] ?? []))[0] ?? $details;
        $done = (array) ($details['goals_done'] ?? []);
        $sessions = array_reverse(array_values(array_filter($history, fn (array $row) => $row['course_id'] === $course->id)));

        return [
            // On the clinic's calendar: recorded at 2:30 am in India is still the day before in UTC.
            'initial' => isset($first['complaint']) ? ['complaint' => $first['complaint'], 'on' => isset($first['recorded_at']) ? ClinicClock::fromUtc(CarbonImmutable::parse((string) $first['recorded_at']))->toDateString() : null] : null,
            'now' => $details['complaint'] ?? null,
            'goals' => array_map(fn (string $goal) => ['text' => $goal, 'done_on' => $done[$goal] ?? null], self::goals($course->goal)),
            'notes' => array_reverse((array) ($details['notes'] ?? [])),
            'review_on' => $details['review_on'] ?? null,
            'feels' => array_map(fn (array $row) => ['date' => $row['date'], 'feel' => $row['feel']], array_slice($sessions, -12)),
            'last_session' => end($sessions)['date'] ?? null,
        ];
    }

    /**
     * Every package of a problem, newest first (spec §20): bought when, used
     * and left, still running or used up, out of date or cancelled, and its
     * bill — paid, due, and the invoice to print.
     *
     * @return list<array<string, mixed>>
     */
    private function packagesOf(PatientTreatment $course): array
    {
        $packages = PatientSessionPackage::query()->withoutGlobalScopes()->where('clinic_id', $course->clinic_id)->where('patient_treatment_id', $course->id)->latest('id')->get();
        $bills = PatientBilling::query()->withoutGlobalScopes()->where('clinic_id', $course->clinic_id)->whereIn('id', $packages->pluck('patient_billing_id')->filter())->get()->keyBy('id');

        return $packages->map(function (PatientSessionPackage $package) use ($bills) {
            $left = max(0, $this->packages->balance($package));
            $bill = $package->patient_billing_id ? $bills->get($package->patient_billing_id) : null;
            $state = $package->status !== PatientSessionPackage::ACTIVE ? $package->status : ($left === 0 ? 'used' : ($package->isExpired() ? 'expired' : 'active'));

            return [
                'id' => $package->id, 'name' => $package->name, 'total' => (int) $package->sessions_total, 'used' => (int) $package->sessions_total - $left, 'left' => $left,
                'state' => $state, 'sold_on' => $package->created_at ? ClinicClock::fromUtc($package->created_at->toImmutable())->toDateString() : null,
                'valid_until' => $package->valid_until?->toDateString(),
                'bill' => $bill ? [
                    'number' => $bill->billing_number, 'amount' => (float) $bill->final_amount, 'paid' => (float) $bill->paid_amount, 'due' => (float) $bill->outstanding_balance,
                    'print' => route('clinic.billing.bills.print', $bill->id),
                ] : null,
            ];
        })->values()->all();
    }

    /**
     * Only the listed choices are kept; the two notes are short text.
     *
     * @param  array<string, mixed>  $input
     * @return array{duration: ?string, severity: ?string, difficulties: list<string>, previous: list<string>, description: ?string, remarks: ?string}
     */
    private function complaint(array $input): array
    {
        $pick = fn (array $options, mixed $value) => is_string($value) && isset($options[$value]) ? $value : null;
        $many = fn (array $options, mixed $values) => array_values(array_intersect(array_keys($options), array_map('strval', (array) $values)));

        return [
            'duration' => $pick(self::DURATIONS, $input['duration'] ?? null),
            'severity' => $pick(self::SEVERITIES, $input['severity'] ?? null),
            'difficulties' => $many(self::DIFFICULTIES, $input['difficulties'] ?? []),
            'previous' => $many(self::PREVIOUS, $input['previous'] ?? []),
            'description' => mb_substr(trim((string) ($input['description'] ?? '')), 0, 2000) ?: null,
            'remarks' => mb_substr(trim((string) ($input['remarks'] ?? '')), 0, 2000) ?: null,
        ];
    }

    /** From now on the course runs at another session type (the patient improved). */
    public function changeType(PatientTreatment $course, string $typeKey): PatientTreatment
    {
        $clinic = Clinic::query()->findOrFail($course->clinic_id);
        $type = $this->catalogue->type($clinic, $course->physio_problem, $typeKey) ?? throw new InvalidArgumentException('Choose a session type of this problem.');
        $course->forceFill(['session_type' => $type['key']])->save();

        return $course;
    }

    /**
     * Finish the visit: the consultation's findings, each started course's
     * session (its type's treatments unless that day was different, its
     * add-ons, its charge), the signature, then the course's next visits.
     *
     * @param  array<string, mixed>  $input
     * @return array{sessions: int, booked: int, booking_problem: ?string}
     */
    public function finish(ClinicalEncounter $encounter, array $input, ClinicEmployee $me, User $by): array
    {
        $clinic = Clinic::query()->findOrFail($encounter->clinic_id);
        $branch = Branch::query()->withoutGlobalScopes()->findOrFail($encounter->branch_id);
        $treatments = $this->catalogue->treatments($clinic);
        $addons = $this->catalogue->addons($clinic);
        $staff = collect($this->therapists($clinic, $branch))->pluck('id')->push($me->id)->unique()->all();
        $consultation = (array) ($input['consultation'] ?? []);
        $rows = collect($input['courses'] ?? [])->filter(fn (array $row) => ! empty($row['done']));
        // A consultation only is enough once a problem is recorded (its complaint and plan say what was found).
        $recorded = PatientTreatment::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('patient_id', $encounter->patient_id)
            ->where('specialty_key', PhysioCareSummary::SPECIALTY)->whereIn('status', TreatmentReadModel::OPEN)->whereNotNull('physio_details')->exists();
        if ($rows->isEmpty() && ! $recorded && trim((string) ($consultation['complaint'] ?? $encounter->chief_complaint)) === '') {
            throw new InvalidArgumentException('Add the patient’s problem, or mark a session done.');
        }

        $sessions = DB::transaction(function () use ($encounter, $consultation, $rows, $treatments, $addons, $staff, $me, $by, $clinic) {
            $workspace = app(EncounterWorkspace::class);
            $fresh = ClinicalEncounter::query()->withoutGlobalScopes()->findOrFail($encounter->id);
            $notes = array_filter(['chief_complaint' => $consultation['complaint'] ?? null, 'history' => $consultation['history'] ?? null,
                'examination' => $consultation['findings'] ?? null, 'follow_up_note' => $consultation['advice'] ?? null], fn ($value) => $value !== null);
            if ($notes !== []) {
                $workspace->autosave($fresh, $notes, (int) $fresh->lock_version);
            }
            $diagnosis = trim((string) ($consultation['diagnosis'] ?? ''));
            if ($diagnosis !== '' && ! $fresh->diagnoses()->withoutGlobalScopes()->where('description', $diagnosis)->exists()) {
                $workspace->addDiagnosis($fresh, ['description' => $diagnosis, 'status' => 'confirmed']);
            }

            $count = 0;
            $titles = [];
            foreach ($rows as $row) {
                $course = PatientTreatment::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('patient_id', $encounter->patient_id)->findOrFail((int) $row['id']);
                if ($course->status !== PatientTreatment::IN_PROGRESS) {
                    throw new DomainException("Start {$course->title} before recording a session.");
                }
                $type = $this->catalogue->type($clinic, $course->physio_problem, $row['type'] ?? $course->session_type)
                    ?? throw new InvalidArgumentException("Choose a session type for {$course->title}.");
                $therapist = (int) ($row['therapist_id'] ?? $me->id);
                if (! in_array($therapist, $staff, true)) {
                    throw new InvalidArgumentException('Choose a therapist of this branch.');
                }

                // The type's treatments, all performed, unless the therapist said otherwise for that day:
                // a plan's treatment is recorded as given only as it was confirmed.
                $keys = is_array($row['given'] ?? null) ? array_column($row['given'], 'key') : $type['treatments'];
                $minutes = is_array($row['given'] ?? null) ? array_column($row['given'], 'minutes', 'key') : [];
                $statuses = is_array($row['given'] ?? null) ? array_column($row['given'], 'status', 'key') : [];
                $given = array_values(array_map(function (string $key) use ($treatments, $minutes, $statuses, $therapist) {
                    $item = $treatments[$key] ?? throw new InvalidArgumentException('That treatment is not on the clinic’s list.');
                    $status = $statuses[$key] ?? self::PERFORMED;

                    return ['key' => $key, 'label' => $item['label'], 'minutes' => max(1, min(180, (int) ($minutes[$key] ?? $item['minutes']))), 'therapist_id' => $therapist,
                        'status' => isset(self::TREATMENT_STATUSES[$status]) ? $status : self::PERFORMED, 'price' => $item['price']];
                }, array_values(array_unique($keys))));
                $done = array_values(array_filter($given, fn (array $item) => $item['status'] !== self::NOT_PERFORMED));
                if ($done === []) {
                    throw new InvalidArgumentException("Mark at least one treatment performed for {$course->title}.");
                }
                $extra = array_values(array_map(function (string $key) use ($addons) {
                    $item = $addons[$key] ?? throw new InvalidArgumentException('That add-on is not on the clinic’s list.');

                    return ['key' => $key, 'label' => $item['label'], 'price' => $item['price']];
                }, array_values(array_unique((array) ($row['addons'] ?? [])))));

                $package = $this->packages->eligible($course)->first();
                $covered = $package && (empty($package['package']->includes) || in_array($type['key'], (array) $package['package']->includes, true));
                // Paid on the day: the type's fixed price, or each treatment given; a package covering the type pays it.
                $visit = $covered ? 0.0 : PhysioCatalogue::visitPrice($type, $treatments, array_column($done, 'key'));
                $charge = $visit + array_sum(array_column($extra, 'price'));

                $session = $this->physio->openSession($course, $encounter, $me, $by);
                // The prescribed exercises as done today: as prescribed unless the therapist said otherwise.
                $prescribed = $session->care_plan_id
                    ? PhysioExercise::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('care_plan_id', $session->care_plan_id)->orderBy('sort_order')->get()
                    : collect();
                $told = collect((array) ($row['exercises'] ?? []))->keyBy('id');
                $performed = $prescribed->map(fn (PhysioExercise $exercise) => [
                    'physio_exercise_id' => $exercise->id,
                    'status' => $told[$exercise->id]['status'] ?? PhysioSessionExercise::PERFORMED,
                    'sets' => $told[$exercise->id]['sets'] ?? $exercise->sets,
                    'reps' => $told[$exercise->id]['reps'] ?? $exercise->reps,
                ])->values()->all();
                $this->physio->saveSession($session, [
                    'modalities' => array_column($done, 'key'),
                    'treatment_performed' => $row['note'] ?? null,
                    'patient_response' => self::FEELS[$row['feel'] ?? ''] ?? null,
                    ...($performed !== [] ? ['exercises' => $performed] : []),
                ], $me, $by);
                $session->forceFill(['given' => $given, 'session_type' => $type['key'], 'addons' => $extra ?: null, 'charge' => $charge])->save();
                $this->physio->completeSession($session->fresh(), $covered ? $package['package']->id : null, $me, $by);

                // Charged per visit, as the owner priced it: one line for a fixed-price type, else one per treatment
                // given — none when the package covers it — and each add-on.
                $visitLines = $covered ? [] : ($type['price'] !== null
                    ? [['name' => "{$course->title} — {$type['label']}", 'price' => $type['price']]]
                    : array_map(fn (array $item) => ['name' => "{$course->title} — {$item['label']}", 'price' => (float) $item['price']], $done));
                $lines = [...array_filter($visitLines, fn (array $line) => $line['price'] > 0),
                    ...array_map(fn (array $item) => ['name' => $item['label'], 'price' => $item['price']], array_filter($extra, fn (array $item) => $item['price'] > 0))];
                foreach ($lines as $line) {
                    app(ClinicalProcedureService::class)->recordProcedure($encounter, [
                        'name' => $line['name'], 'status' => 'completed', 'charged_amount' => $line['price'], 'quantity' => 1,
                        'patient_treatment_id' => $course->id, 'details' => ['physio_session_id' => $session->id],
                    ]);
                }
                $titles[] = $course->title;
                $count++;
            }

            $locked = ClinicalEncounter::query()->withoutGlobalScopes()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();
            if (trim((string) $locked->chief_complaint) === '') {
                // The problems treated today, or (a consultation only) the problems found.
                $titles = $titles ?: $this->courses(Patient::query()->withoutGlobalScopes()->findOrFail($encounter->patient_id))->pluck('title')->all();
                $locked->forceFill(['chief_complaint' => 'Physiotherapy: '.implode(', ', $titles)])->save();
            }
            $workspace->finalize($locked->fresh(), $me);

            return $count;
        });

        [$booked, $problem] = $this->book($clinic, $branch, $encounter, $me, $by, $input['booking'] ?? null);

        return ['sessions' => $sessions, 'booked' => $booked, 'booking_problem' => $problem];
    }

    /** @return Collection<int, PatientTreatment> */
    private function courses(Patient $patient): Collection
    {
        return PatientTreatment::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->where('specialty_key', PhysioCareSummary::SPECIALTY)->whereIn('status', TreatmentReadModel::OPEN)
            ->orderByRaw('case when status = ? then 1 else 0 end', [PatientTreatment::PLANNED])->orderBy('id')->limit(8)->get();
    }

    /**
     * Every completed session of the patient's physiotherapy, newest first:
     * the course, the type and side, what was given and by whom, and money.
     *
     * @param  array<int, string>  $names
     * @return list<array<string, mixed>>
     */
    private function history(Patient $patient, array $names): array
    {
        $clinic = Clinic::query()->findOrFail($patient->clinic_id);
        $modalities = (array) config('clinic_physio.modalities');
        $sessions = PhysioSession::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->where('status', PhysioSession::COMPLETED)->with(['package:id,name,sessions_total', 'treatment' => fn ($q) => $q->withoutGlobalScopes()->select(['id', 'title', 'physio_problem'])])
            ->latest('completed_at')->limit(40)->get();

        $charges = PatientProcedure::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->whereIn('encounter_id', $sessions->pluck('encounter_id')->unique())->whereNotNull('charged_amount')->get(['id', 'charged_amount', 'details'])
            ->groupBy(fn (PatientProcedure $procedure) => (int) ($procedure->details['physio_session_id'] ?? 0));
        $billLines = PatientBillingItem::query()->withoutGlobalScopes()->where('source_type', (new PatientProcedure)->getMorphClass())
            ->whereIn('source_id', $charges->flatten()->pluck('id'))->with(['billing' => fn ($q) => $q->withoutGlobalScopes()->select(['id', 'status', 'outstanding_balance'])])
            ->get(['id', 'source_id', 'patient_billing_id'])->keyBy('source_id');

        // Which session of its package each one was ("3 of 10"), counted over the package's whole use.
        $packageOrder = PhysioSession::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->where('status', PhysioSession::COMPLETED)
            ->whereIn('package_id', $sessions->pluck('package_id')->filter()->unique())->orderBy('completed_at')->orderBy('id')->get(['id', 'package_id'])
            ->groupBy('package_id')->map(fn (Collection $rows) => $rows->pluck('id')->flip());

        // The exercises of each session, as done: done, partly (with how much) or not.
        $performed = PhysioSessionExercise::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->whereIn('physio_session_id', $sessions->pluck('id'))
            ->orderBy('position')->get(['physio_session_id', 'name', 'status', 'sets', 'reps'])->groupBy('physio_session_id');

        return $sessions->map(function (PhysioSession $session) use ($names, $modalities, $charges, $billLines, $clinic, $packageOrder, $performed) {
            $given = (array) ($session->given ?? []);
            $items = $given !== []
                ? array_map(fn (array $row) => ['label' => $row['label'], 'minutes' => $row['minutes'], 'by' => $names[$row['therapist_id'] ?? 0] ?? null, 'status' => $row['status'] ?? self::PERFORMED], $given)
                : array_map(fn (string $key) => ['label' => $modalities[$key] ?? $key, 'minutes' => null, 'by' => null], (array) ($session->modalities ?? []));
            $charged = $charges->get($session->id, collect());
            $bills = $charged->map(fn (PatientProcedure $procedure) => $billLines->get($procedure->id)?->billing)->filter();
            $status = $charged->isEmpty() ? null
                : ($bills->count() < $charged->count() ? 'not_billed'
                    : ($bills->every(fn (PatientBilling $bill) => $bill->status === BillingStatus::Paid || (float) $bill->outstanding_balance <= 0) ? 'paid' : 'due'));
            $total = (float) $charged->sum('charged_amount');

            return [
                'id' => $session->id,
                'date' => $session->completed_at ? ClinicClock::fromUtc($session->completed_at->toImmutable())->toDateString() : null,
                'course_id' => $session->patient_treatment_id,
                'course' => $session->treatment?->title,
                'type' => $this->catalogue->type($clinic, $session->treatment?->physio_problem, $session->session_type)['label'] ?? null,
                'session' => $session->session_number,
                'items' => array_values($items),
                'addons' => array_values(array_column((array) ($session->addons ?? []), 'label')),
                'exercises' => $performed->get($session->id, collect())->map(fn (PhysioSessionExercise $e) => ['name' => $e->name, 'status' => $e->status, 'sets' => $e->sets, 'reps' => $e->reps])->values()->all(),
                'by' => collect($items)->pluck('by')->filter()->unique()->push($names[$session->therapist_id] ?? null)->filter()->unique()->values()->all(),
                'feel' => array_search($session->patient_response, self::FEELS, true) ?: null,
                'note' => $session->treatment_performed,
                'package' => $session->package?->name,
                'package_use' => $session->package ? (($packageOrder[$session->package_id][$session->id] ?? 0) + 1).' of '.$session->package->sessions_total : null,
                'charged' => $total > 0 ? round($total, 2) : null,
                'payment' => $status,
            ];
        })->values()->all();
    }

    /** @return array{visits: int, missed: int, due: float} */
    private function stats(Patient $patient): array
    {
        return [
            'visits' => PhysioSession::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
                ->where('status', PhysioSession::COMPLETED)->distinct()->count('encounter_id'),
            'missed' => Appointment::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
                ->where('status', AppointmentStatus::NoShow->value)->count(),
            'due' => round((float) PatientBilling::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
                ->where('status', '!=', BillingStatus::Cancelled->value)->sum('outstanding_balance'), 2),
        ];
    }

    /** @return list<array{id: int, name: string}> therapists who give physiotherapy at this branch today */
    private function therapists(Clinic $clinic, Branch $branch): array
    {
        return $this->roster->forBranch($clinic, $branch)
            ->filter(fn (array $entry) => $entry['shift'] !== 'on_leave' && in_array(PhysioCareSummary::SPECIALTY, $entry['employee']->practitionerProfile?->specialtyKeys() ?? [], true))
            ->map(fn (array $entry) => ['id' => $entry['employee']->id, 'name' => $entry['employee']->name])->values()->all();
    }

    /**
     * The therapist's free starts for the coming days (clinic time), and the
     * patient's visits already booked with them.
     *
     * @return array{duration: int, days: list<array{date: string, times: list<string>}>, upcoming: list<array{date: string, time: string}>}
     */
    private function slots(Clinic $clinic, Branch $branch, ?ClinicEmployee $me, Patient $patient): array
    {
        $duration = (int) $this->appointmentSettings->resolve($clinic)['default_duration_minutes'];
        if (! $me) {
            return ['duration' => $duration, 'days' => [], 'upcoming' => []];
        }
        $from = ClinicClock::now()->addDay()->startOfDay();
        $free = collect($this->availability->freeSlots($clinic, $branch, [$me->id], $from, self::BOOKING_DAYS, $duration, 1000))
            ->groupBy(fn (array $slot) => substr($slot['start'], 0, 10));

        return [
            'duration' => $duration,
            'days' => collect(range(0, self::BOOKING_DAYS - 1))->map(fn (int $i) => $from->addDays($i)->toDateString())
                ->map(fn (string $date) => ['date' => $date, 'times' => $free->get($date, collect())->map(fn (array $slot) => substr($slot['start'], 11, 5))->values()->all()])
                ->values()->all(),
            'upcoming' => $this->upcoming($clinic, $patient, $me)->map(fn (Appointment $a) => ['date' => $a->scheduled_at->toDateString(), 'time' => $a->scheduled_at->format('H:i')])->values()->all(),
        ];
    }

    /** @return Collection<int, Appointment> the patient's coming visits with this therapist */
    private function upcoming(Clinic $clinic, Patient $patient, ClinicEmployee $me): Collection
    {
        return Appointment::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('patient_id', $patient->id)->where('practitioner_id', $me->id)
            ->where('scheduled_at', '>=', ClinicClock::now()->addDay()->startOfDay())
            ->whereIn('status', array_map(fn (AppointmentStatus $status) => $status->value, AppointmentStatus::awaitingArrival()))
            ->orderBy('scheduled_at')->limit(60)->get();
    }

    /**
     * Book the coming visits by the frequency: from the first day, every
     * `step` days at that time (the next free day when one is not free),
     * until `count` visits are booked — never a day already booked.
     *
     * @param  array{date?: ?string, time?: ?string, frequency?: ?string, count?: ?int}|null  $booking
     * @return array{0: int, 1: ?string}
     */
    private function book(Clinic $clinic, Branch $branch, ClinicalEncounter $encounter, ClinicEmployee $me, User $by, ?array $booking): array
    {
        if (! $booking || empty($booking['date']) || empty($booking['time'])) {
            return [0, null];
        }
        $patient = Patient::query()->withoutGlobalScopes()->findOrFail($encounter->patient_id);
        $duration = (int) $this->appointmentSettings->resolve($clinic)['default_duration_minutes'];
        $first = CarbonImmutable::parse($booking['date'].' '.$booking['time'], ClinicClock::timezone());
        $step = self::FREQUENCIES[$booking['frequency'] ?? '']['step'] ?? 1;
        $count = max(1, min(30, (int) ($booking['count'] ?? 1)));
        $taken = $this->upcoming($clinic, $patient, $me)->map(fn (Appointment $a) => $a->scheduled_at->toDateString())->flip();

        $freeDays = collect($this->availability->freeSlots($clinic, $branch, [$me->id], $first->startOfDay(), min(90, $count * $step + 14), $duration, 4000))
            ->filter(fn (array $slot) => substr($slot['start'], 11, 5) === $first->format('H:i'))
            ->map(fn (array $slot) => substr($slot['start'], 0, 10))->unique()->values();

        $dates = [];
        $next = $first->toDateString();
        foreach ($freeDays as $day) {
            if (count($dates) >= $count) {
                break;
            }
            if ($day >= $next && ! isset($taken[$day])) {
                $dates[] = $day;
                $next = CarbonImmutable::parse($day)->addDays($step)->toDateString();
            }
        }
        if ($dates === []) {
            return [0, 'That time is not free on those days. Book the next visit from the calendar.'];
        }

        $booked = 0;
        foreach ($dates as $date) {
            try {
                app(AppointmentBookingService::class)->book($clinic, $branch, $patient, $me, [
                    'scheduled_at' => CarbonImmutable::parse($date.' '.$first->format('H:i'), ClinicClock::timezone()),
                    'duration_minutes' => $duration, 'appointment_type' => 'follow_up', 'source' => AppointmentSource::Doctor, 'notes' => 'Physiotherapy session',
                ], $by);
                $booked++;
            } catch (Throwable) {
                // A slot taken meanwhile is skipped; the rest still book.
            }
        }

        return [$booked, $booked < $count ? "Booked {$booked} of {$count}: the other days were not free at that time." : null];
    }
}
