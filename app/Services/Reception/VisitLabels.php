<?php

namespace App\Services\Reception;

use App\Models\Clinic;
use App\Models\ClinicSpecialty;
use App\Models\PatientVisit;
use App\Services\Clinical\ClinicalCatalogue;
use App\Support\Clinical\DefaultClinicalCatalogue;
use App\Support\Specialty\SpecialtyRegistry;

/**
 * Why a patient came today, as words: the visit's specialty and type labels
 * and the reason Reception wrote. Read once per request for a clinic (one
 * small query and the cached catalogue), then applied to any number of
 * visits — the Front Desk and a doctor's Today read visits the same way.
 */
class VisitLabels
{
    /** @var array<int, array{specialties: array<int, string>, types: array<string, string>}> */
    private array $memo = [];

    public function __construct(
        protected ClinicalCatalogue $catalogue,
        protected SpecialtyRegistry $specialties,
    ) {}

    /**
     * @return array{specialty_key: ?string, specialty: ?string, type_key: ?string, type: ?string, reason: ?string}
     */
    public function for(Clinic $clinic, ?PatientVisit $visit, ?string $fallbackSpecialty = null, ?string $fallbackReason = null): array
    {
        $labels = $this->labels($clinic);
        $key = ($visit?->clinic_specialty_id ? ($labels['specialties'][$visit->clinic_specialty_id] ?? null) : null) ?? $fallbackSpecialty;

        return [
            'specialty_key' => $key,
            'specialty' => $key ? ($this->specialties->get($key)['label'] ?? str($key)->headline()->toString()) : null,
            'type_key' => $visit?->visit_type_key,
            'type' => $visit?->visit_type_key ? ($labels['types'][$visit->visit_type_key] ?? null) : null,
            'reason' => $visit?->visit_reason ?? $fallbackReason,
        ];
    }

    /**
     * @return array{specialties: array<int, string>, types: array<string, string>}
     */
    private function labels(Clinic $clinic): array
    {
        return $this->memo[$clinic->id] ??= [
            'specialties' => ClinicSpecialty::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->get(['id', 'specialty_key'])
                ->mapWithKeys(fn (ClinicSpecialty $s) => [$s->id => $this->specialties->normalize([$s->specialty_key])[0] ?? $s->specialty_key])->all(),
            'types' => collect($this->catalogue->effective($clinic->id, DefaultClinicalCatalogue::KIND_VISIT_TYPE, null, true))
                ->mapWithKeys(fn (array $type) => [$type['key'] => $type['name']])->all(),
        ];
    }
}
