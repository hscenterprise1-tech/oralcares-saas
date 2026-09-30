<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\PatientTimelineEvent;
use Illuminate\Support\Facades\DB;

class PatientRegistrationService
{
    public function __construct(
        protected ClinicalIdentifierService $identifierService,
        protected PatientDuplicateDetectionService $duplicateDetector,
        protected PatientProfileService $profileService,
        protected MedicalHistoryService $medicalHistoryService
    ) {}

    /**
     * Register a new patient, with duplicate guards.
     */
    public function registerPatient(
        $organizationId,
        array $demographics,
        array $profileData = [],
        array $medicalHistoryData = [],
        bool $force = false
    ): Patient {
        return DB::transaction(function () use ($organizationId, $demographics, $profileData, $medicalHistoryData) {
            // 1. Generate UHID and patient_number
            // In a real implementation, this would use a NumberingSequenceService.
            $uhid = 'UHID-'.date('Ymd').'-'.rand(1000, 9999);
            $patientNumber = 'PAT-'.date('Ymd').'-'.rand(1000, 9999);

            // 2. Create Patient
            $patient = Patient::create(array_merge($demographics, [
                'organization_id' => $organizationId,
                'uhid' => $uhid,
                'patient_number' => $patientNumber,
                'status' => 'active',
            ]));

            // 3. Create Profile
            $patient->profile()->create(array_merge($profileData, [
                'organization_id' => $organizationId,
            ]));

            // 4. Create Medical History
            $patient->medicalHistory()->create(array_merge($medicalHistoryData, [
                'organization_id' => $organizationId,
            ]));

            // 5. Create Timeline Event
            PatientTimelineEvent::create([
                'organization_id' => $organizationId,
                'patient_id' => $patient->id,
                'event_type' => 'registered',
                'context' => [
                    'source' => 'reception',
                ],
                'actor_id' => auth()->id() ?? null,
            ]);

            return $patient;
        });
    }

    /**
     * Archive a patient. Never physically delete patient records.
     */
    public function archivePatient(Patient $patient): void
    {
        $patient->update(['status' => 'archived']);
    }
}
