<?php

use App\Models\Lead;
use App\Services\CRM\LeadScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->scoringService = new LeadScoringService;
});

it('calculates health score based on data completeness', function () {
    $lead = Lead::factory()->create([
        'organization_type' => null,
        'organization_size' => null,
        'expected_arr' => 0,
        'expected_mrr' => 0,
        'expected_users' => 1,
        'current_software' => null,
    ]);

    // Minimum points for factory default contact_name, email, phone (3 x 5 = 15).
    // Plus recency points.
    $score = $this->scoringService->calculateScore($lead);
    expect($score)->toBeGreaterThanOrEqual(15);

    $lead->update([
        'organization_type' => 'hospital',
        'organization_size' => 'enterprise',
        'expected_arr' => 50000,
        'expected_users' => 50,
        'current_software' => 'Old CRM',
    ]);

    $score = $this->scoringService->calculateScore($lead);
    expect($score)->toBeGreaterThan(50);
});
