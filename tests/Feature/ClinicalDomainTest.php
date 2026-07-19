<?php

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalNote;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientAlert;
use App\Models\Prescription;
use App\Models\TreatmentPlan;
use App\Models\TreatmentRecord;
use App\Services\ClinicalDomainValidationService;
use App\Services\ClinicalIdentifierService;
use Database\Seeders\SystemRoleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SystemRoleTemplateSeeder::class);
});

test('clinical database relationship integrity', function () {
    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $doctor = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);

    $patient = Patient::create([
        'clinic_id' => $clinic->id,
        'patient_number' => 'PT-HOUSE-1',
        'name' => 'John Doe',
        'email' => 'johndoe@example.com',
    ]);

    // Create Medical Alert
    $alert = PatientAlert::create([
        'patient_id' => $patient->id,
        'type' => 'allergy',
        'description' => 'Penicillin Allergy',
        'severity' => 'critical',
    ]);

    expect($patient->alerts)->toHaveCount(1)
        ->and($patient->alerts->first()->description)->toBe('Penicillin Allergy');

    // Create Encounter/Visit
    $encounter = ClinicalEncounter::create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'doctor_id' => $doctor->id,
        'encounter_number' => 'ENC-HOUSE-1',
        'status' => 'checked_in',
    ]);

    expect($patient->encounters)->toHaveCount(1)
        ->and($encounter->doctor->name)->toBe('Dr. House');

    // Create Clinical Note
    $note = ClinicalNote::create([
        'encounter_id' => $encounter->id,
        'patient_id' => $patient->id,
        'author_id' => $doctor->id,
        'note_type' => 'soap',
        'content' => 'Patient has tooth pain.',
    ]);

    expect($encounter->notes)->toHaveCount(1)
        ->and($encounter->notes->first()->content)->toBe('Patient has tooth pain.');

    // Create Treatment Plan & Record
    $plan = TreatmentPlan::create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'title' => 'Dental Crown Treatment Plan',
        'status' => 'draft',
    ]);

    $treatment = TreatmentRecord::create([
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
        'treatment_plan_id' => $plan->id,
        'code' => 'D2740',
        'description' => 'Crown - porcelain/ceramic',
        'tooth_number' => '14',
        'status' => 'proposed',
        'price' => 850.00,
    ]);

    expect($patient->treatmentPlans)->toHaveCount(1)
        ->and($plan->records)->toHaveCount(1)
        ->and($encounter->treatments)->toHaveCount(1);

    // Create Prescription
    $prescription = Prescription::create([
        'encounter_id' => $encounter->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'prescription_number' => 'RX-HOUSE-1',
        'status' => 'active',
    ]);

    $prescription->items()->create([
        'drug_name' => 'Amoxicillin',
        'dosage' => '500mg',
        'frequency' => 'Three times a day',
        'duration' => '7 days',
    ]);

    expect($encounter->prescriptions)->toHaveCount(1)
        ->and($prescription->items)->toHaveCount(1);
});

test('identifier service generates unique sequential numbers', function () {
    $clinic = Clinic::factory()->create();
    $idService = app(ClinicalIdentifierService::class);

    $num1 = $idService->generatePatientNumber($clinic);
    Patient::create([
        'clinic_id' => $clinic->id,
        'patient_number' => $num1,
        'name' => 'Patient One',
    ]);

    $num2 = $idService->generatePatientNumber($clinic);
    expect($num1)->toBe("PT-{$clinic->id}-00001")
        ->and($num2)->toBe("PT-{$clinic->id}-00002");
});

test('domain validation service validates lifecycle transitions', function () {
    $valService = app(ClinicalDomainValidationService::class);

    // Patient
    expect($valService->validatePatientStatusTransition('active', 'inactive'))->toBeTrue();
    expect($valService->validatePatientStatusTransition('archived', 'inactive'))->toBeFalse();

    // Visit/Encounter
    expect($valService->validateEncounterStatusTransition('checked_in', 'waiting'))->toBeTrue();
    expect($valService->validateEncounterStatusTransition('completed', 'waiting'))->toBeFalse();

    // Treatment Record
    expect($valService->validateTreatmentStatusTransition('proposed', 'completed'))->toBeTrue();
    expect($valService->validateTreatmentStatusTransition('completed', 'proposed'))->toBeFalse();
});
