<?php

namespace App\Services\Treatment\Targets;

use App\Models\PatientTreatment;
use App\Models\PatientTreatmentTooth;
use App\Services\DentalChartService;
use InvalidArgumentException;

/**
 * Dental extension target: FDI teeth, with surfaces when they matter.
 * Stored in `patient_treatment_teeth`.
 */
class ToothTarget implements TreatmentTarget
{
    public function __construct(protected DentalChartService $fdi) {}

    public function key(): string
    {
        return 'tooth';
    }

    public function normalize(array $items): array
    {
        return collect($items)->map(function (array $tooth) {
            $number = (int) ($tooth['tooth_number'] ?? 0);
            if (! $this->fdi->isValidFdiToothNumber($number)) {
                throw new InvalidArgumentException("Tooth {$number} is not an FDI tooth number.");
            }
            $surfaces = array_values(array_intersect(array_keys((array) config('clinic_dental.surfaces', [])), array_map('strtoupper', (array) ($tooth['surfaces'] ?? []))));

            return ['tooth_number' => $number, 'surfaces' => $surfaces ?: null];
        })->unique('tooth_number')->values()->all();
    }

    public function store(PatientTreatment $treatment, array $items): void
    {
        foreach ($items as $tooth) {
            PatientTreatmentTooth::query()->withoutGlobalScopes()->create([
                'clinic_id' => $treatment->clinic_id,
                'patient_treatment_id' => $treatment->id,
                'tooth_number' => $tooth['tooth_number'],
                'surfaces' => $tooth['surfaces'],
            ]);
        }
    }

    public function read(PatientTreatment $treatment): array
    {
        return PatientTreatmentTooth::query()->withoutGlobalScopes()->where('patient_treatment_id', $treatment->id)
            ->orderBy('tooth_number')->get(['tooth_number', 'surfaces'])
            ->map(fn (PatientTreatmentTooth $tooth) => ['tooth_number' => $tooth->tooth_number, 'surfaces' => $tooth->surfaces])->all();
    }

    public function readMany(int $clinicId, array $treatmentIds): array
    {
        return PatientTreatmentTooth::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->whereIn('patient_treatment_id', $treatmentIds)
            ->orderBy('tooth_number')->get(['patient_treatment_id', 'tooth_number', 'surfaces'])
            ->groupBy('patient_treatment_id')
            ->map(fn ($teeth) => $teeth->map(fn (PatientTreatmentTooth $tooth) => ['tooth_number' => $tooth->tooth_number, 'surfaces' => $tooth->surfaces])->values()->all())
            ->all();
    }

    public function describe(array $details): ?string
    {
        return ! empty($details['tooth_number']) ? " (tooth {$details['tooth_number']})" : null;
    }
}
