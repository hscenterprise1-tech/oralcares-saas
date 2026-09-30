<?php

use App\Models\Lead;
use App\OrganizationType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a lead with generic healthcare attributes', function () {
    $lead = Lead::factory()->create([
        'organization_type' => 'diagnostic_center',
        'healthcare_specialty' => 'radiology',
        'organization_size' => '50-100',
        'branch_count' => 3,
        'expected_users' => 15,
        'expected_mrr' => 1500.00,
        'expected_arr' => 18000.00,
        'current_software' => 'Legacy RIS',
        'migration_required' => true,
        'deployment_preference' => 'cloud',
        'required_modules' => ['patients', 'billing', 'inventory'],
        'required_integrations' => ['pacs_system'],
    ]);

    expect($lead->organization_type)->toBe(OrganizationType::DiagnosticCenter)
        ->and($lead->healthcare_specialty)->toBe('radiology')
        ->and($lead->branch_count)->toBe(3)
        ->and($lead->expected_mrr)->toEqual(1500.00)
        ->and($lead->migration_required)->toBeTrue()
        ->and($lead->required_modules)->toBeArray()->toContain('patients');
});
