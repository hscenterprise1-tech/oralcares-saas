<?php

namespace App\Services\Treatment;

use App\ClinicPermission;
use App\Models\ClinicalDiagnosis;
use App\Models\ClinicalEncounter;
use App\Models\ClinicRoom;
use App\Models\Patient;
use App\Models\PatientSessionPackage;
use App\Models\PatientTreatment;
use App\Models\PhysioCarePlan;
use App\Models\PhysioExercise;
use App\Models\PhysioSession;
use App\Models\PhysioSessionExercise;
use App\Models\SessionPackageUsage;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\Patient\ClinicSpecialties;
use App\Services\Patient\PatientBranchAccess;
use App\Support\Specialty\SpecialtyRegistry;
use Illuminate\Support\Collection;

/**
 * The treatments panel of a consultation: every open treatment of the
 * patient as its own card — its diagnosis and goal, its plan of care, its
 * packages with the balance from the ledger, and its session in THIS
 * consultation — so the doctor works on Knee and Back side by side without
 * leaving the visit. Nothing is merged across treatments and nothing is
 * inferred: each card carries its own `patient_treatment_id` and links.
 *
 * Read only; every write goes through the 4E.2 writers (`PatientTreatments`,
 * `PhysioRecord`, `SessionPackages`). Batched: a fixed number of queries
 * whatever the number of treatments, and at most `LIMIT` cards.
 */
class ConsultationCare
{
    public const LIMIT = 20;

    public function __construct(
        protected TreatmentReadModel $readModel,
        protected PatientBranchAccess $branchAccess,
        protected SpecialtyRegistry $specialties,
        protected ClinicSpecialties $clinicSpecialties,
        protected TreatmentAccess $access,
        protected ClinicContext $clinicContext,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(ClinicalEncounter $encounter, Patient $patient, User $user, bool $editable): array
    {
        $clinicId = $encounter->clinic_id;
        $todaysSessions = PhysioSession::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)
            ->where('encounter_id', $encounter->id)->whereNotNull('patient_treatment_id')
            ->with(['performedExercises' => fn ($q) => $q->withoutGlobalScopes()])->get()->keyBy('patient_treatment_id');

        // Open treatments, plus any treatment worked on in this consultation even if it has since closed.
        $rows = $this->readModel->query($clinicId, $this->branchAccess->branchIds())->where('patient_id', $patient->id)
            ->where(fn ($q) => $q->whereIn('status', TreatmentReadModel::OPEN)->orWhereIn('id', $todaysSessions->keys()->all() ?: [0]))
            ->with(['practitioner:id,name'])
            ->orderByRaw("case when status in ('planned','in_progress','paused') then 0 else 1 end")
            ->orderByDesc('id')->limit(self::LIMIT + 1)->get();
        $more = $rows->count() > self::LIMIT;
        $rows = $rows->take(self::LIMIT);
        $ids = $rows->pluck('id')->all();

        $presented = collect($this->readModel->present($rows, false))->keyBy('id');
        $diagnoses = ClinicalDiagnosis::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)
            ->whereIn('id', $rows->pluck('primary_diagnosis_id')->filter()->unique()->values()->all() ?: [0])
            ->get(['id', 'code', 'description'])->keyBy('id');
        $plans = PhysioCarePlan::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->whereIn('patient_treatment_id', $ids ?: [0])
            ->where('status', PhysioCarePlan::ACTIVE)
            ->with(['exercises' => fn ($q) => $q->withoutGlobalScopes()->where('is_active', true)->orderBy('sort_order')->orderBy('id')])
            ->get()->keyBy('patient_treatment_id');
        $packages = $this->packages($clinicId, $ids);
        $nextNumbers = PhysioSession::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->whereIn('patient_treatment_id', $ids ?: [0])
            ->groupBy('patient_treatment_id')->selectRaw('patient_treatment_id, max(session_number) as last')->pluck('last', 'patient_treatment_id');

        $canExecute = $editable && $user->hasClinicPermission(ClinicPermission::TreatmentExecute) && $user->hasClinicPermission(ClinicPermission::ClinicalEdit);
        $canSell = $user->hasClinicPermission(ClinicPermission::BillingCreate);
        // Treatment work is done by a practitioner of its specialty (as TreatmentAccess enforces): a dentist sees a physiotherapy card, not its session buttons.
        $practised = $this->clinicContext->currentEmployee()?->practitionerProfile?->specialtyKeys() ?? [];

        $cards = $rows->map(function (PatientTreatment $treatment) use ($presented, $diagnoses, $plans, $packages, $todaysSessions, $nextNumbers, $encounter, $canExecute, $practised) {
            $session = $todaysSessions->get($treatment->id);
            $plan = $plans->get($treatment->id);
            $diagnosis = $treatment->primary_diagnosis_id ? $diagnoses->get($treatment->primary_diagnosis_id) : null;
            $physio = $this->runsSessions($treatment->specialty_key);

            $card = $presented->get($treatment->id);
            $card['stages'] = array_map(fn (array $stage) => [...$stage, 'links' => [
                'start' => route('clinic.treatments.stages.start', [$treatment, $stage['id']]),
                'complete' => route('clinic.treatments.stages.complete', [$treatment, $stage['id']]),
                'procedures' => route('clinic.treatments.stages.procedures.store', [$treatment, $stage['id']]),
            ]], $card['stages']);

            return [
                ...$card,
                'specialty_label' => $treatment->specialty_key ? ($this->specialties->get($treatment->specialty_key)['label'] ?? null) : null,
                'goal' => $treatment->goal,
                'diagnosis' => $diagnosis ? ['id' => $diagnosis->id, 'code' => $diagnosis->code, 'description' => $diagnosis->description] : null,
                'runs_sessions' => $physio,
                'plan' => $plan ? $this->plan($plan) : null,
                'packages' => $packages->get($treatment->id, collect())->values()->all(),
                'session' => $session ? $this->session($session) : null,
                'next_session_number' => ((int) ($nextNumbers[$treatment->id] ?? 0)) + 1,
                'links' => [
                    'open_session' => route('clinic.treatments.sessions.store', $treatment),
                    'session' => $session ? route('clinic.treatments.sessions.update', [$treatment, $session]) : null,
                    'complete_session' => $session ? route('clinic.treatments.sessions.complete', [$treatment, $session]) : null,
                    'reverse_session' => $session ? route('clinic.treatments.sessions.reverse', [$treatment, $session]) : null,
                    'start_plan' => route('clinic.treatments.plans.store', $treatment),
                    'revise_plan' => $plan ? route('clinic.treatments.plans.revise', [$treatment, $plan]) : null,
                    'sell_package' => route('clinic.treatments.packages.store', $treatment),
                    'context' => route('clinic.treatments.context', $treatment),
                    'detail' => route('clinic.treatments.show', $treatment),
                ],
                'encounter_id' => $encounter->id,
                'can' => ['execute' => $canExecute && in_array($treatment->specialty_key, $practised, true)],
            ];
        })->values()->all();

        return [
            'treatments' => $cards,
            'more' => $more,
            'can' => [
                'execute' => $canExecute,
                'start' => $editable && $user->hasClinicPermission(ClinicPermission::TreatmentStart),
                'sell_package' => $editable && $canSell,
            ],
            'start' => [
                'url' => route('clinic.consultations.treatments.store', $encounter),
                'options' => route('clinic.patients.treatments.options', $patient->id),
                'specialties' => $this->startableSpecialties($encounter, $user),
            ],
            'rooms' => ClinicRoom::query()->where('branch_id', $encounter->branch_id)->where('is_active', true)->orderBy('name')->limit(100)->get(['id', 'name'])->values(),
            'modalities' => config('clinic_physio.modalities'),
        ];
    }

    /** A specialty whose treatments are delivered as documented sessions (physiotherapy). */
    public function runsSessions(?string $specialtyKey): bool
    {
        return $specialtyKey !== null && in_array('soap_notes', $this->specialties->get($specialtyKey)['extensions'] ?? [], true);
    }

    /**
     * The specialties this user may start a treatment in here: practised by
     * the clinic and, for a practitioner, by them.
     *
     * @return list<array{key: string, label: string, runs_sessions: bool, tooth: bool}>
     */
    private function startableSpecialties(ClinicalEncounter $encounter, User $user): array
    {
        $clinic = $encounter->clinic()->withoutGlobalScopes()->first();
        if (! $clinic) {
            return [];
        }
        $active = $this->clinicSpecialties->activeKeys($clinic);
        $mine = $this->access->boardSpecialties($user);
        $keys = array_values(array_intersect($mine ?? $active, $active));
        // The consultation's own specialty first.
        usort($keys, fn (string $a, string $b) => [$a !== $encounter->specialty, $a] <=> [$b !== $encounter->specialty, $b]);

        return array_map(fn (string $key) => [
            'key' => $key,
            'label' => $this->specialties->get($key)['label'] ?? str($key)->headline()->toString(),
            'runs_sessions' => $this->runsSessions($key),
            'tooth' => in_array('tooth', $this->specialties->get($key)['targets'] ?? [], true),
        ], $keys);
    }

    /**
     * Each treatment's packages, the balance read from the ledger (never the
     * cached counters), and whether a session may be paid from it now.
     *
     * @param  list<int>  $treatmentIds
     * @return Collection<int, Collection<int, array<string, mixed>>>
     */
    private function packages(int $clinicId, array $treatmentIds): Collection
    {
        $packages = PatientSessionPackage::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)
            ->whereIn('patient_treatment_id', $treatmentIds ?: [0])
            ->whereIn('status', [PatientSessionPackage::ACTIVE, PatientSessionPackage::EXHAUSTED])
            ->orderBy('valid_until')->orderBy('id')->limit(200)->get();
        $ledger = SessionPackageUsage::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)
            ->whereIn('package_id', $packages->pluck('id')->all() ?: [0])
            ->groupBy('package_id')->selectRaw('package_id, sum(quantity) as total')->pluck('total', 'package_id');

        return $packages->map(function (PatientSessionPackage $package) use ($ledger) {
            $remaining = $package->sessions_total + (int) ($ledger[$package->id] ?? 0);
            $expired = $package->isExpired();
            $eligible = $package->status === PatientSessionPackage::ACTIVE && ! $expired && $remaining > 0;

            return [
                'id' => $package->id,
                'patient_treatment_id' => $package->patient_treatment_id,
                'name' => $package->name,
                'status' => $package->status,
                'sessions_total' => $package->sessions_total,
                'remaining' => $remaining,
                // What completing one session against it would leave: the server's figure, not the screen's.
                'remaining_after' => $eligible ? $remaining - 1 : null,
                'valid_until' => $package->valid_until?->toDateString(),
                'expired' => $expired,
                'eligible' => $eligible,
            ];
        })->groupBy('patient_treatment_id');
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(PhysioCarePlan $plan): array
    {
        return [
            'id' => $plan->id,
            'revision' => $plan->revision,
            'title' => $plan->title,
            'goals' => $plan->goals,
            'planned_sessions' => $plan->planned_sessions,
            'frequency' => $plan->frequency,
            'interventions' => $plan->interventions ?? [],
            'effective_from' => $plan->effective_from?->toDateString(),
            'exercises' => $plan->exercises->map(fn (PhysioExercise $e) => $e->only(['id', 'name', 'sets', 'reps', 'hold_seconds', 'frequency', 'instructions', 'is_home']))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function session(PhysioSession $session): array
    {
        return [
            ...$session->only(['id', 'status', 'session_number', 'care_plan_id', 'subjective', 'objective', 'assessment', 'plan', 'pain_before', 'pain_after', 'modalities', 'room_id', 'package_id', 'treatment_performed', 'patient_response', 'home_program', 'next_step', 'reversal_reason']),
            'completed_at' => $session->completed_at?->toIso8601String(),
            'reversed_at' => $session->reversed_at?->toIso8601String(),
            'exercises' => $session->performedExercises->map(fn (PhysioSessionExercise $e) => $e->only(['id', 'physio_exercise_id', 'name', 'status', 'sets', 'reps', 'duration_seconds', 'intensity', 'notes']))->values()->all(),
        ];
    }
}
