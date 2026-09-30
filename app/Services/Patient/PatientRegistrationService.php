<?php

namespace App\Services\Patient;

use App\Events\Patient\PatientRegistered;
use App\Events\Patient\PatientVisitCreated;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PatientTimelineEvent;
use App\Models\PatientVisit;
use App\VisitRegistrationType;
use Illuminate\Support\Facades\DB;

class PatientRegistrationService
{
    public function __construct(
        protected UhidGenerator $uhidGenerator,
        protected CaseNumberGenerator $caseNumberGenerator
    ) {}

    /**
     * Register a brand-new patient record with optional profile, without creating an immediate visit.
     *
     * @param  array<string, mixed>  $patientData
     * @param  array<string, mixed>  $profileData
     */
    public function createPatientOnly(
        array $patientData,
        array $profileData,
        Clinic $clinic
    ): Patient {
        return DB::transaction(function () use ($patientData, $profileData, $clinic) {
            // 1. Generate UHID
            $uhid = $this->uhidGenerator->generate($clinic);

            // 2. Create the permanent patient record
            $patient = Patient::create(array_merge($patientData, [
                'clinic_id' => $clinic->id,
                'uhid' => $uhid,
                'registration_type' => VisitRegistrationType::New->value,
                'status' => 'active',
                'name' => trim(($patientData['first_name'] ?? '').' '.($patientData['last_name'] ?? '')),
            ]));

            // 3. Create profile if profile data provided
            if (array_filter($profileData)) {
                $patient->profile()->create(array_merge($profileData, [
                    'organization_id' => $clinic->organization_id,
                ]));
            }

            // 4. Create timeline event
            PatientTimelineEvent::create([
                'organization_id' => $clinic->organization_id,
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'actor_id' => auth()->id(),
                'event_type' => 'registered',
                'context' => [
                    'source' => 'reception',
                    'registered_by' => auth()->user()?->name ?? 'Staff',
                ],
            ]);

            PatientRegistered::dispatch($patient);

            return $patient->load('profile');
        });
    }

    /**
     * Register a brand-new patient and create their first visit.
     *
     * Wrapped in a DB transaction so that UHID + visit + case number
     * are always created atomically.
     *
     * @param  array<string, mixed>  $patientData  Validated registration form data.
     * @param  array<string, mixed>  $visitData  Visit-specific options (doctor, department, etc.)
     */
    public function registerNewPatient(
        array $patientData,
        array $visitData,
        Clinic $clinic,
        Branch $branch
    ): PatientVisit {
        return DB::transaction(function () use ($patientData, $visitData, $clinic, $branch) {
            // 1. Generate UHID
            $uhid = $this->uhidGenerator->generate($clinic);

            // 2. Create the permanent patient record
            $patient = Patient::create(array_merge($patientData, [
                'clinic_id' => $clinic->id,
                'uhid' => $uhid,
                'registration_type' => VisitRegistrationType::New->value,
                'status' => 'active',
                // Keep legacy `name` populated for backward compatibility
                'name' => trim(($patientData['first_name'] ?? '').' '.($patientData['last_name'] ?? '')),
            ]));

            // 3. Create the visit record
            $visit = $this->createVisit($patient, $visitData, $clinic, $branch, VisitRegistrationType::New);

            // 4. The patient's case number for this period, linked to the visit
            $caseNumber = $this->caseNumberGenerator->forVisit($clinic, $visit);

            // 5. Create timeline event
            PatientTimelineEvent::create([
                'organization_id' => $clinic->organization_id,
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'actor_id' => auth()->id(),
                'event_type' => 'registered',
                'context' => [
                    'source' => 'reception',
                    'registered_by' => auth()->user()?->name ?? 'Staff',
                ],
            ]);

            PatientRegistered::dispatch($patient);
            PatientVisitCreated::dispatch($visit, $caseNumber);

            return $visit->load(['patient', 'caseNumber']);
        });
    }

    /**
     * Create a repeat visit for an existing patient.
     *
     * Reuses the patient's case number for the current period, or issues
     * one if this is their first visit in it. The UHID never changes.
     *
     * @param  array<string, mixed>  $visitData
     */
    public function registerRepeatVisit(
        Patient $patient,
        array $visitData,
        Clinic $clinic,
        Branch $branch
    ): PatientVisit {
        return DB::transaction(function () use ($patient, $visitData, $clinic, $branch) {
            // 1. Create the visit record
            $visit = $this->createVisit($patient, $visitData, $clinic, $branch, VisitRegistrationType::Repeat);

            // 2. The patient's case number for this period (reused if one exists)
            $caseNumber = $this->caseNumberGenerator->forVisit($clinic, $visit);

            PatientVisitCreated::dispatch($visit, $caseNumber);

            return $visit->load(['patient', 'caseNumber']);
        });
    }

    /**
     * Shared helper to create a PatientVisit row.
     */
    private function createVisit(
        Patient $patient,
        array $visitData,
        Clinic $clinic,
        Branch $branch,
        VisitRegistrationType $registrationType
    ): PatientVisit {
        return PatientVisit::create([
            'clinic_id' => $clinic->id,
            'branch_id' => $branch->id,
            'patient_id' => $patient->id,
            'department_id' => $visitData['department_id'] ?? null,
            'doctor_id' => $visitData['doctor_id'] ?? null,
            'registration_type' => $registrationType->value,
            'visit_date' => now()->toDateString(),
            'queue_status' => 'not_queued',
            'visit_status' => 'registered',
            'registration_fee_collected' => $visitData['fee_collected'] ?? false,
            'notes' => $visitData['notes'] ?? null,
        ]);
    }
}
