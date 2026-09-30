<?php

use App\Models\Lead;
use App\Models\Organization;
use App\Services\LeadWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase 1 — lead duplicate detection.
 *
 * Detection is a warning, not a block: reps must still be able to create the
 * lead (a genuine second enquiry from the same clinic is legitimate), but the
 * collision has to be visible and auditable.
 */
beforeEach(function () {
    $this->org = Organization::factory()->create();
});

it('detects a duplicate by normalized phone even when formatting differs', function () {
    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'company_name' => 'Apex Dental',
        'phone' => '+91 98765 43210',
        'normalized_phone' => '9876543210',
    ]);

    $duplicate = app(LeadWorkspaceService::class)->findDuplicateLead(
        $this->org->id,
        '098765-43210',
        null
    );

    expect($duplicate)->not->toBeNull()
        ->and($duplicate->company_name)->toBe('Apex Dental');
});

it('detects a duplicate by email case-insensitively', function () {
    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'email' => 'owner@apexdental.in',
    ]);

    $duplicate = app(LeadWorkspaceService::class)->findDuplicateLead(
        $this->org->id,
        null,
        'OWNER@ApexDental.IN'
    );

    expect($duplicate)->not->toBeNull();
});

it('detects a duplicate by company name', function () {
    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'company_name' => 'Northgate Dental Care',
    ]);

    $duplicate = app(LeadWorkspaceService::class)->findDuplicateLead(
        $this->org->id,
        null,
        null,
        null,
        'northgate dental care'
    );

    expect($duplicate)->not->toBeNull();
});

it('returns nothing when there is no identifying information', function () {
    Lead::factory()->count(3)->create(['organization_id' => $this->org->id]);

    $duplicate = app(LeadWorkspaceService::class)->findDuplicateLead($this->org->id, null, null);

    expect($duplicate)->toBeNull();
});

it('does not treat a different clinic as a duplicate', function () {
    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'company_name' => 'Apex Dental',
        'normalized_phone' => '9876543210',
        'email' => 'a@apex.in',
    ]);

    $duplicate = app(LeadWorkspaceService::class)->findDuplicateLead(
        $this->org->id,
        '+919999900000',
        'different@clinic.in',
        null,
        'Completely Different Clinic'
    );

    expect($duplicate)->toBeNull();
});

it('still creates the lead but reports the duplicate', function () {
    $existing = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'company_name' => 'Apex Dental',
        'normalized_phone' => '9876543210',
    ]);

    $result = app(LeadWorkspaceService::class)->createLead([
        'company_name' => 'Apex Dental Second Enquiry',
        'contact_name' => 'Dr. Repeat',
        'phone' => '+919876543210',
        'organization_id' => $this->org->id,
        'source' => 'manual_entry',
    ]);

    expect($result['duplicate_detected'])->toBeTrue()
        ->and($result['duplicate_lead_id'])->toBe($existing->id)
        ->and($result['lead'])->not->toBeNull();
});

it('records the duplicate warning on the lead timeline', function () {
    $existing = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'company_name' => 'Apex Dental',
        'normalized_phone' => '9876543210',
    ]);

    $result = app(LeadWorkspaceService::class)->createLead([
        'company_name' => 'Apex Dental Repeat',
        'contact_name' => 'Dr. Repeat',
        'phone' => '+919876543210',
        'organization_id' => $this->org->id,
        'source' => 'manual_entry',
    ]);

    $descriptions = $result['lead']->activities()->pluck('description')->implode(' ');

    expect($descriptions)->toContain("Possible duplicate of Lead #{$existing->id}");
});

it('scopes duplicate detection to the organization', function () {
    $otherOrg = Organization::factory()->create();

    Lead::factory()->create([
        'organization_id' => $otherOrg->id,
        'normalized_phone' => '9876543210',
    ]);

    $duplicate = app(LeadWorkspaceService::class)->findDuplicateLead(
        $this->org->id,
        '+919876543210',
        null
    );

    expect($duplicate)->toBeNull();
});
