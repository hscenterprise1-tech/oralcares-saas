<?php

namespace App\Http\Controllers\Clinic\Admin;

use App\Http\Controllers\Concerns\AnswersDomainErrors;
use App\Http\Controllers\Concerns\BindsToActiveClinic;
use App\Http\Controllers\Controller;
use App\Models\CatalogPriceVersion;
use App\Models\ClinicalProcedure;
use App\Services\ClinicContext;
use App\Services\Treatment\TreatmentCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Procedure catalog API (T4). Writes go through `TreatmentCatalog`, which
 * authorises `catalog.manage`, checks the specialty is active and the
 * targets are ones that specialty declares, and audits.
 */
class ProcedureCatalogController extends Controller
{
    use AnswersDomainErrors, BindsToActiveClinic;

    public function __construct(
        protected ClinicContext $clinicContext,
        protected TreatmentCatalog $catalog,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'specialty' => ['nullable', 'string', 'max:50'],
            'active' => ['nullable', 'boolean'],
            'after_id' => ['nullable', 'integer', 'min:0'],
        ]);
        $q = trim((string) ($filters['q'] ?? ''));

        // Keyset pages by id within one clinic's catalog (clinic-leading indexes); prefix search only.
        $rows = ClinicalProcedure::query()->where('clinic_id', $this->clinicContext->clinic()->id)
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('code', 'like', $q.'%')->orWhere('name', 'like', $q.'%')))
            ->when($filters['specialty'] ?? null, fn ($b, $s) => $b->where('specialty_key', $s))
            ->when(array_key_exists('active', $filters), fn ($b) => $b->where('is_active', (bool) $filters['active']))
            ->when($filters['after_id'] ?? null, fn ($b, $id) => $b->where('id', '>', $id))
            ->orderBy('id')->limit(101)
            ->get(['id', 'code', 'name', 'category', 'specialty_key', 'default_duration_minutes', 'is_billable', 'is_bookable', 'is_active', 'metadata']);

        // The clinic-wide price in force for each service on this page, in one query.
        $now = now();
        $prices = CatalogPriceVersion::query()->withoutGlobalScopes()->where('clinic_id', $this->clinicContext->clinic()->id)->whereNull('branch_id')
            ->whereIn('clinical_procedure_id', $rows->take(100)->pluck('id'))->where('effective_from', '<=', $now)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $now))->orderBy('effective_from')->get(['clinical_procedure_id', 'amount'])
            ->mapWithKeys(fn (CatalogPriceVersion $p) => [$p->clinical_procedure_id => (float) $p->amount]);

        return response()->json([
            'data' => $rows->take(100)->map(fn (ClinicalProcedure $p) => [...$this->present($p), 'price' => $prices[$p->id] ?? null])->values(),
            'next_after_id' => $rows->count() > 100 ? $rows->get(99)->id : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, null);

        return $this->attemptJson(fn () => ['data' => $this->present($this->catalog->saveProcedure($this->clinicContext->clinic(), null, $data, $request->user()))], 201);
    }

    public function update(Request $request, ClinicalProcedure $procedure): JsonResponse
    {
        $this->inActiveClinic($procedure);
        $data = $this->validated($request, $procedure);

        return $this->attemptJson(fn () => ['data' => $this->present($this->catalog->saveProcedure($this->clinicContext->clinic(), $procedure, $data, $request->user()))]);
    }

    public function setActive(Request $request, ClinicalProcedure $procedure): JsonResponse
    {
        $this->inActiveClinic($procedure);
        $active = (bool) $request->validate(['active' => ['required', 'boolean']])['active'];

        return $this->attemptJson(function () use ($procedure, $active, $request) {
            $this->catalog->setProcedureActive($procedure, $active, $request->user());

            return ['data' => ['id' => $procedure->id, 'is_active' => $active]];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ClinicalProcedure $procedure): array
    {
        return [
            'id' => $procedure->id, 'code' => $procedure->code, 'name' => $procedure->name, 'category' => $procedure->category,
            'specialty' => $procedure->specialty_key, 'default_duration_minutes' => $procedure->default_duration_minutes,
            'is_billable' => $procedure->is_billable, 'is_bookable' => $procedure->is_bookable, 'is_active' => $procedure->is_active,
            'targets' => array_values((array) (($procedure->metadata ?? [])['targets'] ?? [])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?ClinicalProcedure $procedure): array
    {
        $clinicId = $this->clinicContext->clinic()->id;

        return $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('clinical_procedures', 'code')->where('clinic_id', $clinicId)->ignore($procedure?->id)],
            'name' => ['required', 'string', 'max:160'],
            'category' => [$procedure ? 'sometimes' : 'required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:2000'],
            'specialty_key' => ['nullable', 'string', 'max:50'],
            'default_duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'is_billable' => ['nullable', 'boolean'],
            'is_bookable' => ['nullable', 'boolean'],
            'targets' => ['nullable', 'array', 'max:5'],
            'targets.*' => ['string', 'max:30'],
        ]);
    }
}
