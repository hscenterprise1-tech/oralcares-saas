<?php

namespace App\Services\Treatment;

use App\Models\CatalogPriceVersion;
use App\Models\CatalogTreatment;
use App\Models\CatalogTreatmentVersion;
use App\Models\Patient;
use App\Models\PatientProcedure;
use App\Models\PatientTreatment;
use App\Models\PatientTreatmentStage;
use App\Services\Clinical\ClinicalContextResolver;
use App\Services\Treatment\Targets\TreatmentTargets;
use App\Support\Specialty\PracticeAreas;
use App\Support\Specialty\SpecialtyRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How patient treatments are read: Active Care, a patient's Treatments, the
 * treatment detail, the clinic treatment board and the directory's active-care
 * column all use this one presentation. Batched — one query for treatments,
 * one for their stages, one per declared target — never per row.
 *
 * Amounts are included only for callers that may see money; the caller
 * decides (`$withAmounts`). Branch limits come from `PatientBranchAccess`.
 */
class TreatmentReadModel
{
    public function __construct(protected TreatmentTargets $targets) {}

    /** Open = planned, in progress or paused. */
    public const OPEN = [PatientTreatment::PLANNED, PatientTreatment::IN_PROGRESS, PatientTreatment::PAUSED];

    /**
     * @param  list<int>|null  $branchIds
     */
    public function query(int $clinicId, ?array $branchIds): Builder
    {
        return PatientTreatment::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)
            ->when($branchIds !== null, fn (Builder $q) => $q->where(fn (Builder $b) => $b->whereIn('branch_id', $branchIds ?: [0])->orWhereNull('branch_id')));
    }

    /**
     * A patient's treatments (open first, then most recent), optionally only
     * the given ones (e.g. those naming one tooth).
     *
     * @param  list<int>|null  $branchIds
     * @param  list<int>|null  $onlyIds
     * @return list<array<string, mixed>>
     */
    public function forPatient(int $clinicId, int $patientId, ?array $branchIds, bool $openOnly, bool $withAmounts, int $limit = 50, ?array $onlyIds = null): array
    {
        if ($onlyIds === []) {
            return [];
        }
        $rows = $this->query($clinicId, $branchIds)->where('patient_id', $patientId)
            ->when($openOnly, fn (Builder $q) => $q->whereIn('status', self::OPEN))
            ->when($onlyIds !== null, fn (Builder $q) => $q->whereIn('id', $onlyIds))
            ->with(['practitioner:id,name'])
            ->orderByRaw("case when status in ('planned','in_progress','paused') then 0 else 1 end")
            ->latest('id')->limit($limit)->get();

        return $this->present($rows, $withAmounts);
    }

    /**
     * A patient's open treatments as separate care streams, one per module
     * and practice area ("Dental · Orthodontics", "Physiotherapy · Sports
     * Rehabilitation"), so unrelated care is never merged into one list.
     *
     * A treatment's area is the one its visit was resolved to at check-in;
     * else its practitioner's area within the treatment's module; else the
     * area its specialty is named after; else the module alone.
     *
     * @param  list<int>|null  $branchIds
     * @return list<array{key: string, module: array{key: string, label: string}|null, area: array{key: string, label: string}|null, label: string, specialty: ?string, section: string, treatments: list<array{id: int, title: string, status: string, practitioner: ?string, progress: array{done: int, total: ?int, unit: string}}>}>
     */
    public function careStreams(int $clinicId, int $patientId, ?array $branchIds, int $limit = 12): array
    {
        $rows = $this->query($clinicId, $branchIds)->where('patient_id', $patientId)->whereIn('status', self::OPEN)
            ->with(['practitioner' => fn ($q) => $q->withoutGlobalScopes()->select(['id', 'name']), 'practitioner.practitionerProfile' => fn ($q) => $q->withoutGlobalScopes()])
            ->latest('id')->limit($limit)->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $areas = app(PracticeAreas::class);
        $registry = app(SpecialtyRegistry::class);
        app(ClinicalContextResolver::class)->loadProfiles($rows->map(fn (PatientTreatment $t) => $t->practitioner?->practitionerProfile)->filter()->unique('id')->values());
        $visitAreas = DB::table('clinical_encounters as ce')->join('patient_visits as pv', 'pv.id', '=', 'ce.patient_visit_id')
            ->where('ce.clinic_id', $clinicId)->whereIn('ce.id', $rows->pluck('encounter_id')->filter()->unique()->all() ?: [0])
            ->whereNotNull('pv.practice_area_key')->pluck('pv.practice_area_key', 'ce.id');

        $streams = [];
        foreach ($rows->sortBy('id') as $t) {
            $module = $areas->moduleOfSpecialty($t->specialty_key);
            $inModule = fn (?string $key) => $key !== null && $areas->get($key) !== null && $areas->get($key)['module'] === $module ? $key : null;
            $area = $inModule($visitAreas[$t->encounter_id] ?? null)
                ?? collect($t->practitioner?->practitionerProfile?->practiceAreaKeys() ?? [])->first(fn (string $key) => $inModule($key) !== null)
                ?? $inModule($areas->match($t->specialty_key));
            $key = ($module ?? 'care').':'.($area ?? '');

            $streams[$key] ??= [
                'key' => $key,
                'module' => $module ? ['key' => $module, 'label' => $areas->moduleLabel($module)] : null,
                'area' => $area ? ['key' => $area, 'label' => $areas->get($area)['label']] : null,
                'label' => $area ? $areas->contextLabel($area) : ($areas->moduleLabel($module) ?? ($registry->get((string) $t->specialty_key)['label'] ?? 'Treatment')),
                'specialty' => $t->specialty_key,
                'section' => $areas->module($module)['care_section'] ?? 'treatment',
                'treatments' => [],
            ];
            $streams[$key]['treatments'][] = [
                'id' => $t->id,
                'title' => $t->title,
                'status' => $t->status,
                'practitioner' => $t->practitioner?->name,
                'progress' => $this->progress($t),
            ];
        }

        return array_values($streams);
    }

    /**
     * Open treatments for a page of patients, keyed by patient id.
     *
     * @param  list<int>  $patientIds
     * @param  list<int>|null  $branchIds
     * @return array<int, list<array{id: int, title: string, specialty: ?string, status: string, progress: array{done: int, total: ?int, unit: string}}>>
     */
    public function activeCareFor(int $clinicId, array $patientIds, ?array $branchIds): array
    {
        if ($patientIds === []) {
            return [];
        }

        return $this->query($clinicId, $branchIds)->whereIn('patient_id', $patientIds)->whereIn('status', self::OPEN)
            ->orderBy('id')->get(['id', 'patient_id', 'title', 'specialty_key', 'status', 'treatment_type', 'stages_total', 'stages_completed', 'sessions_planned', 'sessions_completed'])
            ->groupBy('patient_id')
            ->map(fn (Collection $treatments) => $treatments->map(fn (PatientTreatment $t) => [
                'id' => $t->id, 'title' => $t->title, 'specialty' => $t->specialty_key, 'status' => $t->status, 'progress' => $this->progress($t),
            ])->values()->all())->all();
    }

    /**
     * @param  Collection<int, PatientTreatment>  $rows
     * @return list<array<string, mixed>>
     */
    public function present(Collection $rows, bool $withAmounts): array
    {
        if ($rows->isEmpty()) {
            return [];
        }
        $stages = PatientTreatmentStage::query()->withoutGlobalScopes()->whereIn('patient_treatment_id', $rows->pluck('id'))
            ->orderBy('position')->get()->groupBy('patient_treatment_id');
        $targets = $this->targets->readMany($rows);

        return $rows->map(function (PatientTreatment $t) use ($stages, $targets, $withAmounts) {
            $own = $stages->get($t->id, collect());
            $next = $own->first(fn (PatientTreatmentStage $s) => ! $s->isClosed());

            return [
                'id' => $t->id,
                'patient_id' => $t->patient_id,
                'title' => $t->title,
                'code' => $t->code,
                'specialty' => $t->specialty_key,
                'source' => $t->source,
                'status' => $t->status,
                'status_reason' => $t->status_reason,
                'treatment_type' => $t->treatment_type,
                'charging_model' => $t->charging_model,
                'branch_id' => $t->branch_id,
                'practitioner' => $t->practitioner ? ['id' => $t->practitioner->id, 'name' => $t->practitioner->name] : null,
                'progress' => $this->progress($t),
                'next_stage' => $next ? ['id' => $next->id, 'name' => $next->name, 'status' => $next->status] : null,
                'stages' => $own->map(fn (PatientTreatmentStage $s) => [
                    'id' => $s->id, 'position' => $s->position, 'name' => $s->name, 'status' => $s->status, 'is_optional' => $s->is_optional,
                    'sessions' => $s->sessions_planned !== null ? ['done' => $s->sessions_completed, 'planned' => $s->sessions_planned] : null,
                    'completed_at' => $s->completed_at?->toIso8601String(),
                    ...($withAmounts ? ['amount' => $s->agreed_amount !== null ? (float) $s->agreed_amount : null] : []),
                ])->values()->all(),
                'targets' => $targets[$t->id] ?? [],
                'valid_until' => $t->valid_until?->toDateString(),
                'started_at' => $t->started_at?->toIso8601String(),
                'completed_at' => $t->completed_at?->toIso8601String(),
                ...($withAmounts ? ['agreed_amount' => $t->agreed_amount !== null ? (float) $t->agreed_amount : null, 'currency' => $t->currency] : []),
            ];
        })->values()->all();
    }

    /**
     * One treatment with its recorded procedures (T8). The caller has already
     * authorised it (`TreatmentAccess::assertCanView`).
     *
     * @return array<string, mixed>
     */
    public function detail(PatientTreatment $treatment, bool $withAmounts): array
    {
        $treatment->loadMissing(['practitioner:id,name']);
        $procedures = PatientProcedure::query()->withoutGlobalScopes()->where('clinic_id', $treatment->clinic_id)->where('patient_treatment_id', $treatment->id)
            ->latest('id')->limit(500)->get(['id', 'encounter_id', 'patient_treatment_stage_id', 'clinical_procedure_id', 'code', 'name', 'quantity', 'details', 'performed_at', 'charged_amount']);

        return [
            ...$this->present(collect([$treatment]), $withAmounts)[0],
            'catalog_treatment_version_id' => $treatment->catalog_treatment_version_id,
            'encounter_id' => $treatment->encounter_id,
            'cancelled_at' => $treatment->cancelled_at?->toIso8601String(),
            'procedures' => $procedures->map(fn (PatientProcedure $p) => [
                'id' => $p->id, 'encounter_id' => $p->encounter_id, 'stage_id' => $p->patient_treatment_stage_id,
                'clinical_procedure_id' => $p->clinical_procedure_id, 'code' => $p->code, 'name' => $p->name, 'quantity' => $p->quantity,
                'description' => $this->targets->describeProcedure($p), 'performed_at' => $p->performed_at?->toIso8601String(),
                ...($withAmounts ? ['charged_amount' => $p->charged_amount !== null ? (float) $p->charged_amount : null] : []),
            ])->values()->all(),
        ];
    }

    /**
     * The clinic treatment board (T9): keyset pages, newest first, with the
     * patient's name batched. `$specialties` null = every specialty;
     * a list limits a clinician to what they practise.
     *
     * @param  list<int>|null  $branchIds
     * @param  list<string>|null  $specialties
     * @param  array{status?: ?string, specialty?: ?string, practitioner_id?: ?int}  $filters
     * @return array{data: list<array<string, mixed>>, next_cursor: ?string, per_page: int}
     */
    public function board(int $clinicId, ?array $branchIds, ?array $specialties, array $filters, ?string $cursor, bool $withAmounts, int $perPage = 25): array
    {
        $perPage = max(1, min($perPage, 100));
        $status = $filters['status'] ?? null;
        $page = $this->query($clinicId, $branchIds)
            ->when($status === null || $status === 'open', fn (Builder $q) => $q->whereIn('status', self::OPEN), fn (Builder $q) => $q->where('status', $status))
            ->when($specialties !== null, fn (Builder $q) => $q->whereIn('specialty_key', $specialties ?: ['']))
            ->when(filled($filters['specialty'] ?? null), fn (Builder $q) => $q->where('specialty_key', $filters['specialty']))
            ->when(filled($filters['practitioner_id'] ?? null), fn (Builder $q) => $q->where('practitioner_id', (int) $filters['practitioner_id']))
            ->with(['practitioner:id,name'])
            ->orderByDesc('id')
            ->cursorPaginate($perPage, ['*'], 'cursor', $cursor);

        $rows = collect($page->items());
        $patients = Patient::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->whereIn('id', $rows->pluck('patient_id')->unique())
            ->get(['id', 'uhid', 'first_name', 'middle_name', 'last_name', 'name'])->keyBy('id');

        return [
            'data' => collect($this->present($rows, $withAmounts))->map(fn (array $t) => [
                ...$t,
                'patient' => ($p = $patients->get($t['patient_id'])) ? ['id' => $p->id, 'uhid' => $p->uhid, 'name' => $p->full_name] : null,
            ])->values()->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'per_page' => $perPage,
        ];
    }

    /**
     * What can be started now: current published versions of active
     * treatments in these specialties, with the clinic-wide or branch price
     * in force for an upfront treatment. Three queries in all.
     *
     * @param  list<string>  $specialties
     * @return list<array<string, mixed>>
     */
    public function startable(int $clinicId, array $specialties, ?int $branchId, bool $withAmounts): array
    {
        if ($specialties === []) {
            return [];
        }
        $treatments = CatalogTreatment::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->where('is_active', true)
            ->whereIn('specialty_key', $specialties)->whereNotNull('current_version_id')
            ->with(['currentVersion' => fn ($q) => $q->withoutGlobalScopes()->where('status', CatalogTreatmentVersion::PUBLISHED)])
            ->orderBy('name')->limit(500)->get()->filter(fn (CatalogTreatment $t) => $t->currentVersion !== null);

        $now = now();
        $prices = $withAmounts && $treatments->isNotEmpty()
            ? CatalogPriceVersion::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)
                ->whereIn('catalog_treatment_version_id', $treatments->pluck('current_version_id'))
                ->where(fn ($q) => $q->whereNull('branch_id')->when($branchId, fn ($q) => $q->orWhere('branch_id', $branchId)))
                ->where('effective_from', '<=', $now)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $now))
                ->get()->sortBy(fn (CatalogPriceVersion $p) => [$p->branch_id !== null, $p->effective_from])
                ->keyBy('catalog_treatment_version_id')
            : collect();

        return $treatments->map(fn (CatalogTreatment $t) => [
            'catalog_treatment_id' => $t->id,
            'version_id' => $t->currentVersion->id,
            'code' => $t->code,
            'name' => $t->name,
            'specialty' => $t->specialty_key,
            'category' => $t->category,
            'treatment_type' => $t->currentVersion->treatment_type,
            'charging_model' => $t->currentVersion->charging_model,
            'targets' => collect($t->currentVersion->targets())->map(fn (string $key) => ['key' => $key, 'label' => $this->targets->label($key)])->values()->all(),
            ...($withAmounts ? ['price' => $t->currentVersion->charging_model === 'upfront' ? (($p = $prices->get($t->currentVersion->id)) ? (float) $p->amount : null) : null] : []),
        ])->values()->all();
    }

    /**
     * @return array{done: int, total: ?int, unit: string}
     */
    public function progress(PatientTreatment $t): array
    {
        return $t->treatment_type === 'session_based'
            ? ['done' => (int) $t->sessions_completed, 'total' => $t->sessions_planned, 'unit' => 'sessions']
            : ['done' => (int) $t->stages_completed, 'total' => (int) $t->stages_total, 'unit' => 'stages'];
    }
}
