<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\ClinicalEncounter;
use App\Models\DoctorProfile;
use App\Models\Patient;
use App\Models\PatientTimelineEvent;
use Illuminate\Auth\Access\AuthorizationException;

class EncounterManagementService
{
    /**
     * Start a new clinical encounter.
     */
    public function startEncounter(
        Patient $patient,
        Branch $branch,
        ?DoctorProfile $provider = null,
        ?string $encounterType = 'consultation'
    ): ClinicalEncounter {
        if ($patient->clinic_id && $branch->clinic_id && (int) $patient->clinic_id !== (int) $branch->clinic_id) {
            throw new \InvalidArgumentException('Branch does not belong to the patient clinic.');
        }

        if (app()->bound(ClinicContext::class)) {
            $context = app(ClinicContext::class);
            if ($context->membership() && ! $context->membership()->hasAccessToBranch($branch)) {
                throw new AuthorizationException('User does not have access to the specified branch.');
            }
        }

        // Generate encounter number
        $encounterNumber = 'ENC-'.date('Ymd').'-'.rand(1000, 9999);

        $clinicId = $patient->clinic_id ?? $branch->clinic_id;
        $organizationId = $patient->organization_id ?? $branch->organization_id ?? $patient->clinic?->organization_id;

        $encounter = ClinicalEncounter::create([
            'organization_id' => $organizationId,
            'clinic_id' => $clinicId,
            'patient_id' => $patient->id,
            'branch_id' => $branch->id,
            'provider_id' => $provider?->id,
            'encounter_number' => $encounterNumber,
            'encounter_type' => $encounterType,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        // Log timeline event
        PatientTimelineEvent::create([
            'organization_id' => $organizationId,
            'clinic_id' => $clinicId,
            'patient_id' => $patient->id,
            'event_type' => 'encounter_started',
            'context' => [
                'encounter_id' => $encounter->id,
                'encounter_number' => $encounter->encounter_number,
                'encounter_type' => $encounterType,
            ],
            'actor_id' => auth()->id() ?? null,
        ]);

        return $encounter;
    }

    /**
     * Complete an active encounter.
     */
    public function completeEncounter(ClinicalEncounter $encounter): ClinicalEncounter
    {
        $encounter->update([
            'status' => 'completed',
            'ended_at' => now(),
        ]);

        // Log timeline event
        PatientTimelineEvent::create([
            'organization_id' => $encounter->organization_id,
            'clinic_id' => $encounter->clinic_id,
            'patient_id' => $encounter->patient_id,
            'event_type' => 'encounter_completed',
            'context' => [
                'encounter_id' => $encounter->id,
                'encounter_number' => $encounter->encounter_number,
            ],
            'actor_id' => auth()->id() ?? null,
        ]);

        return $encounter;
    }
}
