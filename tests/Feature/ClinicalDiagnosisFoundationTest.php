<?php

use App\Models\Branch;
use App\Models\ClinicalEncounter;
use App\Models\DiagnosisMaster;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\User;
use App\Services\ClinicalDiagnosisService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('adds a diagnosis from master list to encounter', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $patient = Patient::factory()->create(['organization_id' => $organization->id]);

    $master = DiagnosisMaster::create([
        'organization_id' => $organization->id,
        'code' => 'K02.1',
        'name' => 'Dental Caries',
        'category' => 'ICD-10',
    ]);

    $encounter = ClinicalEncounter::create([
        'organization_id' => $organization->id,
        'patient_id' => $patient->id,
        'branch_id' => Branch::factory()->create(['organization_id' => $organization->id, 'clinic_id' => $patient->clinic_id])->id,
        'encounter_number' => 'ENC-001',
        'status' => 'in_progress',
        'started_at' => now(),
    ]);

    $this->actingAs($user);

    $service = new ClinicalDiagnosisService;

    $diagnosis = $service->addDiagnosis($encounter, [
        'diagnosis_master_id' => $master->id,
        'description' => null, // Service pulls from master
        'details' => ['tooth' => 11],
    ]);

    expect($diagnosis->coding_system)->toBe('ICD-10')
        ->and($diagnosis->code)->toBe('K02.1')
        ->and($diagnosis->description)->toBe('Dental Caries')
        ->and($diagnosis->status)->toBe('active')
        ->and($diagnosis->details['tooth'])->toBe(11)
        ->and($patient->timelineEvents()->where('event_type', 'diagnosis_added')->count())->toBe(1);

    // Update status
    $service->updateStatus($diagnosis, 'resolved');

    expect($diagnosis->fresh()->status)->toBe('resolved')
        ->and($patient->timelineEvents()->where('event_type', 'diagnosis_status_updated')->count())->toBe(1);
});
