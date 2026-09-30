<?php

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\User;
use App\Services\EncounterManagementService;
use App\VisitStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('starts and completes an encounter with timeline events', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $branch = Branch::factory()->create(['organization_id' => $organization->id]);
    $patient = Patient::factory()->create([
        'organization_id' => $organization->id,
        'clinic_id' => $branch->clinic_id,
    ]);

    $this->actingAs($user);

    $service = new EncounterManagementService;

    // Start
    $encounter = $service->startEncounter($patient, $branch, null, 'consultation');

    expect($encounter->organization_id)->toBe($organization->id)
        ->and($encounter->status)->toBe(VisitStatus::InProgress)
        ->and($patient->timelineEvents()->count())->toBe(1);

    // Complete
    $completedEncounter = $service->completeEncounter($encounter);

    expect($completedEncounter->status)->toBe(VisitStatus::Completed)
        ->and($completedEncounter->ended_at)->not->toBeNull()
        ->and($patient->timelineEvents()->count())->toBe(2);
});
