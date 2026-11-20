<?php

namespace App\Http\Controllers\Clinic\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CatalogPriceVersion;
use App\Models\CatalogTreatmentVersion;
use App\Models\ClinicalProcedure;
use App\Services\ClinicContext;
use App\Services\Dental\DentalConsultation;
use App\Services\Treatment\ServiceLibrary;
use App\Support\Specialty\SpecialtyRegistry;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administration → Services & treatments: the screen over the catalogue
 * API (services, treatments with their versioned stages, versioned prices).
 * Only the specialties the clinic has (its plan sells and it practises) are
 * offered; every write goes through the catalogue API and its services.
 */
class CatalogPageController extends Controller
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected SpecialtyRegistry $specialties,
    ) {}

    public function index(): Response
    {
        $clinic = $this->clinicContext->clinic() ?? abort(403);
        $targets = (array) config('clinic_specialties.targets', []);

        return Inertia::render('ClinicPortal/Admin/Catalog', [
            'specialties' => collect($this->specialties->forClinic($clinic))->map(fn (string $key) => [
                'key' => $key,
                'label' => $this->specialties->get($key)['label'] ?? $key,
                // What a treatment of this specialty may be aimed at (teeth, for dental).
                'targets' => collect($this->specialties->get($key)['targets'] ?? [])->map(fn (string $t) => ['key' => $t, 'label' => $targets[$t]['label'] ?? ucfirst($t)])->values(),
            ])->values(),
            'branches' => Branch::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->orderBy('name')->get(['id', 'name']),
            'treatment_types' => CatalogTreatmentVersion::TYPES,
            'charging_models' => CatalogTreatmentVersion::CHARGING_MODELS,
            'services' => $this->services($clinic->id),
            'library' => app(ServiceLibrary::class)->missingFor($clinic),
            'api' => [
                'library' => route('admin.catalog.procedures.library', [], false),
                'procedures' => route('admin.catalog.procedures.index', [], false),
                'treatments' => route('admin.catalog.treatments.index', [], false),
                'prices' => route('admin.catalog.prices.index', [], false),
            ],
        ]);
    }

    /**
     * Every service of the clinic with its clinic-wide price in force.
     *
     * @return list<array<string, mixed>>
     */
    private function services(int $clinicId): array
    {
        $now = now();
        $prices = CatalogPriceVersion::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->whereNull('branch_id')
            ->whereNotNull('clinical_procedure_id')->where('effective_from', '<=', $now)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $now))
            ->orderBy('effective_from')->get(['clinical_procedure_id', 'amount'])
            ->mapWithKeys(fn (CatalogPriceVersion $p) => [$p->clinical_procedure_id => (float) $p->amount]);

        return ClinicalProcedure::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)
            ->orderBy('category')->orderBy('name')->limit(1000)
            ->get(['id', 'code', 'name', 'category', 'specialty_key', 'default_duration_minutes', 'is_billable', 'is_bookable', 'is_active', 'metadata'])
            ->map(fn (ClinicalProcedure $p) => [
                'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'category' => $p->category, 'specialty' => $p->specialty_key,
                'duration' => $p->default_duration_minutes, 'bookable' => (bool) $p->is_bookable, 'billable' => (bool) $p->is_billable,
                'active' => (bool) $p->is_active, 'price' => $prices[$p->id] ?? null,
                'usual_sittings' => DentalConsultation::sittingsOf($p->name),
                'sittings' => isset($p->metadata['sittings']) ? ['visits' => (int) $p->metadata['sittings']['visits'], 'step' => (int) $p->metadata['sittings']['step']] : null,
                'update_url' => route('admin.catalog.procedures.update', $p->id, false),
                'active_url' => route('admin.catalog.procedures.active', $p->id, false),
            ])->values()->all();
    }
}
