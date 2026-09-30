<?php

namespace App\Services;

// Will alias or use Prescription
use App\Models\ClinicalEncounter;
use App\Models\PatientTimelineEvent;
use App\Models\Prescription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClinicalPrescriptionService
{
    public function issuePrescription(ClinicalEncounter $encounter, array $prescriptionData, array $items): Prescription
    {
        return DB::transaction(function () use ($encounter, $prescriptionData, $items) {
            $prescription = Prescription::create([
                'organization_id' => $encounter->organization_id,
                'encounter_id' => $encounter->id,
                'patient_id' => $encounter->patient_id,
                'prescribed_by' => auth()->id(),
                'prescription_number' => 'RX-'.strtoupper(Str::random(8)),
                'status' => 'active',
                'notes' => $prescriptionData['notes'] ?? null,
                'details' => $prescriptionData['details'] ?? [],
            ]);

            foreach ($items as $item) {
                $prescription->items()->create([
                    'drug_master_id' => $item['drug_master_id'] ?? null,
                    'drug_name' => $item['drug_name'],
                    'dosage' => $item['dosage'],
                    'frequency' => $item['frequency'],
                    'duration' => $item['duration'],
                    'instructions' => $item['instructions'] ?? null,
                    'details' => $item['details'] ?? [],
                ]);
            }

            PatientTimelineEvent::create([
                'organization_id' => $prescription->organization_id,
                'patient_id' => $prescription->patient_id,
                'event_type' => 'prescription_issued',
                'context' => [
                    'prescription_id' => $prescription->id,
                    'encounter_id' => $prescription->encounter_id,
                    'prescription_number' => $prescription->prescription_number,
                    'item_count' => count($items),
                ],
                'actor_id' => auth()->id(),
            ]);

            return $prescription;
        });
    }
}
