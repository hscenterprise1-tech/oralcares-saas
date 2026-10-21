<?php

namespace App\Services\Patient;

use App\Events\Patient\PatientRegistered;
use App\Events\Patient\PatientVisitCreated;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ConsentRecord;
use App\Models\Patient;
use App\Models\PatientTimelineEvent;
use App\Models\PatientVisit;
use App\Models\User;
use App\Services\Security\SecurityAudit;
use App\VisitRegistrationType;
use Illuminate\Support\Facades\DB;

class PatientRegistrationService
{
    public function __construct(
        protected UhidGenerator $uhidGenerator,
        protected CaseNumberGenerator $caseNumberGenerator,
        protected PatientBranchActivityRecorder $branchActivity,
        protected PatientSpecialties $patientSpecialties,
        protected ClinicSpecialties $clinicSpecialties,
        protected SecurityAudit $audit,
    ) {}

    /**
     * What follows creating the record, in the same transaction as the
     * caller: the branch it was registered at, its specialty enrolments (the
     * ones chosen, else the clinic's only active specialty), communication
     * consent, and — when it was registered past possible existing patients —
     * the audited override.
     *
     * @param  list<string>  $specialtyKeys
     * @param  list<int>  $overriddenMatchIds
     */
    public function completeRegistration(
        Patient $patient,
        Clinic $clinic,
        ?Branch $branch,
        ?User $by,
        array $specialtyKeys = [],
        bool $communicationConsent = false,
        ?string $consentMethod = null,
        array $overriddenMatchIds = [],
        ?string $overrideReason = null,
    ): void {
        $now = now();

        if ($branch && $branch->clinic_id === $clinic->id) {
            $this->branchActivity->record($clinic->id, $patient->id, $branch->id, $now, 'registration');
        }

        $active = $this->clinicSpecialties->activeKeys($clinic);
        $keys = $specialtyKeys !== [] ? $specialtyKeys : (count($active) === 1 ? $active : []);
        foreach ($keys as $key) {
            $this->patientSpecialties->enroll($patient, $key, 'registration', $by, $now);
        }

        if ($communicationConsent) {
            // Given against the clinic's current communication wording.
            $evidence = app(ConsentService::class)->evidence($clinic, 'communication');
            ConsentRecord::query()->withoutGlobalScopes()->create([
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'consent_type' => 'communication',
                'consent_version' => $evidence['version'],
                'text_hash' => $evidence['text_hash'],
                'status' => ConsentRecord::GRANTED,
                'capture_method' => $consentMethod ?? 'verbal',
                'captured_by' => $by?->id,
                'captured_at' => $now,
                'signed_at' => $now,
            ]);
        }

        if ($overriddenMatchIds !== []) {
            $this->audit->record('patient.duplicate_override', $patient, [
                'possible_duplicate_ids' => array_values($overriddenMatchIds),
                'reason' => $overrideReason,
            ]);
        }
    }

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
