<?php

namespace App\Services;

use App\Events\DiagnosisRecorded;
use App\Models\ClinicalDiagnosis;
use App\Models\ClinicalEncounter;

class DiagnosisService
{
    /**
     * Record a new diagnosis for a patient during an encounter.
     */
    public function recordDiagnosis(
        ClinicalEncounter $encounter,
        string $description,
        ?string $code = null,
        ?string $notes = null
    ): ClinicalDiagnosis {
        $diag = ClinicalDiagnosis::create([
            'encounter_id' => $encounter->id,
            'patient_id' => $encounter->patient_id,
            'code' => $code,
            'description' => $description,
            'notes' => $notes,
        ]);

        event(new DiagnosisRecorded($diag));

        return $diag;
    }
}
