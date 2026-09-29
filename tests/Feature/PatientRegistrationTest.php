<?php

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\User;
use App\Services\ClinicalIdentifierService;
use App\Services\MedicalHistoryService;
use App\Services\Patient\PatientRegistrationService;
use App\Services\PatientDuplicateDetectionService;
use App\Services\PatientProfileService;
use App\Services\PatientRegistrationService as NewPatientRegistrationService;
use App\VisitRegistrationType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('registers a patient with uhid and timeline event', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($user);

    $service = new NewPatientRegistrationService(
        app(ClinicalIdentifierService::class),
        app(PatientDuplicateDetectionService::class),
        app(PatientProfileService::class),
        app(MedicalHistoryService::class)
    );

    $patient = $service->registerPatient(
        $organization->id,
        [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'phone' => '1234567890',
        ],
        [],
        []
    );

    expect($patient->organization_id)->toBe($organization->id)
        ->and($patient->uhid)->not->toBeNull()
        ->and($patient->timelineEvents()->count())->toBe(1)
        ->and($patient->timelineEvents()->first()->event_type)->toBe('registered');
});

beforeEach(function () {
    $this->clinic = Clinic::factory()->create();
    $this->branch = Branch::factory()->create(['clinic_id' => $this->clinic->id]);
    $this->service = app(PatientRegistrationService::class);
});

it('registers a new patient and generates a UHID', function () {
    $visit = $this->service->registerNewPatient(
        patientData: [
            'first_name' => 'Rahul',
            'last_name' => 'Mehta',
            'phone' => '9876543210',
            'gender' => 'male',
        ],
        visitData: [],
        clinic: $this->clinic,
        branch: $this->branch
    );

    $patient = $visit->patient;

    expect($patient->uhid)->toStartWith('OC-')
        ->and($patient->first_name)->toBe('Rahul')
        ->and($patient->registration_type)->toBe(VisitRegistrationType::New);
});

it('generates a case number on new patient registration', function () {
    $visit = $this->service->registerNewPatient(
        patientData: [
            'first_name' => 'Priya',
            'last_name' => 'Shah',
            'phone' => '9000000001',
            'gender' => 'female',
        ],
        visitData: [],
        clinic: $this->clinic,
        branch: $this->branch
    );

    expect($visit->caseNumber)->not->toBeNull()
        ->and($visit->caseNumber->year)->toBe(now()->year)
        ->and($visit->caseNumber->sequence)->toBe(1)
        ->and($visit->caseNumber->case_number)->toBe('OPD-'.now()->year.'-000001');
});

it('creates a repeat visit for an existing patient without changing the UHID', function () {
    // Register new patient
    $firstVisit = $this->service->registerNewPatient(
        patientData: [
            'first_name' => 'Arjun',
            'last_name' => 'Kumar',
            'phone' => '9111111111',
            'gender' => 'male',
        ],
        visitData: [],
        clinic: $this->clinic,
        branch: $this->branch
    );

    $originalUhid = $firstVisit->patient->uhid;
    $firstCaseNumber = $firstVisit->caseNumber->case_number;

    // Register repeat visit
    $repeatVisit = $this->service->registerRepeatVisit(
        patient: $firstVisit->patient,
        visitData: [],
        clinic: $this->clinic,
        branch: $this->branch
    );

    expect($repeatVisit->patient->uhid)->toBe($originalUhid) // UHID unchanged
        ->and($repeatVisit->caseNumber->case_number)->toBe($firstCaseNumber) // Same period, same case number
        ->and($repeatVisit->patient->id)->toBe($firstVisit->patient->id); // Same patient record
});

it('assigns sequential UHIDs to multiple new patients', function () {
    $visit1 = $this->service->registerNewPatient(
        patientData: ['first_name' => 'P1', 'last_name' => 'T', 'phone' => '1111111111', 'gender' => 'male'],
        visitData: [],
        clinic: $this->clinic,
        branch: $this->branch
    );

    $visit2 = $this->service->registerNewPatient(
        patientData: ['first_name' => 'P2', 'last_name' => 'T', 'phone' => '2222222222', 'gender' => 'female'],
        visitData: [],
        clinic: $this->clinic,
        branch: $this->branch
    );

    expect($visit1->patient->uhid)->toBe('OC-00000001')
        ->and($visit2->patient->uhid)->toBe('OC-00000002');
});

it('assigns sequential case numbers across different patients in the same year', function () {
    $visit1 = $this->service->registerNewPatient(
        patientData: ['first_name' => 'A', 'last_name' => 'B', 'phone' => '3333333333', 'gender' => 'male'],
        visitData: [],
        clinic: $this->clinic,
        branch: $this->branch
    );

    $visit2 = $this->service->registerNewPatient(
        patientData: ['first_name' => 'C', 'last_name' => 'D', 'phone' => '4444444444', 'gender' => 'female'],
        visitData: [],
        clinic: $this->clinic,
        branch: $this->branch
    );

    expect($visit1->caseNumber->sequence)->toBe(1)
        ->and($visit2->caseNumber->sequence)->toBe(2);
});
