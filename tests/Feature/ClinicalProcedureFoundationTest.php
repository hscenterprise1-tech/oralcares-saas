<?php

use App\Models\Branch;
use App\Models\ClinicalEncounter;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\ProcedureMaster;
use App\Models\User;
use App\Services\ClinicalProcedureService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records a procedure from master list and completes it', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $patient = Patient::factory()->create(['organization_id' => $organization->id]);

    $master = ProcedureMaster::create([
        'organization_id' => $organization->id,
        'code' => 'D0120',
        'name' => 'Periodic oral evaluation',
        'description' => 'Established patient',
        'base_price' => 50.00,
        'duration_minutes' => 30,
        'is_active' => true,
    ]);

    $encounter = ClinicalEncounter::create([
        'organization_id' => $organization->id,
        'patient_id' => $patient->id,
        'branch_id' => Branch::factory()->create(['organization_id' => $organization->id, 'clinic_id' => $patient->clinic_id])->id,
        'encounter_number' => 'ENC-002',
        'status' => 'in_progress',
        'started_at' => now(),
    ]);

    $this->actingAs($user);

    $service = new ClinicalProcedureService;

    // Record planned procedure
    $procedure = $service->recordProcedure($encounter, [
        'procedure_master_id' => $master->id,
        'name' => 'Should be overridden',
        'status' => 'planned',
        'details' => ['material' => 'composite'],
    ]);

    expect($procedure->code)->toBe('D0120')
        ->and($procedure->name)->toBe('Periodic oral evaluation')
        ->and($procedure->description)->toBe('Established patient')
        ->and($procedure->status)->toBe('planned')
        ->and($procedure->performed_by)->toBeNull()
        ->and($procedure->details['material'])->toBe('composite')
        ->and($patient->timelineEvents()->where('event_type', 'procedure_recorded')->count())->toBe(1);

    // Update status to completed
    $service->updateStatus($procedure, 'completed');

    $procedure->refresh();

    expect($procedure->status)->toBe('completed')
        ->and($procedure->performed_by)->toBe($user->id)
        ->and($procedure->performed_at)->not->toBeNull()
        ->and($patient->timelineEvents()->where('event_type', 'procedure_status_updated')->count())->toBe(1);
});
