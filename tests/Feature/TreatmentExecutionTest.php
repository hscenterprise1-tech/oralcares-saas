<?php

use App\Events\BillableItemCreated;
use App\Events\ToothStatusUpdated;
use App\Events\TreatmentCompleted;
use App\Events\TreatmentStarted;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\TreatmentRecord;
use App\Services\ClinicalProgressService;
use App\Services\ProcedureCatalogService;
use App\Services\ToothStatusHistoryService;
use App\Services\TreatmentExecutionService;
use App\TreatmentStatus;
use App\VisitStatus;
use Database\Seeders\SystemRoleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SystemRoleTemplateSeeder::class);
});

test('procedure catalog registers entries and overrides tenant pricing', function () {
    $clinic = Clinic::factory()->create();

    $catalogService = app(ProcedureCatalogService::class);

    // 1. Create a Procedure
    $procedure = $catalogService->createProcedure([
        'code' => 'D2740',
        'name' => 'Crown - porcelain/ceramic',
        'description' => 'Single crown replacement',
        'category' => 'restorative',
        'default_duration_minutes' => 60,
        'clinical_complexity' => 'medium',
        'requires_tooth' => true,
        'is_active' => true,
    ]);

    expect($procedure->code)->toBe('D2740');

    // 2. Set Clinic Override Pricing
    $catalogService->setClinicPricing($clinic, $procedure, 950.00, 45, true);

    // 3. Resolve Effective Price
    $effectivePrice = $catalogService->getEffectivePrice($clinic, $procedure);
    expect($effectivePrice)->toBe(950.00);
});

test('tooth history service tracks fdi notation and syncs cache', function () {
    Event::fake([ToothStatusUpdated::class]);

    $clinic = Clinic::factory()->create();
    $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_number' => 'PT-1', 'name' => 'John Doe']);
    $doctor = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);

    $historyService = app(ToothStatusHistoryService::class);

    // Log tooth status
    $history = $historyService->logToothStatus($patient, 14, 'filled', 'Tooth had caries', null, $doctor);

    expect($history->status)->toBe('filled')
        ->and($patient->fresh()->dentalChart->first()->status)->toBe('filled');

    // Fetch history
    $list = $historyService->getToothStatusHistory($patient, 14);
    expect($list)->toHaveCount(1)
        ->and($list->first()->status)->toBe('filled');

    // Log second status
    $history2 = $historyService->logToothStatus($patient, 14, 'crown', 'Molar replaced with crown', null, $doctor);
    expect($patient->fresh()->dentalChart->first()->status)->toBe('crown');

    $list2 = $historyService->getToothStatusHistory($patient, 14);
    expect($list2)->toHaveCount(2);

    Event::assertDispatched(ToothStatusUpdated::class);
});

test('treatment execution starts, pauses, resumes, and completes with billing creation', function () {
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

    $txRecord = TreatmentRecord::create([
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
        'code' => 'D2740',
        'description' => 'Crown',
        'tooth_number' => '14',
        'status' => TreatmentStatus::Proposed,
        'price' => 850.00,
    ]);

    $execService = app(TreatmentExecutionService::class);

    // 1. Start
    $execService->startTreatment($txRecord);
    expect($txRecord->fresh()->status)->toBe(TreatmentStatus::Started)
        ->and($txRecord->fresh()->progressNotes)->toHaveCount(1);
    Event::assertDispatched(TreatmentStarted::class);

    // 2. Pause
    $execService->pauseTreatment($txRecord, 'Waiting for anesthesia');
    expect($txRecord->fresh()->status)->toBe(TreatmentStatus::Paused);

    // 3. Resume
    $execService->resumeTreatment($txRecord);
    expect($txRecord->fresh()->status)->toBe(TreatmentStatus::Resumed);

    // 4. Complete
    $execService->completeTreatment($txRecord, $doctor);

    expect($txRecord->fresh()->status)->toBe(TreatmentStatus::Completed)
        ->and($patient->fresh()->dentalChart->first()->status)->toBe('completed');

    // Verify billing preparation
    $this->assertDatabaseHas('billing_references', [
        'treatment_record_id' => $txRecord->id,
        'amount' => 850.00,
        'status' => 'pending',
    ]);

    Event::assertDispatched(BillableItemCreated::class);
    Event::assertDispatched(TreatmentCompleted::class);
});

test('clinical progress service maps materials to execution records', function () {
    $clinic = Clinic::factory()->create();
    $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_number' => 'PT-1', 'name' => 'John Doe']);

    $txRecord = TreatmentRecord::create([
        'patient_id' => $patient->id,
        'code' => 'D2740',
        'description' => 'Crown',
        'status' => TreatmentStatus::Planned,
        'price' => 500.00,
    ]);

    $progService = app(ClinicalProgressService::class);

    $progService->addMaterial($txRecord, 'Composite Resin', 2.00, 'BATCH-ABC');

    expect($txRecord->materials)->toHaveCount(1)
        ->and($txRecord->materials->first()->material_name)->toBe('Composite Resin')
        ->and((float) $txRecord->materials->first()->quantity)->toBe(2.00);
});
