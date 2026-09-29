<?php

use App\Models\Branch;
use App\Models\ClinicalEncounter;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\User;
use App\Services\ClinicalOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('places a generic clinical order with multiple items', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $patient = Patient::factory()->create(['organization_id' => $organization->id]);

    $encounter = ClinicalEncounter::create([
        'organization_id' => $organization->id,
        'patient_id' => $patient->id,
        'branch_id' => Branch::factory()->create(['organization_id' => $organization->id, 'clinic_id' => $patient->clinic_id])->id,
        'encounter_number' => 'ENC-004',
        'status' => 'in_progress',
        'started_at' => now(),
    ]);

    $this->actingAs($user);

    $service = new ClinicalOrderService;

    $order = $service->placeOrder($encounter, [
        'order_type' => 'laboratory',
        'priority' => 'stat',
        'clinical_instructions' => 'Fasting required',
    ], [
        [
            'item_name' => 'Complete Blood Count (CBC)',
        ],
        [
            'item_name' => 'Lipid Panel',
        ],
    ]);

    expect($order->order_number)->toStartWith('ORD-')
        ->and($order->order_type)->toBe('laboratory')
        ->and($order->priority)->toBe('stat')
        ->and($order->clinical_instructions)->toBe('Fasting required')
        ->and($order->ordered_by)->toBe($user->id)
        ->and($order->items()->count())->toBe(2);

    expect($patient->timelineEvents()->where('event_type', 'clinical_order_placed')->count())->toBe(1);
});

it('auto-completes order when all items are completed', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $patient = Patient::factory()->create(['organization_id' => $organization->id]);

    $encounter = ClinicalEncounter::create([
        'organization_id' => $organization->id,
        'patient_id' => $patient->id,
        'branch_id' => Branch::factory()->create(['organization_id' => $organization->id, 'clinic_id' => $patient->clinic_id])->id,
        'encounter_number' => 'ENC-005',
        'status' => 'in_progress',
        'started_at' => now(),
    ]);

    $service = new ClinicalOrderService;

    $order = $service->placeOrder($encounter, [
        'order_type' => 'imaging',
        'priority' => 'routine',
    ], [
        ['item_name' => 'Chest X-Ray'],
    ]);

    expect($order->status)->toBe('active');

    $item = $order->items()->first();
    $service->updateItemStatus($item, 'completed');

    $order->refresh();

    expect($order->status)->toBe('completed');
    expect($patient->timelineEvents()->where('event_type', 'clinical_order_completed')->count())->toBe(1);
});
