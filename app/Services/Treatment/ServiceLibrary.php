<?php

namespace App\Services\Treatment;

use App\Models\Clinic;
use App\Models\ClinicalProcedure;
use App\Models\User;
use App\Support\Specialty\SpecialtyRegistry;

/**
 * The ready-made list of services for the clinic's specialties
 * (`config/clinic_onboarding.php` → `catalogues`), so an owner picks the ones
 * the clinic offers instead of typing a menu. Nothing is added unless the
 * owner picks it; each pick is created through the catalogue's own writer
 * and priced by `CatalogPricing`, so it is audited like any other service.
 */
class ServiceLibrary
{
    public function __construct(
        protected SpecialtyRegistry $specialties,
        protected TreatmentCatalog $catalog,
        protected CatalogPricing $pricing,
    ) {}

    /**
     * Library services the clinic does not have yet.
     *
     * @return list<array{code: string, name: string, category: string, duration: int, price: int, specialty: string|null}>
     */
    public function missingFor(Clinic $clinic): array
    {
        $have = ClinicalProcedure::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->pluck('code')->all();

        return array_values(array_filter($this->forClinic($clinic), fn (array $item) => ! in_array($item['code'], $have, true)));
    }

    /**
     * @param  list<string>  $codes  the library codes the owner picked
     * @return int how many services were added
     */
    public function import(Clinic $clinic, array $codes, User $by): int
    {
        $wanted = array_flip($codes);
        $added = 0;

        foreach ($this->missingFor($clinic) as $item) {
            if (! isset($wanted[$item['code']])) {
                continue;
            }
            $procedure = $this->catalog->saveProcedure($clinic, null, [
                'code' => $item['code'],
                'name' => $item['name'],
                'category' => $item['category'],
                'specialty_key' => $item['specialty'],
                'default_duration_minutes' => $item['duration'],
            ], $by);
            $this->pricing->set($clinic, 'clinical_procedure_id', $procedure->id, (float) $item['price'], null, $by);
            $added++;
        }

        return $added;
    }

    /**
     * @return list<array{code: string, name: string, category: string, duration: int, price: int, specialty: string|null}>
     */
    private function forClinic(Clinic $clinic): array
    {
        $active = $this->specialties->forClinic($clinic);
        $templates = (array) config('clinic_onboarding.specialty_templates', []);
        $catalogues = [];
        foreach ($active as $specialty) {
            $template = $templates[$specialty] ?? null;
            foreach ((array) config("clinic_onboarding.templates.{$template}.options.catalogues", []) as $catalogue) {
                $catalogues[$catalogue] = true;
            }
        }

        $items = [];
        foreach (array_keys($catalogues) as $catalogue) {
            foreach ((array) config("clinic_onboarding.catalogues.{$catalogue}", []) as $row) {
                $items[$row['code']] ??= [
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'category' => $row['category'],
                    'duration' => (int) $row['duration'],
                    'price' => (int) $row['price'],
                    'specialty' => in_array($catalogue, $active, true) ? $catalogue : null,
                ];
            }
        }

        return array_values($items);
    }
}
