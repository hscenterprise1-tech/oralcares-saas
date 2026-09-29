<?php

use App\Events\WorkflowCancelled;
use App\Events\WorkflowCompleted;
use App\Events\WorkflowStageChanged;
use App\Events\WorkflowStarted;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Services\WorkflowDefinitionService;
use App\Services\WorkflowEngineService;
use App\Services\WorkflowTransitionService;
use Database\Seeders\SystemRoleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SystemRoleTemplateSeeder::class);
});

test('can configure custom stages and enforce single active flow definition per branch', function () {
    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $clinic->organization_id]);

    $defService = app(WorkflowDefinitionService::class);

    // 1. Create first definition
    $flow1 = $defService->createDefinition($clinic, $branch, 'Standard Flow V1');
    expect($flow1->is_active)->toBeTrue();

    // 2. Create second definition (should deactivate Standard Flow V1)
    $flow2 = $defService->createDefinition($clinic, $branch, 'Standard Flow V2');

    expect($flow2->is_active)->toBeTrue()
        ->and($flow1->fresh()->is_active)->toBeFalse();

    // 3. Add Custom Stages
    $stageReception = $defService->addStage($flow2, 'Reception', 'reception', 'Floor 1', 'Reception', 10);
    $stageDoctor = $defService->addStage($flow2, 'Doctor Consultation', 'doctor', 'Floor 2', 'Dental', 20);

    expect($flow2->stages)->toHaveCount(2)
        ->and($flow2->stages->first()->slug)->toBe('reception');
});

test('transition verification and rules evaluation engine', function () {
    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $clinic->organization_id]);

    $defService = app(WorkflowDefinitionService::class);
    $transService = app(WorkflowTransitionService::class);

    $flow = $defService->createDefinition($clinic, $branch, 'Ortho Flow');
    $reception = $defService->addStage($flow, 'Reception', 'reception', 'Floor 1', 'Reception', 10);
    $doctor = $defService->addStage($flow, 'Doctor', 'doctor', 'Floor 2', 'Dental', 20);
    $xray = $defService->addStage($flow, 'X-Ray', 'xray', 'Floor 3', 'Radiology', 30);

    // Transition 1: Reception -> Doctor (no rules)
    $trans1 = $transService->createTransition($flow, $reception, $doctor);

    // Transition 2: Doctor -> X-Ray (requires xray rule check)
    $trans2 = $transService->createTransition($flow, $doctor, $xray, ['requires_xray' => true]);

    // Setup run
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $employee = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);
    $encounter = ClinicalEncounter::create([
        'organization_id' => $clinic->organization_id,
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'encounter_number' => 'ENC-1',
        'status' => 'checked_in',
    ]);

    $run = app(WorkflowEngineService::class)->startWorkflow($encounter, $flow, $employee);

    // Verify Reception -> Doctor transition is valid without extra context
    expect($transService->canTransition($run, $doctor))->toBeTrue();

    // Verify Doctor -> X-Ray transition is invalid without context, but valid if context matches
    $run->current_stage_id = $doctor->id;
    $run->save();

    expect($transService->canTransition($run, $xray, ['requires_xray' => false]))->toBeFalse()
        ->and($transService->canTransition($run, $xray, ['requires_xray' => true]))->toBeTrue();
});

test('orchestration run lifecycle transitions completes and cancels flows with history', function () {
    Event::fake([
        WorkflowStarted::class,
        WorkflowStageChanged::class,
        WorkflowCompleted::class,
        WorkflowCancelled::class,
    ]);

    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $clinic->organization_id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $employee = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);
    $encounter = ClinicalEncounter::create([
        'organization_id' => $clinic->organization_id,
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'encounter_number' => 'ENC-1',
        'status' => 'checked_in',
    ]);

    $defService = app(WorkflowDefinitionService::class);
    $transService = app(WorkflowTransitionService::class);
    $engine = app(WorkflowEngineService::class);

    $flow = $defService->createDefinition($clinic, $branch, 'Standard Flow');
    $reception = $defService->addStage($flow, 'Reception', 'reception', 'Floor 1', 'Reception', 10);
    $doctor = $defService->addStage($flow, 'Doctor', 'doctor', 'Floor 2', 'Dental', 20);

    $transService->createTransition($flow, $reception, $doctor);

    // 1. Start Workflow
    $run = $engine->startWorkflow($encounter, $flow, $employee);
    expect($run->current_stage_id)->toBe($reception->id)
        ->and($run->status)->toBe('active')
        ->and($run->histories)->toHaveCount(1);
    Event::assertDispatched(WorkflowStarted::class);

    // 2. Transition Stage
    $engine->transitionTo($run, $doctor, $employee, 'Consultation required');
    expect($run->fresh()->current_stage_id)->toBe($doctor->id)
        ->and($run->fresh()->histories)->toHaveCount(2);
    Event::assertDispatched(WorkflowStageChanged::class);

    // 3. Complete
    $engine->completeWorkflow($run, $employee);
    expect($run->fresh()->status)->toBe('completed')
        ->and($run->fresh()->histories)->toHaveCount(3);
    Event::assertDispatched(WorkflowCompleted::class);

    // Setup another run for cancellation test
    $encounter2 = ClinicalEncounter::create([
        'organization_id' => $clinic->organization_id,
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'encounter_number' => 'ENC-2',
        'status' => 'checked_in',
    ]);

    $run2 = $engine->startWorkflow($encounter2, $flow, $employee);
    $engine->cancelWorkflow($run2, $employee, 'Patient left');
    expect($run2->fresh()->status)->toBe('cancelled');
    Event::assertDispatched(WorkflowCancelled::class);
});
