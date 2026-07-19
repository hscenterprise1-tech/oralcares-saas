<?php

namespace App\Services;

use App\Models\Clinic;
use App\Models\Patient;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
        Clinic $clinic,
        array $demographics,
        array $profileData = [],
        array $medicalHistoryData = [],
        bool $force = false
    ): Patient {
        return DB::transaction(function () use ($clinic, $demographics, $profileData, $medicalHistoryData, $force) {
            // 1. Duplicate detection check
            if (! $force) {
                $duplicates = $this->duplicateDetector->detectDuplicates($clinic, [
                    'phone' => $demographics['phone'] ?? null,
                    'email' => $demographics['email'] ?? null,
                    'name' => $demographics['name'] ?? null,
                    'date_of_birth' => $demographics['date_of_birth'] ?? null,
                ]);

                if ($duplicates->isNotEmpty()) {
                    throw new InvalidArgumentException('Potential duplicate patient records found.');
                }
            }

            // 2. Generate immutable patient number
            $patientNumber = $this->identifierService->generatePatientNumber($clinic);

            // 3. Create Patient
            $patient = Patient::create(array_merge($demographics, [
                'clinic_id' => $clinic->id,
                'patient_number' => $patientNumber,
                'status' => 'active',
            ]));

            // 4. Create Profile
            $this->profileService->updateProfile($patient, $profileData);

            // 5. Create Medical History
            $this->medicalHistoryService->updateMedicalHistory($patient, $medicalHistoryData);

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
