<?php

namespace App\Services;

use App\Models\TreatmentMaterial;
use App\Models\TreatmentProgressNote;
use App\Models\TreatmentRecord;

class ClinicalProgressService
{
    /**
     * Log a clinical progress note during treatment execution.
     */
    public function logProgress(TreatmentRecord $txRecord, string $event, ?string $notes = null): TreatmentProgressNote
    {
        return TreatmentProgressNote::create([
            'treatment_record_id' => $txRecord->id,
            'event' => $event,
            'notes' => $notes,
            'logged_at' => now(),
        ]);
    }

    /**
     * Map a material component for treatment consumption.
     */
    public function addMaterial(
        TreatmentRecord $txRecord,
        string $materialName,
        float $quantity = 1.00,
        ?string $batch = null
    ): TreatmentMaterial {
        return TreatmentMaterial::create([
            'treatment_record_id' => $txRecord->id,
            'material_name' => $materialName,
            'quantity' => $quantity,
            'batch_number' => $batch,
        ]);
    }
}
