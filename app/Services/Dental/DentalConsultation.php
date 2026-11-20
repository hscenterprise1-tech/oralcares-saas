<?php

namespace App\Services\Dental;

use App\AppointmentSource;
use App\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalProcedure;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientDentalChart;
use App\Models\TreatmentPlan;
use App\Models\TreatmentRecord;
use App\Models\User;
use App\Services\Appointments\AppointmentAvailabilityService;
use App\Services\Appointments\AppointmentBookingService;
use App\Services\Appointments\AppointmentSettingsService;
use App\Services\Billing\EncounterCheckout;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\ProcedureCatalogService;
use App\Support\ClinicClock;
use App\TreatmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * The dental visit as one screen: the complaint, what the doctor finds on
 * which tooth, the plan, what was done today, and the next visits. It owns
 * no tables: charting, plans and procedures go through `DentalRecord`, the
 * narrative and signature through `EncounterWorkspace`, and what is billed
 * is whatever the signed visit recorded (`EncounterCheckout`).
 */
class DentalConsultation
{
    public const SPECIALTY = 'dental';

    /** Why the patient came: tapped, then written into the chief complaint. */
    public const COMPLAINTS = [
        'pain' => 'Pain', 'sensitivity' => 'Sensitivity', 'swelling' => 'Swelling', 'bleeding' => 'Bleeding gums', 'broken' => 'Broken tooth',
        'cavity' => 'Cavity', 'cleaning' => 'Cleaning', 'missing' => 'Missing tooth', 'breath' => 'Bad breath', 'braces' => 'Braces', 'checkup' => 'Check-up',
    ];

    private const BOOKING_DAYS = 21;

    /**
     * Treatments that take more than one sitting, as [visits, days between]: the screen offers to book them once the doctor
     * plans them. A service the clinic names differently simply gets none.
     */
    public const SITTINGS = [
        'root canal' => [3, 5], 'rct' => [3, 5], 'pulpectomy' => [2, 5], 'implant' => [3, 14], 'crown' => [2, 7], 'bridge' => [2, 7],
        'veneer' => [2, 7], 'denture' => [3, 7], 'surgical' => [2, 7], 'wisdom' => [2, 7], 'gum' => [2, 7], 'flap' => [2, 7],
    ];

    /** @return array{visits: int, step: int} */
    public static function sittingsOf(?string $text): array
    {
        $text = strtolower((string) $text);
        foreach (self::SITTINGS as $needle => [$visits, $step]) {
            if (str_contains($text, $needle)) {
                return ['visits' => $visits, 'step' => $step];
            }
        }

        return ['visits' => 1, 'step' => 7];
    }

    public function __construct(
        protected DentalRecord $dental,
        protected EncounterWorkspace $workspace,
        protected ProcedureCatalogService $catalogue,
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

        $plans = TreatmentPlan::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('patient_id', $patient->id)
            ->whereIn('status', [TreatmentPlan::PROPOSED, TreatmentPlan::ACCEPTED, TreatmentPlan::ACTIVE])
            ->with(['records' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])->latest('id')->limit(10)->get();

        // Services whose visits the clinic has set itself, by name (a plan item carries the name it was planned under).
        $ownSittings = ClinicalProcedure::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->whereNotNull('metadata')->get(['id', 'name', 'metadata'])
            ->filter(fn (ClinicalProcedure $service) => isset($service->metadata['sittings']))
            ->mapWithKeys(fn (ClinicalProcedure $service) => [strtolower(trim($service->name)) => self::sittingsFor($service)])->all();

        return [
            'consultation' => [
                'complaint' => $encounter->chief_complaint,
                'findings' => $encounter->examination,
                'advice' => $encounter->follow_up_note,
                'diagnoses' => $encounter->diagnoses()->withoutGlobalScopes()->orderByDesc('is_primary')->pluck('description')->all(),
            ],
            'complaints' => collect(self::COMPLAINTS)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values()->all(),
            'teeth' => $this->dental->chart($patient)->map(fn (PatientDentalChart $tooth) => [
                'tooth' => (int) $tooth->tooth_number, 'status' => $tooth->status, 'surfaces' => $tooth->surfaces ?? [], 'notes' => $tooth->notes,
            ])->values()->all(),
            'conditions' => config('clinic_dental.conditions'),
            'surfaces' => config('clinic_dental.surfaces'),
            'services' => $this->services($clinic, $branch),
            // Open plans: what was proposed or accepted on earlier visits and is still to do.
            'plans' => $plans->map(fn (TreatmentPlan $plan) => [
                'id' => $plan->id, 'title' => $plan->title, 'status' => $plan->status,
                'estimate_url' => route('clinic.patients.dental.plans.estimate', [$patient->id, $plan->id]),
                'items' => $plan->records->reject(fn (TreatmentRecord $item) => in_array($item->status, [TreatmentStatus::Completed, TreatmentStatus::Aborted], true))
                    ->map(fn (TreatmentRecord $item) => [
                        'id' => $item->id, 'description' => $item->description, 'tooth' => $item->tooth_number !== null ? (int) $item->tooth_number : null,
                        'surfaces' => $item->surfaces ?? [], 'price' => $item->price !== null ? (float) $item->price : null,
                        ...($ownSittings[strtolower(trim((string) $item->description))] ?? self::sittingsOf($item->description)),
                    ])->values()->all(),
            ])->filter(fn (array $plan) => $plan['items'] !== [])->values()->all(),
            // What this visit has recorded so far (done today), so a reload shows the work.
            'today' => $encounter->procedures()->withoutGlobalScopes()->orderBy('id')->get(['id', 'name', 'quantity', 'details'])
                ->map(fn ($procedure) => ['id' => $procedure->id, 'name' => $procedure->name, 'tooth' => $procedure->details['tooth_number'] ?? null])->values()->all(),
            'history' => $this->history($patient, $encounter),
            'booking' => $this->slots($clinic, $branch, $me, $patient),
            // Root canal, implant, braces and lab work: the patient's records, written in this visit.
            'specialty' => app(DentalSpecialtyView::class)->forVisit($encounter, $patient, $user->can('writeExtension', [$encounter, 'odontogram'])),
            'finished' => $encounter->isFinalized(),
            'to_bill' => $encounter->isFinalized()
                ? round(collect(app(EncounterCheckout::class)->lines($encounter))->sum(fn (array $line) => $line['quantity'] * $line['unit_price']), 2)
                : 0.0,
        ];
    }

    /**
     * Finish the visit: notes, the findings charted, the plan made, the work
     * done today recorded, the visit signed, the next visits booked.
     *
     * @param  array<string, mixed>  $input  consultation{complaint,findings,diagnosis,advice}, findings[{tooth,condition,surfaces}],
     *                                       work[{id?, procedure_id?, tooth?, surfaces?, done}], booking{date,time,step,count}
     * @return array{done: int, planned: int, booked: int, booking_problem: ?string}
     */
    public function finish(ClinicalEncounter $encounter, array $input, ClinicEmployee $me, User $by): array
    {
        $consultation = (array) ($input['consultation'] ?? []);
        $findings = array_values((array) ($input['findings'] ?? []));
        $work = array_values((array) ($input['work'] ?? []));
        $hasComplaint = trim((string) ($consultation['complaint'] ?? $encounter->chief_complaint)) !== '' || trim((string) ($consultation['diagnosis'] ?? '')) !== '';
        if (! $hasComplaint && $work === [] && $findings === []) {
            throw new InvalidArgumentException('Record why the patient came, a finding, or the work done.');
        }

        $counts = DB::transaction(function () use ($encounter, $consultation, $findings, $work, $me) {
            $fresh = ClinicalEncounter::query()->withoutGlobalScopes()->findOrFail($encounter->id);
            $notes = array_filter(['chief_complaint' => $consultation['complaint'] ?? null, 'examination' => $consultation['findings'] ?? null,
                'follow_up_note' => $consultation['advice'] ?? null], fn ($value) => $value !== null);
            if ($notes !== []) {
                $this->workspace->autosave($fresh, $notes, (int) $fresh->lock_version);
            }
            $diagnosis = trim((string) ($consultation['diagnosis'] ?? ''));
            if ($diagnosis !== '' && ! $fresh->diagnoses()->withoutGlobalScopes()->where('description', $diagnosis)->exists()) {
                $this->workspace->addDiagnosis($fresh, ['description' => $diagnosis, 'status' => 'confirmed']);
            }

            foreach ($findings as $finding) {
                $this->dental->recordFinding($fresh->fresh(), (int) $finding['tooth'], (string) $finding['condition'], (array) ($finding['surfaces'] ?? []), $finding['note'] ?? null, $me);
            }

            [$done, $planned] = $this->work($fresh->fresh(), $work, $me);

            $locked = ClinicalEncounter::query()->withoutGlobalScopes()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();
            if (trim((string) $locked->chief_complaint) === '' && ! $locked->diagnoses()->withoutGlobalScopes()->exists()) {
                $locked->forceFill(['chief_complaint' => $done > 0 ? 'Dental treatment' : 'Dental consultation'])->save();
            }
            $this->workspace->finalize($locked->fresh(), $me);

            return [$done, $planned];
        });

        [$booked, $problem] = $this->book($encounter, $me, $by, $input['booking'] ?? null);

        return ['done' => $counts[0], 'planned' => $counts[1], 'booked' => $booked, 'booking_problem' => $problem];
    }

    /**
     * What the doctor plans or did: items of an open plan are done in place;
     * new ones become one plan for this visit, accepted at once (the doctor
     * deciding it at the chair is the patient's consent to plan).
     *
     * @param  list<array<string, mixed>>  $work
     * @return array{0: int, 1: int} done today, left on the plan
     */
    private function work(ClinicalEncounter $encounter, array $work, ClinicEmployee $me): array
    {
        $done = 0;
        $planned = 0;
        $new = array_values(array_filter($work, fn (array $row) => empty($row['id'])));
        $created = collect();
        if ($new !== []) {
            $plan = $this->dental->proposePlan($encounter, 'Visit '.ClinicClock::now()->format('d M Y'), array_map(fn (array $row) => [
                'clinical_procedure_id' => (int) $row['procedure_id'], 'tooth_number' => $row['tooth'] ?? null, 'surfaces' => (array) ($row['surfaces'] ?? []), 'notes' => $row['notes'] ?? null,
            ], $new), null, $me);
            $created = $plan->records()->orderBy('sort_order')->get();
        }

        $newIndex = 0;
        foreach ($work as $row) {
            $item = empty($row['id']) ? $created[$newIndex++] : TreatmentRecord::query()->withoutGlobalScopes()->where('clinic_id', $encounter->clinic_id)
                ->where('patient_id', $encounter->patient_id)->findOrFail((int) $row['id']);
            $plan = TreatmentPlan::query()->withoutGlobalScopes()->findOrFail($item->treatment_plan_id);
            if ($plan->status === TreatmentPlan::PROPOSED) {
                $plan = $this->dental->acceptPlan($plan);
            }
            if (! empty($row['done'])) {
                $this->dental->completeItem($plan, $item->fresh(), $encounter->fresh(), $me, $row['condition'] ?? $this->resultingCondition($item), null);
                $done++;
            } else {
                $planned++;
            }
        }

        return [$done, $planned];
    }

    /**
     * The condition a tooth is left in by a treatment, read from the service: a filling leaves it filled, a root canal root-canal
     * treated, and so on. Null leaves the tooth as charted.
     */
    private function resultingCondition(TreatmentRecord $item): ?string
    {
        $name = strtolower(($item->code ?? '').' '.($item->description ?? ''));

        return match (true) {
            str_contains($name, 'implant') => 'implant',
            str_contains($name, 'root canal'), str_contains($name, 'rct'), str_contains($name, 'pulpectomy') => 'root_canal',
            str_contains($name, 'extraction'), str_contains($name, 'exodontia') => 'extracted',
            str_contains($name, 'crown'), str_contains($name, 'cap') => 'crown',
            str_contains($name, 'veneer') => 'veneer',
            str_contains($name, 'bridge') => 'bridge',
            str_contains($name, 'sealant') => 'sealant',
            str_contains($name, 'filling'), str_contains($name, 'composite'), str_contains($name, 'restoration'), str_contains($name, 'gic'), str_contains($name, 'amalgam') => 'filled',
            default => null,
        };
    }

    /** @return list<array{id: int, code: ?string, name: string, category: ?string, price: ?float}> */
    private function services(Clinic $clinic, Branch $branch): array
    {
        return ClinicalProcedure::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('specialty_key')->orWhere('specialty_key', self::SPECIALTY))
            ->orderBy('name')->limit(500)->get()
            ->map(fn (ClinicalProcedure $service) => [
                'id' => $service->id, 'code' => $service->code, 'name' => $service->name, 'category' => $service->category,
                'price' => $this->catalogue->effectivePrice($clinic->id, $service, $branch->id),
                ...self::sittingsFor($service),
            ])->values()->all();
    }

    /**
     * The visits a service usually takes: the clinic's own setting on the service when it has one,
     * else the standard hint for its name.
     *
     * @return array{visits: int, step: int}
     */
    public static function sittingsFor(ClinicalProcedure $service): array
    {
        $own = $service->metadata['sittings'] ?? null;

        return is_array($own) && isset($own['visits'], $own['step'])
            ? ['visits' => (int) $own['visits'], 'step' => (int) $own['step']]
            : self::sittingsOf($service->name);
    }

    /** @return list<array{id: int, date: ?string, doctor: ?string, complaint: ?string, work: list<string>}> */
    private function history(Patient $patient, ClinicalEncounter $current): array
    {
        return ClinicalEncounter::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->whereKeyNot($current->id)->whereNotNull('finalized_at')->with(['doctor:id,name', 'procedures' => fn ($query) => $query->withoutGlobalScopes()])
            ->latest('started_at')->limit(8)->get()
            ->map(fn (ClinicalEncounter $visit) => [
                'id' => $visit->id, 'date' => $visit->started_at?->toDateString(), 'doctor' => $visit->doctor?->name, 'complaint' => $visit->chief_complaint,
                'work' => $visit->procedures->map(fn ($procedure) => trim($procedure->name.(isset($procedure->details['tooth_number']) ? ' · '.$procedure->details['tooth_number'] : '')))->values()->all(),
            ])->values()->all();
    }

    /** Free days and times for the coming visits, and what is already booked. */
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

    /** @return Collection<int, Appointment> */
    private function upcoming(Clinic $clinic, Patient $patient, ClinicEmployee $me)
    {
        return Appointment::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('patient_id', $patient->id)->where('practitioner_id', $me->id)
            ->where('scheduled_at', '>=', ClinicClock::now()->addDay()->startOfDay())
            ->whereIn('status', array_map(fn (AppointmentStatus $status) => $status->value, AppointmentStatus::awaitingArrival()))
            ->orderBy('scheduled_at')->limit(60)->get();
    }

    /**
     * Book the coming visits: `count` visits, `step` days apart from the first
     * date, at one time; a day already booked or not free is skipped.
     *
     * @param  array{date?: ?string, time?: ?string, step?: ?int, count?: ?int}|null  $booking
     * @return array{0: int, 1: ?string}
     */
    private function book(ClinicalEncounter $encounter, ClinicEmployee $me, User $by, ?array $booking): array
    {
        if (! $booking || empty($booking['date']) || empty($booking['time'])) {
            return [0, null];
        }
        $clinic = Clinic::query()->findOrFail($encounter->clinic_id);
        $branch = Branch::query()->withoutGlobalScopes()->findOrFail($encounter->branch_id);
        $patient = Patient::query()->withoutGlobalScopes()->findOrFail($encounter->patient_id);
        $duration = (int) $this->appointmentSettings->resolve($clinic)['default_duration_minutes'];
        $first = CarbonImmutable::parse($booking['date'].' '.$booking['time'], ClinicClock::timezone());
        $step = max(1, min(60, (int) ($booking['step'] ?? 7)));
        $count = max(1, min(12, (int) ($booking['count'] ?? 1)));
        $taken = $this->upcoming($clinic, $patient, $me)->map(fn (Appointment $a) => $a->scheduled_at->toDateString())->flip();

        $freeDays = collect($this->availability->freeSlots($clinic, $branch, [$me->id], $first->startOfDay(), min(120, $count * $step + 14), $duration, 4000))
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
                    'duration_minutes' => $duration, 'appointment_type' => 'follow_up', 'source' => AppointmentSource::Doctor, 'notes' => 'Dental treatment visit',
                ], $by);
                $booked++;
            } catch (Throwable) {
                // A slot taken meanwhile is skipped; the rest still book.
            }
        }

        return [$booked, $booked < $count ? "Booked {$booked} of {$count}: the other days were not free at that time." : null];
    }
}
