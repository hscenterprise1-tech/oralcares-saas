<?php

use App\Models\Branch;
use App\Models\ClinicalEncounter;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\User;
use App\Services\ClinicalPrescriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('issues a prescription with multiple items', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $patient = Patient::factory()->create(['organization_id' => $organization->id]);

    $encounter = ClinicalEncounter::create([
        'organization_id' => $organization->id,
        'patient_id' => $patient->id,
        'branch_id' => Branch::factory()->create(['organization_id' => $organization->id, 'clinic_id' => $patient->clinic_id])->id,
        'encounter_number' => 'ENC-003',
        'status' => 'in_progress',
        'started_at' => now(),
    ]);

    $this->actingAs($user);

    $service = new ClinicalPrescriptionService;

    $prescription = $service->issuePrescription($encounter, [
        'notes' => 'Take with food',
        'details' => ['pharmacy_routing' => 'pending'],
    ], [
        [
            'drug_name' => 'Amoxicillin 500mg',
            'dosage' => '1 cap',
            'frequency' => 'TID',
            'duration' => '7 days',
            'instructions' => 'Finish all pills',
        ],
        [
            'drug_name' => 'Ibuprofen 400mg',
            'dosage' => '1 tab',
            'frequency' => 'PRN',
            'duration' => '3 days',
            'instructions' => 'For pain',
        ],
    ]);

    expect($prescription->prescription_number)->toStartWith('RX-')
        ->and($prescription->notes)->toBe('Take with food')
        ->and($prescription->prescribed_by)->toBe($user->id)
        ->and($prescription->details['pharmacy_routing'])->toBe('pending')
        ->and($prescription->items()->count())->toBe(2);

    $firstItem = $prescription->items()->first();
    expect($firstItem->drug_name)->toBe('Amoxicillin 500mg')
        ->and($firstItem->duration)->toBe('7 days');

    expect($patient->timelineEvents()->where('event_type', 'prescription_issued')->count())->toBe(1);
});
