<?php

namespace App\Services;

use App\Models\ClinicalEncounter;
use App\Models\PatientProcedure;
use App\Models\PatientTimelineEvent;
use App\Models\ProcedureMaster;
use Illuminate\Validation\ValidationException;

class ClinicalProcedureService
{
    public function recordProcedure(ClinicalEncounter $encounter, array $data): PatientProcedure
    {
        if ($encounter->status === 'completed') {
            throw ValidationException::withMessages([
                'encounter' => 'Cannot record procedures in a completed encounter.',
            ]);
        }

        $code = null;
        $name = $data['name'];
        $description = $data['description'] ?? null;

        if (! empty($data['procedure_master_id'])) {
            $master = ProcedureMaster::findOrFail($data['procedure_master_id']);
            $code = $master->code;
            $name = $master->name;
            $description = $master->description;
        }

        $status = $data['status'] ?? 'planned';

        $procedure = PatientProcedure::create([
            'organization_id' => $encounter->organization_id,
            'encounter_id' => $encounter->id,
            'patient_id' => $encounter->patient_id,
            'procedure_master_id' => $data['procedure_master_id'] ?? null,
            'code' => $data['code'] ?? $code,
            'name' => $name,
            'description' => $description,
            'details' => $data['details'] ?? [],
            'status' => $status,
            'performed_by' => $status === 'completed' ? auth()->id() : null,
            'performed_at' => $status === 'completed' ? now() : null,
        ]);

        PatientTimelineEvent::create([
            'organization_id' => $procedure->organization_id,
            'patient_id' => $procedure->patient_id,
            'event_type' => 'procedure_recorded',
            'context' => [
                'procedure_id' => $procedure->id,
                'encounter_id' => $procedure->encounter_id,
                'name' => $procedure->name,
                'status' => $procedure->status,
            ],
            'actor_id' => auth()->id() ?? null,
        ]);

        return $procedure;
    }

    public function updateStatus(PatientProcedure $procedure, string $status): PatientProcedure
    {
        $updates = ['status' => $status];

        if ($status === 'completed' && $procedure->status !== 'completed') {
            $updates['performed_by'] = auth()->id();
            $updates['performed_at'] = now();
        }

        $procedure->update($updates);

        PatientTimelineEvent::create([
            'organization_id' => $procedure->organization_id,
            'patient_id' => $procedure->patient_id,
            'event_type' => 'procedure_status_updated',
            'context' => [
                'procedure_id' => $procedure->id,
                'new_status' => $status,
                'name' => $procedure->name,
            ],
            'actor_id' => auth()->id() ?? null,
        ]);

        return $procedure;
    }
}
