<?php

use App\Events\ConsultationCompleted;
use App\Events\ConsultationStarted;
use App\Events\DiagnosisRecorded;
use App\Events\TreatmentPlanCreated;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalNote;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientMedicalHistory;
use App\Models\User;
use App\Services\ConsultationService;
use App\Services\DentalChartService;
use App\Services\DiagnosisService;
use App\Services\PatientSummaryService;
use App\Services\TreatmentPlanningService;
use App\VisitStatus;
use Database\Seeders\SystemRoleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SystemRoleTemplateSeeder::class);
});

test('consultation lifecycle starts, pauses, resumes, and finishes', function () {
    Event::fake();

    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_number' => 'PT-1', 'name' => 'John Doe']);
    $doctor = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);

    $encounter = ClinicalEncounter::create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'doctor_id' => $doctor->id,
        'encounter_number' => 'ENC-1',
        'status' => VisitStatus::CheckedIn,
    ]);

    $service = app(ConsultationService::class);

    // 1. Start Consultation
    $consultation = $service->startConsultation($encounter, $doctor);

    expect($consultation->status)->toBe('started')
        ->and($encounter->fresh()->status)->toBe(VisitStatus::InConsultation);
    Event::assertDispatched(ConsultationStarted::class);

    // 2. Pause
    $service->pauseConsultation($encounter);
    expect($consultation->fresh()->status)->toBe('paused')
        ->and($consultation->fresh()->paused_at)->not->toBeNull();

    // 3. Resume
    $service->resumeConsultation($encounter);
    expect($consultation->fresh()->status)->toBe('resumed')
        ->and($consultation->fresh()->paused_at)->toBeNull();

    // 4. Finish
    $service->finishConsultation($encounter);
    expect($consultation->fresh()->status)->toBe('completed')
        ->and($encounter->fresh()->status)->toBe(VisitStatus::Completed);
    Event::assertDispatched(ConsultationCompleted::class);
});

test('clinical SOAP notes and diagnoses can be recorded', function () {
    Event::fake([DiagnosisRecorded::class]);

    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $user = User::factory()->create(['organization_id' => $clinic->organization_id]);
    $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_number' => 'PT-1', 'name' => 'John Doe']);
    $doctor = ClinicEmployee::create(['clinic_id' => $clinic->id, 'user_id' => $user->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);

    $encounter = ClinicalEncounter::create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'doctor_id' => $doctor->id,
        'encounter_number' => 'ENC-1',
        'status' => VisitStatus::InConsultation,
    ]);

    // Create Note
    $note = ClinicalNote::create([
        'encounter_id' => $encounter->id,
        'patient_id' => $patient->id,
        'author_id' => $user->id,
        'note_type' => 'SOAP',
        'content' => 'Subjective: Patient has tooth sensitivity.',
    ]);

    expect($encounter->notes)->toHaveCount(1)
        ->and($encounter->notes->first()->note_type)->toBe('SOAP');

    // Create Diagnosis
    $diagnosis = app(DiagnosisService::class)->recordDiagnosis(
        $encounter,
        'Dental Caries',
        'K02.9',
        'Caries present on molar.'
    );

    expect($encounter->fresh()->diagnoses)->toHaveCount(1)
        ->and($diagnosis->code)->toBe('K02.9');
    Event::assertDispatched(DiagnosisRecorded::class);
});

test('dental chart validates FDI tooth status edits', function () {
    $clinic = Clinic::factory()->create();
    $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_number' => 'PT-1', 'name' => 'John Doe']);
    $doctor = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);

    $chartService = app(DentalChartService::class);

    // FDI valid status update
    $tooth = $chartService->updateToothStatus($patient, 14, 'implant', 'Replaced missing molar', $doctor);

    expect($tooth->status)->toBe('implant')
        ->and($patient->dentalChart)->toHaveCount(1)
        ->and($patient->dentalChart->first()->tooth_number)->toBe(14);

    // FDI invalid status update
    $failed = false;
    try {
        $chartService->updateToothStatus($patient, 99, 'crown', 'Invalid FDI number', $doctor);
    } catch (InvalidArgumentException $e) {
        $failed = true;
    }
    expect($failed)->toBeTrue();
});

test('treatment planning drafts procedures, prescriptions, labs, and xrays', function () {
    Event::fake();

    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_number' => 'PT-1', 'name' => 'John Doe']);
    $doctor = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);

    $encounter = ClinicalEncounter::create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'doctor_id' => $doctor->id,
        'encounter_number' => 'ENC-1',
        'status' => VisitStatus::InConsultation,
    ]);

    $planService = app(TreatmentPlanningService::class);

    // 1. Treatment Plan
    $plan = $planService->createTreatmentPlan($patient, $clinic, 'Root Canal Treatment', [
        [
            'code' => 'D3330',
            'description' => 'Endodontic therapy, molar',
            'tooth_number' => '16',
            'price' => 1200.00,
        ],
    ]);

    expect($plan->title)->toBe('Root Canal Treatment')
        ->and($plan->records)->toHaveCount(1)
        ->and($plan->records->first()->code)->toBe('D3330');
    Event::assertDispatched(TreatmentPlanCreated::class);

    // 2. Prescription
    $rx = $planService->requestPrescription($encounter, $doctor, [
        [
            'drug_name' => 'Ibuprofen',
            'dosage' => '400mg',
            'frequency' => 'Twice daily',
            'duration' => '3 days',
        ],
    ], 'Take after food.');

    expect($rx->prescription_number)->toBe("RX-{$clinic->id}-00001")
        ->and($rx->items)->toHaveCount(1);

    // 3. X-Ray & Lab Requests
    $xr = $planService->requestXray($encounter, $patient, 'OPG request');
    $lb = $planService->requestLab($encounter, $patient, 'Crown fabrication');

    expect($xr->xray_number)->toBe("XR-{$clinic->id}-00001")
        ->and($lb->lab_order_number)->toBe("LB-{$clinic->id}-00001");
});

test('patient summary compiler output', function () {
    $clinic = Clinic::factory()->create();
    $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_number' => 'PT-1', 'name' => 'John Doe']);

    PatientMedicalHistory::create([
        'patient_id' => $patient->id,
        'diabetes' => true,
        'allergies' => ['Penicillin'],
        'current_medications' => ['Metformin'],
    ]);

    $summary = app(PatientSummaryService::class)->getSummary($patient);

    expect($summary['patient']['name'])->toBe('John Doe')
        ->and($summary['medical_history']['conditions']['diabetes'])->toBeTrue()
        ->and($summary['medical_history']['allergies'])->toContain('Penicillin')
        ->and($summary['medical_history']['current_medications'])->toContain('Metformin');
});
