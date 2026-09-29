<?php

use App\Models\ClinicalEncounter;
use App\Models\InventoryItem;
use App\Models\Organization;
use App\Models\StockLocation;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('receives stock and correctly deducts it using FEFO logic', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($user);

    $location = StockLocation::create([
        'organization_id' => $organization->id,
        'name' => 'Main Pharmacy',
        'type' => 'pharmacy',
    ]);

    $item = InventoryItem::create([
        'organization_id' => $organization->id,
        'item_type' => 'drug',
        'name' => 'Amoxicillin 500mg',
        'unit_of_measure' => 'tablet',
    ]);

    $service = new InventoryService;

    // Receive Batch A (Expires sooner)
    $service->receiveStock($location, $item, 100, 'LOT-A', now()->addDays(30)->toDateString());

    // Receive Batch B (Expires later)
    $service->receiveStock($location, $item, 200, 'LOT-B', now()->addDays(90)->toDateString());

    expect($item->batches()->count())->toBe(2)
        ->and((float) $item->transactions()->where('transaction_type', 'receive')->sum('quantity'))->toBe(300.0);

    // Mock a clinical procedure reference
    $encounter = ClinicalEncounter::factory()->create(['organization_id' => $organization->id]);

    // Deduct 150 items
    $service->deductStock($location, $item, 150, 'consume', $encounter);

    // Assert Batch A was completely depleted (100)
    $batchA = $item->batches()->where('lot_number', 'LOT-A')->first();
    expect((float) $batchA->quantity_on_hand)->toBe((float) 0);

    // Assert Batch B was partially depleted (50 taken, 150 remaining)
    $batchB = $item->batches()->where('lot_number', 'LOT-B')->first();
    expect((float) $batchB->quantity_on_hand)->toBe((float) 150);

    // Assert exactly 2 deduction transactions were logged pointing to the encounter
    $deductions = $item->transactions()->where('transaction_type', 'consume')->get();
    expect($deductions->count())->toBe(2)
        ->and($deductions->first()->referenceable_id)->toBe($encounter->id);
});
