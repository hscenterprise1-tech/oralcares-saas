<?php

namespace App\Services\Dental;

use App\AppointmentSource;
use App\AppointmentStatus;
use App\ClinicPermission;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalProcedure;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientClinicalEntry;
use App\Models\PatientDentalChart;
use App\Models\TreatmentPlan;
use App\Models\TreatmentRecord;
use App\Models\TreatmentRecordSitting;
use App\Models\User;
use App\Services\Appointments\AppointmentAvailabilityService;
use App\Services\Appointments\AppointmentBookingService;
use App\Services\Appointments\AppointmentSettingsService;
use App\Services\Billing\EncounterCheckout;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\Clinical\PrescriptionAllergyCheck;
use App\Services\ClinicalPrescriptionService;
use App\Services\ProcedureCatalogService;
use App\Services\Treatment\LegacyTreatmentMirror;
use App\Support\Clinical\DefaultClinicalCatalogue;
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

    /**
     * The visits a treatment is made of, by its service name: what each does and the days until the next.
     *
     * @return list<array{name: string, days: int}>
     */
    public static function stagesOf(?string $text): array
    {
        $text = strtolower((string) $text);
        foreach ((array) config('clinic_dental.stages') as $needle => $stages) {
            if (str_contains($text, (string) $needle)) {
                return array_map(fn (array $stage) => ['name' => $stage[0], 'days' => (int) $stage[1]], $stages);
            }
        }

        return [];
    }

    /**
     * The visit names a clinic gave its own services (`metadata.sittings.stages`), by lower-cased service name.
     *
     * @return array<string, list<string>>
     */
    public static function ownStageNames(int $clinicId): array
    {
        return ClinicalProcedure::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->whereNotNull('metadata')->get(['name', 'metadata'])
            ->filter(fn (ClinicalProcedure $service) => ! empty($service->metadata['sittings']['stages']))
            ->mapWithKeys(fn (ClinicalProcedure $service) => [strtolower(trim($service->name)) => array_values(array_map('strval', (array) $service->metadata['sittings']['stages']))])->all();
    }

    /** @return array{visits: int, step: int} */
    public static function sittingsOf(?string $text): array
    {
        if (($stages = self::stagesOf($text)) !== []) {
            return ['visits' => count($stages), 'step' => max(1, $stages[0]['days'])];
        }
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
            ->with(['records' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'), 'records.sittings'])->latest('id')->limit(10)->get();

        $ownStages = self::ownStageNames($clinic->id);
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
            // Treatments already started on earlier visits: where each stands, so the doctor resumes without searching.
            'continuing' => $plans->flatMap(fn (TreatmentPlan $plan) => $plan->records)
                ->filter(fn (TreatmentRecord $item) => $item->sittings->isNotEmpty() && ! in_array($item->status, [TreatmentStatus::Completed, TreatmentStatus::Aborted], true))
                ->map(fn (TreatmentRecord $item) => ['id' => $item->id, 'label' => $item->description, 'tooth' => $item->tooth_number !== null ? (int) $item->tooth_number : null, ...$this->progress($item, $ownStages)])
                ->values()->all(),
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
                        ...$this->progress($item, $ownStages),
                    ])->values()->all(),
            ])->filter(fn (array $plan) => $plan['items'] !== [])->values()->all(),
            // What this visit has recorded so far (done today), so a reload shows the work.
            'today' => $encounter->procedures()->withoutGlobalScopes()->orderBy('id')->get(['id', 'name', 'quantity', 'details'])
                ->map(fn ($procedure) => ['id' => $procedure->id, 'name' => $procedure->name, 'tooth' => $procedure->details['tooth_number'] ?? null])->values()->all(),
            'history' => $this->history($patient, $encounter),
            'booking' => $this->slots($clinic, $branch, $me, $patient),
            // Root canal, implant, braces and lab work: the patient's records, written in this visit.
            'specialty' => app(DentalSpecialtyView::class)->forVisit($encounter, $patient, $user->can('writeExtension', [$encounter, 'odontogram'])),
            // What the doctor must not miss, always on screen: documented allergies and the patient's active conditions.
            'alerts' => $this->alerts($patient),
            'suggestions' => config('clinic_dental.suggestions'),
            'rx' => [
                'can_prescribe' => $user->hasClinicPermission(ClinicPermission::PrescriptionManage),
                'templates' => config('clinic_dental.prescription_templates'),
                'allergy' => app(PrescriptionAllergyCheck::class)->stateFor($patient),
                'issued' => ($issued = $encounter->prescriptions()->withoutGlobalScopes()->where('status', 'finalized')->latest('id')->first(['id', 'prescription_number']))
                    ? ['number' => $issued->prescription_number, 'print_url' => route('clinic.prescriptions.print', $issued->id)] : null,
            ],
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

        $prescription = (array) ($input['prescription'] ?? []);
        $counts = DB::transaction(function () use ($encounter, $consultation, $findings, $work, $me, $by, $prescription) {
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
            // Written before the visit is signed: signing settles any draft left open.
            $this->prescribe($fresh->fresh(), $prescription, $me, $by);

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
     * Where a multi-visit treatment stands: its stages with done / next / later, and what the doctor wrote last time.
     *
     * @return array{sittings_total: ?int, sittings_done: int, next_stage: ?string, last_note: ?string, last_date: ?string, stages: list<array{name: string, state: string}>}
     */
    private function progress(TreatmentRecord $item, array $ownStages = []): array
    {
        $done = $item->sittings->count();
        $names = $ownStages[strtolower(trim((string) $item->description))] ?? array_column(self::stagesOf($item->description), 'name');
        $total = $item->sittings_total !== null ? (int) $item->sittings_total : (count($names) > 1 ? count($names) : null);
        $stages = [];
        for ($i = 1; $i <= max($total ?? 0, $done); $i++) {
            $stages[] = ['name' => $names[$i - 1] ?? 'Sitting '.$i, 'state' => $i <= $done ? 'done' : ($i === $done + 1 ? 'next' : 'later')];
        }
        $last = $item->sittings->last();

        return [
            'sittings_total' => $total,
            'sittings_done' => $done,
            'next_stage' => collect($stages)->firstWhere('state', 'next')['name'] ?? null,
            'last_note' => $last?->note,
            'last_date' => $last?->done_at?->toDateString(),
            'stages' => $stages,
        ];
    }

    /**
     * @return list<array{kind: string, label: string}>
     */
    private function alerts(Patient $patient): array
    {
        $allergies = collect(app(PrescriptionAllergyCheck::class)->stateFor($patient)['allergies'])
            ->map(fn (array $allergy) => ['kind' => 'allergy', 'label' => $allergy['label']]);
        $conditions = PatientClinicalEntry::withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->where('kind', DefaultClinicalCatalogue::KIND_CONDITION)->where('status', PatientClinicalEntry::ACTIVE)->orderBy('label')->limit(8)->pluck('label')
            ->map(fn (string $label) => ['kind' => 'condition', 'label' => $label]);

        return $allergies->concat($conditions)->values()->all();
    }

    /**
     * The prescription written on the visit screen, issued with the visit. Where the patient has documented allergies the doctor
     * must have said they reviewed them (the same rule as the prescription studio); the review is recorded against the
     * allergy list the screen showed.
     *
     * @param  array<string, mixed>  $prescription  items[{drug_name, dosage, frequency, duration, instructions?}], allergy_fingerprint?
     */
    private function prescribe(ClinicalEncounter $encounter, array $prescription, ClinicEmployee $me, User $by): void
    {
        $items = array_values(array_filter((array) ($prescription['items'] ?? []), fn ($line) => trim((string) ($line['drug_name'] ?? '')) !== ''));
        if ($items === []) {
            return;
        }
        if (! $by->hasClinicPermission(ClinicPermission::PrescriptionManage)) {
            throw new InvalidArgumentException('You cannot write prescriptions at this clinic.');
        }

        $service = app(ClinicalPrescriptionService::class);
        $draft = $service->draftFor($encounter, $me);
        foreach ($items as $line) {
            $service->addItem($draft, $line);
        }
        $state = $service->allergyState($draft);
        if ($state['status'] === PrescriptionAllergyCheck::DOCUMENTED) {
            $service->reviewAllergies($draft, $by, (string) ($prescription['allergy_fingerprint'] ?? ''));
        }
        $service->finalize($draft->fresh(), $me);
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
            // A treatment of several visits remembers how many it was planned for, from the first time it is worked on or planned.
            $visits = max(0, min(8, (int) ($row['visits'] ?? 0)));
            if ($item->sittings_total === null && $visits > 1) {
                $item->update(['sittings_total' => $visits]);
            }
            if (! empty($row['done'])) {
                if ((int) $item->fresh()->sittings_total > 1) {
                    $this->sitting($plan, $item->fresh(), $encounter->fresh(), $me, $row);
                } else {
                    $this->dental->completeItem($plan, $item->fresh(), $encounter->fresh(), $me, $row['condition'] ?? $this->resultingCondition($item), null);
                }
                $done++;
            } else {
                $planned++;
            }
        }

        return [$done, $planned];
    }

    /**
     * Today's visit of a treatment that takes several: recorded once per consultation, billed its share of the price (the last
     * visit takes the remainder, so the shares always add up to the price), and the treatment closes on its last visit.
     *
     * @param  array<string, mixed>  $row
     */
    private function sitting(TreatmentPlan $plan, TreatmentRecord $item, ClinicalEncounter $encounter, ClinicEmployee $me, array $row): void
    {
        if ($item->sittings()->where('encounter_id', $encounter->id)->exists()) {
            return;
        }
        $total = (int) $item->sittings_total;
        $number = $item->sittings()->count() + 1;
        $names = self::ownStageNames((int) $item->clinic_id)[strtolower(trim((string) $item->description))] ?? array_column(self::stagesOf($item->description), 'name');
        $stage = $names[$number - 1] ?? 'Sitting '.$number;
        $final = $number >= $total;
        $price = $item->price !== null ? (float) $item->price : null;
        $share = $price === null ? null : round($price / $total, 2);
        $charged = $price === null ? null : ($final ? round($price - $share * ($total - 1), 2) : $share);
        $note = trim((string) ($row['sitting_note'] ?? '')) ?: null;
        $label = "{$stage}, sitting {$number} of {$total}".($note ? '. '.$note : '');

        if ($final) {
            $this->dental->completeItem($plan, $item, $encounter, $me, $row['condition'] ?? $this->resultingCondition($item), $note ? "{$stage}. {$note}" : $stage, $charged);
            $procedureId = $item->fresh()->patient_procedure_id;
        } else {
            $procedureId = $this->dental->recordSitting($plan, $item, $encounter, $me, $label, $charged)->id;
        }

        TreatmentRecordSitting::query()->create([
            'clinic_id' => $item->clinic_id, 'treatment_record_id' => $item->id, 'encounter_id' => $encounter->id, 'patient_procedure_id' => $procedureId,
            'number' => $number, 'stage' => $stage, 'note' => $note, 'charged' => $charged, 'done_at' => now(),
        ]);
        // The treatment lists (today's patients, Patient 360) read the mirrored treatment: bring its visit count up to date.
        app(LegacyTreatmentMirror::class)->mirrorRecord($item->fresh(), $plan->fresh());
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
                'stages' => ! empty($service->metadata['sittings']['stages']) ? array_values(array_map('strval', (array) $service->metadata['sittings']['stages'])) : array_column(self::stagesOf($service->name), 'name'),
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
