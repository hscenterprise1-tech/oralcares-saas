<?php

namespace App\Http\Controllers\Clinic\Admin;

use App\Http\Controllers\Concerns\AnswersDomainErrors;
use App\Http\Controllers\Concerns\BindsToActiveClinic;
use App\Http\Controllers\Controller;
use App\Models\CatalogPriceVersion;
use App\Models\CatalogStage;
use App\Models\CatalogStageProcedure;
use App\Models\CatalogTreatment;
use App\Models\CatalogTreatmentVersion;
use App\Services\ClinicContext;
use App\Services\Treatment\TreatmentCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Treatment catalog API (T1–T3): list, create, versions, drafts, publish,
 * activate. Reading needs `catalog.manage`, `treatment.start` or
 * `billing.view` (route); every write is authorised and validated by
 * `TreatmentCatalog` (`catalog.manage`).
 */
class TreatmentCatalogController extends Controller
{
    use AnswersDomainErrors, BindsToActiveClinic;

    public function __construct(
        protected ClinicContext $clinicContext,
        protected TreatmentCatalog $catalog,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['specialty' => ['nullable', 'string', 'max:50'], 'active' => ['nullable', 'boolean']]);
        $treatments = CatalogTreatment::query()->where('clinic_id', $this->clinicContext->clinic()->id)
            ->when($filters['specialty'] ?? null, fn ($q, $s) => $q->where('specialty_key', $s))
            ->when(array_key_exists('active', $filters), fn ($q) => $q->where('is_active', (bool) $filters['active']))
            ->withCount('versions')->with('currentVersion:id,version,treatment_type,charging_model,settings')
            ->orderBy('name')->limit(500)->get();

        return response()->json(['data' => $treatments->map(fn (CatalogTreatment $t) => [
            'id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'specialty' => $t->specialty_key, 'category' => $t->category,
            'is_active' => $t->is_active, 'versions' => $t->versions_count,
            'current_version' => $t->currentVersion ? [
                'id' => $t->currentVersion->id, 'version' => $t->currentVersion->version, 'treatment_type' => $t->currentVersion->treatment_type,
                'charging_model' => $t->currentVersion->charging_model, 'targets' => $t->currentVersion->targets(),
            ] : null,
        ])->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'specialty_key' => ['required', 'string', 'max:50'],
            'code' => ['required', 'string', 'max:40', Rule::unique('catalog_treatments', 'code')->where('clinic_id', $this->clinicContext->clinic()->id)],
            'name' => ['required', 'string', 'max:160'],
            'category' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:2000'],
            'required_capability' => ['nullable', 'string', 'max:60'],
        ]);

        return $this->attemptJson(fn () => ['data' => $this->catalog->createTreatment($this->clinicContext->clinic(), $data, $request->user())->only(['id', 'code', 'name', 'specialty_key', 'required_capability'])], 201);
    }

    public function show(CatalogTreatment $treatment): JsonResponse
    {
        $this->inActiveClinic($treatment);
        $versions = $treatment->versions()->with(['stages.procedures.procedure:id,code,name'])->get();
        $prices = $this->clinicWidePrices($treatment->clinic_id, $versions);

        return response()->json(['data' => [
            'id' => $treatment->id, 'code' => $treatment->code, 'name' => $treatment->name, 'specialty' => $treatment->specialty_key, 'required_capability' => $treatment->required_capability,
            'category' => $treatment->category, 'description' => $treatment->description, 'is_active' => $treatment->is_active,
            'current_version_id' => $treatment->current_version_id,
            'versions' => $versions->map(fn (CatalogTreatmentVersion $v) => [
                'id' => $v->id, 'version' => $v->version, 'status' => $v->status, 'treatment_type' => $v->treatment_type,
                'charging_model' => $v->charging_model, 'targets' => $v->targets(), 'planned_sessions' => $v->planned_sessions,
                'validity_days' => $v->validity_days, 'default_duration_minutes' => $v->default_duration_minutes, 'published_at' => $v->published_at?->toIso8601String(), 'retired_at' => $v->retired_at?->toIso8601String(),
                'price' => $v->charging_model === 'upfront' ? ($prices['v'.$v->id] ?? null) : null,
                'stages' => $v->stages->map(fn (CatalogStage $s) => [
                    'id' => $s->id, 'position' => $s->position, 'name' => $s->name, 'description' => $s->description, 'duration_minutes' => $s->duration_minutes, 'is_optional' => $s->is_optional, 'planned_sessions' => $s->planned_sessions,
                    'price' => $v->charging_model === 'per_stage' ? ($prices['s'.$s->id] ?? null) : null,
                    'procedures' => $s->procedures->map(fn (CatalogStageProcedure $p) => [
                        'clinical_procedure_id' => $p->clinical_procedure_id, 'code' => $p->procedure?->code, 'name' => $p->procedure?->name,
                        'quantity' => $p->quantity, 'is_required' => $p->is_required,
                    ])->values(),
                ])->values(),
            ])->values(),
        ]]);
    }

    public function storeVersion(Request $request, CatalogTreatment $treatment): JsonResponse
    {
        $this->inActiveClinic($treatment);
        if ($request->filled('from_version_id')) {
            $source = $treatment->versions()->whereKey((int) $request->input('from_version_id'))->firstOrFail();

            return $this->attemptJson(fn () => ['data' => ['id' => $this->catalog->newVersionFrom($source, $request->user())->id]], 201);
        }

        return $this->attemptJson(fn () => ['data' => ['id' => $this->catalog->draftVersion($treatment, $this->config($request), $request->user())->id]], 201);
    }

    public function updateVersion(Request $request, CatalogTreatment $treatment, CatalogTreatmentVersion $version): JsonResponse
    {
        $this->inActiveClinic($treatment, $version);
        abort_unless($version->catalog_treatment_id === $treatment->id, 404);

        return $this->attemptJson(fn () => ['data' => ['id' => $this->catalog->updateDraft($version, $this->config($request), $request->user())->id]]);
    }

    public function publish(Request $request, CatalogTreatment $treatment, CatalogTreatmentVersion $version): JsonResponse
    {
        $this->inActiveClinic($treatment, $version);
        abort_unless($version->catalog_treatment_id === $treatment->id, 404);

        return $this->attemptJson(fn () => ['data' => $this->catalog->publish($version, $request->user())->only(['id', 'version', 'status'])]);
    }

    /** Limit the treatment to practitioners with one practice-area capability, or lift the limit (null). */
    public function setCapability(Request $request, CatalogTreatment $treatment): JsonResponse
    {
        $this->inActiveClinic($treatment);
        $capability = $request->validate(['required_capability' => ['present', 'nullable', 'string', 'max:60']])['required_capability'];

        return $this->attemptJson(fn () => ['data' => $this->catalog->setRequiredCapability($treatment, $capability, $request->user())->only(['id', 'required_capability'])]);
    }

    public function setActive(Request $request, CatalogTreatment $treatment): JsonResponse
    {
        $this->inActiveClinic($treatment);
        $active = (bool) $request->validate(['active' => ['required', 'boolean']])['active'];

        return $this->attemptJson(function () use ($treatment, $active, $request) {
            $this->catalog->setActive($treatment, $active, $request->user());

            return ['data' => ['id' => $treatment->id, 'is_active' => $active]];
        });
    }

    /**
     * The clinic-wide price in force for every version and stage shown, in one
     * query (branch overrides are on the pricing history endpoint).
     *
     * @param  Collection<int, CatalogTreatmentVersion>  $versions
     * @return array<string, array<string, mixed>>
     */
    private function clinicWidePrices(int $clinicId, Collection $versions): array
    {
        $versionIds = $versions->pluck('id')->all();
        $stageIds = $versions->flatMap(fn (CatalogTreatmentVersion $v) => $v->stages->pluck('id'))->all();
        if ($versionIds === []) {
            return [];
        }
        $now = now();

        return CatalogPriceVersion::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->whereNull('branch_id')
            ->where('effective_from', '<=', $now)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $now))
            ->where(fn ($q) => $q->whereIn('catalog_treatment_version_id', $versionIds)->when($stageIds !== [], fn ($q) => $q->orWhereIn('catalog_stage_id', $stageIds)))
            ->orderBy('effective_from')->get()
            ->mapWithKeys(fn (CatalogPriceVersion $p) => [
                ($p->catalog_treatment_version_id ? 'v'.$p->catalog_treatment_version_id : 's'.$p->catalog_stage_id) => $p->only(['id', 'amount', 'effective_from']),
            ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function config(Request $request): array
    {
        return $request->validate([
            'treatment_type' => ['required', Rule::in(CatalogTreatmentVersion::TYPES)],
            'charging_model' => ['required', Rule::in(CatalogTreatmentVersion::CHARGING_MODELS)],
            'targets' => ['nullable', 'array', 'max:5'],
            'targets.*' => ['string', 'max:30'],
            'planned_sessions' => ['nullable', 'integer', 'min:1', 'max:500'],
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'default_duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'stages' => ['required', 'array', 'min:1', 'max:50'],
            'stages.*.name' => ['required', 'string', 'max:160'],
            'stages.*.description' => ['nullable', 'string', 'max:2000'],
            'stages.*.planned_sessions' => ['nullable', 'integer', 'min:1', 'max:500'],
            'stages.*.duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'stages.*.is_optional' => ['nullable', 'boolean'],
            'stages.*.procedures' => ['nullable', 'array', 'max:50'],
            'stages.*.procedures.*.clinical_procedure_id' => ['required', 'integer'],
            'stages.*.procedures.*.quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'stages.*.procedures.*.is_required' => ['nullable', 'boolean'],
        ]);
    }
}
