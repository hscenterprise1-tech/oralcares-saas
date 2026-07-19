<?php

use App\Events\LaboratoryAccepted;
use App\Events\LaboratoryCaseCreated;
use App\Events\LaboratoryCompleted;
use App\Events\LaboratoryDelivered;
use App\Events\LaboratoryProductionStarted;
use App\Events\LaboratoryReady;
use App\LabCaseStatus;
use App\LabTrialType;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\TreatmentRecord;
use App\Services\LaboratoryCaseService;
use App\Services\LaboratoryWorkflowService;
use App\Services\MaterialService;
use App\Services\ProstheticCatalogService;
use App\Services\ShadeService;
use Database\Seeders\SystemRoleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SystemRoleTemplateSeeder::class);
});

test('can register prosthetic, material, and shade catalog configurations', function () {
    $clinic = Clinic::factory()->create();

    $prosthetic = app(ProstheticCatalogService::class)->createProsthetic('Zirconia Crown', 'Restorative', $clinic);
    $material = app(MaterialService::class)->createMaterial('Zirconia', $clinic);
    $shade = app(ShadeService::class)->createShade('Vita Classical', 'A2', $clinic);

    expect($prosthetic->name)->toBe('Zirconia Crown')
        ->and($material->name)->toBe('Zirconia')
        ->and($shade->code)->toBe('A2');
});

test('can create laboratory case, record trials and attach files', function () {
    Event::fake([LaboratoryCaseCreated::class]);

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
        'status' => 'checked_in',
    ]);

    $txRecord = TreatmentRecord::create([
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
        'code' => 'D2740',
        'description' => 'Crown',
        'tooth_number' => '14',
        'price' => 850.00,
    ]);

    $prosthetic = app(ProstheticCatalogService::class)->createProsthetic('PFM Crown', 'Restorative', $clinic);
    $material = app(MaterialService::class)->createMaterial('PFM', $clinic);
    $shade = app(ShadeService::class)->createShade('Vita Classical', 'B1', $clinic);

    $caseService = app(LaboratoryCaseService::class);

    // 1. Create Case
    $case = $caseService->createCase(
        $patient,
        $encounter,
        $txRecord,
        $doctor,
        $branch,
        [
            'laboratory_name' => 'Internal Lab',
            'is_internal' => true,
            'prosthetic_id' => $prosthetic->id,
            'material_id' => $material->id,
            'shade_id' => $shade->id,
            'requested_teeth' => [14],
            'delivery_due_date' => '2026-07-25',
            'notes' => 'Fabricate crown',
        ]
    );

    expect($case->lab_order_number)->toBe("LB-{$clinic->id}-00001")
        ->and($case->status)->toBe(LabCaseStatus::Requested)
        ->and($case->laboratory_name)->toBe('Internal Lab')
        ->and($case->is_internal)->toBeTrue()
        ->and($case->requested_teeth)->toContain(14)
        ->and($case->delivery_due_date->format('Y-m-d'))->toBe('2026-07-25');

    Event::assertDispatched(LaboratoryCaseCreated::class);

    // 2. Record Trial fitting stage
    $trial = $caseService->recordTrial($case, LabTrialType::MetalTrial, '2026-07-22 10:00:00', 'Trial scheduled', 'approved');
    expect($trial->trial_type)->toBe(LabTrialType::MetalTrial)
        ->and($trial->outcome)->toBe('approved');

    // 3. Attach files (STL references)
    $attachment = $caseService->attachFile($case, 'scan_upper_jaw.stl', 's3://oralcares-imaging/scans/upper.stl', 'stl_reference');
    expect($attachment->file_name)->toBe('scan_upper_jaw.stl')
        ->and($attachment->file_type)->toBe('stl_reference');
});

test('laboratory workflow transitions case status and triggers events', function () {
    Event::fake([
        LaboratoryAccepted::class,
        LaboratoryProductionStarted::class,
        LaboratoryReady::class,
        LaboratoryDelivered::class,
        LaboratoryCompleted::class,
    ]);

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
        'status' => 'checked_in',
    ]);

    $txRecord = TreatmentRecord::create([
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
        'code' => 'D2740',
        'description' => 'Crown',
        'price' => 500.00,
    ]);

    $case = app(LaboratoryCaseService::class)->createCase(
        $patient,
        $encounter,
        $txRecord,
        $doctor,
        $branch,
        ['laboratory_name' => 'Apex Lab']
    );

    $workflow = app(LaboratoryWorkflowService::class);

    // Accept
    $workflow->acceptCase($case);
    expect($case->fresh()->status)->toBe(LabCaseStatus::Accepted);
    Event::assertDispatched(LaboratoryAccepted::class);

    // Production
    $workflow->startProduction($case);
    expect($case->fresh()->status)->toBe(LabCaseStatus::InProduction);
    Event::assertDispatched(LaboratoryProductionStarted::class);

    // Ready
    $workflow->markReady($case);
    expect($case->fresh()->status)->toBe(LabCaseStatus::ReadyForDelivery);
    Event::assertDispatched(LaboratoryReady::class);

    // Delivered
    $workflow->deliverCase($case);
    expect($case->fresh()->status)->toBe(LabCaseStatus::Delivered);
    Event::assertDispatched(LaboratoryDelivered::class);

    // Completed
    $workflow->completeCase($case);
    expect($case->fresh()->status)->toBe(LabCaseStatus::Completed);
    Event::assertDispatched(LaboratoryCompleted::class);
});
