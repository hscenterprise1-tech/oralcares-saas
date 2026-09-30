<?php

namespace App\Services;

use App\Models\ClinicalDiagnosis;
use App\Models\ClinicalEncounter;
use App\Models\DiagnosisMaster;
use App\Models\PatientTimelineEvent;
use Illuminate\Validation\ValidationException;

class ClinicalDiagnosisService
{
    public function addDiagnosis(ClinicalEncounter $encounter, array $data): ClinicalDiagnosis
    {
        if ($encounter->status === 'completed') {
            throw ValidationException::withMessages([
                'encounter' => 'Cannot add diagnoses to a completed encounter.',
            ]);
        }

        $codingSystem = null;
        $code = null;
        $description = $data['description'];

        if (! empty($data['diagnosis_master_id'])) {
            $master = DiagnosisMaster::findOrFail($data['diagnosis_master_id']);
            $codingSystem = $master->category; // or however coding system is defined
            $code = $master->code;
            $description = $master->name;
        }

        $diagnosis = ClinicalDiagnosis::create([
            'organization_id' => $encounter->organization_id,
            'encounter_id' => $encounter->id,
            'patient_id' => $encounter->patient_id,
            'diagnosis_master_id' => $data['diagnosis_master_id'] ?? null,
            'coding_system' => $data['coding_system'] ?? $codingSystem,
            'code' => $data['code'] ?? $code,
            'description' => $description,
            'details' => $data['details'] ?? [],
            'status' => $data['status'] ?? 'active',
            'diagnosed_by' => auth()->id(),
        ]);

        PatientTimelineEvent::create([
            'organization_id' => $diagnosis->organization_id,
            'patient_id' => $diagnosis->patient_id,
            'event_type' => 'diagnosis_added',
            'context' => [
                'diagnosis_id' => $diagnosis->id,
                'encounter_id' => $diagnosis->encounter_id,
                'description' => $diagnosis->description,
            ],
            'actor_id' => auth()->id() ?? null,
        ]);

        return $diagnosis;
    }

    public function updateStatus(ClinicalDiagnosis $diagnosis, string $status): ClinicalDiagnosis
    {
        $diagnosis->update(['status' => $status]);

        PatientTimelineEvent::create([
            'organization_id' => $diagnosis->organization_id,
            'patient_id' => $diagnosis->patient_id,
            'event_type' => 'diagnosis_status_updated',
            'context' => [
                'diagnosis_id' => $diagnosis->id,
                'new_status' => $status,
            ],
            'actor_id' => auth()->id() ?? null,
        ]);

        return $diagnosis;
    }
}
