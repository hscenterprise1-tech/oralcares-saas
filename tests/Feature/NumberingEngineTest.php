<?php

use App\Models\Branch;
use App\Models\Organization;
use App\Services\Core\NumberingEngine;
use Carbon\Carbon;

it('generates sequential numbers correctly', function () {
    $organization = Organization::factory()->create();
    $engine = new NumberingEngine;

    $id1 = $engine->generate('patient', $organization, null, ['prefix' => 'PT-', 'padding' => 4]);
    $id2 = $engine->generate('patient', $organization, null, ['prefix' => 'PT-', 'padding' => 4]);

    expect($id1)->toBe('PT-0001')
        ->and($id2)->toBe('PT-0002');
});

it('isolates sequences between organizations', function () {
    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();
    $engine = new NumberingEngine;

    $id1 = $engine->generate('invoice', $org1, null, ['prefix' => 'INV-']);
    $id2 = $engine->generate('invoice', $org2, null, ['prefix' => 'INV-']);
    $id3 = $engine->generate('invoice', $org1, null, ['prefix' => 'INV-']);

    expect($id1)->toBe('INV-1')
        ->and($id2)->toBe('INV-1')
        ->and($id3)->toBe('INV-2');
});

it('isolates sequences between branches if provided', function () {
    $org = Organization::factory()->create();
    $branch1 = Branch::factory()->create(['organization_id' => $org->id]);
    $branch2 = Branch::factory()->create(['organization_id' => $org->id]);
    $engine = new NumberingEngine;

    $id1 = $engine->generate('receipt', $org, $branch1, ['prefix' => 'B1-']);
    $id2 = $engine->generate('receipt', $org, $branch2, ['prefix' => 'B2-']);

    expect($id1)->toBe('B1-1')
        ->and($id2)->toBe('B2-1');
});

it('resets calendar yearly', function () {
    $organization = Organization::factory()->create();
    $engine = new NumberingEngine;

    // Set now to late 2025
    Carbon::setTestNow('2025-12-31 23:59:59');

    $id1 = $engine->generate('invoice', $organization, null, [
        'prefix' => 'INV-',
        'padding' => 4,
        'reset_frequency' => 'calendar_yearly',
    ]);

    expect($id1)->toBe('INV-0001');

    // Roll over to 2026
    Carbon::setTestNow('2026-01-01 00:00:01');
    $id2 = $engine->generate('invoice', $organization, null, [
        'prefix' => 'INV-',
        'padding' => 4,
        'reset_frequency' => 'calendar_yearly', // Should trigger reset
    ]);

    expect($id2)->toBe('INV-0001');

    Carbon::setTestNow(); // reset
});
