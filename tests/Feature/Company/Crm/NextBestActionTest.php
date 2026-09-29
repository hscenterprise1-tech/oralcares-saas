<?php

use App\LeadStatus;
use App\Models\Lead;
use App\Services\CRM\NextBestActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->nbaService = new NextBestActionService;
});

/**
 * Phase 1 item 9 changed this contract deliberately.
 *
 * The organisation-profile prompt is gone: it fired on an optional field and
 * outranked every stage-aware branch, which is why twelve of fourteen leads in
 * the running app showed one identical sentence. Size is now the only
 * qualification signal, and only while the lead is being qualified.
 */
it('determines next best action dynamically', function () {
    $lead = Lead::factory()->create([
        'status' => LeadStatus::New,
        'assigned_user_id' => null,
        'expected_mrr' => 0,
        'expected_users' => 0,
    ]);

    // Size unknown, and the lead is at a qualifying stage.
    expect($this->nbaService->determineNextAction($lead))
        ->toBe('Qualify — capture expected users or monthly value.');

    // Either size signal is enough; the optional profile fields stay blank and
    // must not produce a prompt of their own.
    $lead->update(['expected_users' => 10, 'organization_type' => null]);

    expect($this->nbaService->determineNextAction($lead))
        ->toBe('Make first contact.');

    // Filling the optional fields changes nothing.
    $lead->update(['organization_type' => 'hospital', 'healthcare_specialty' => 'general']);

    expect($this->nbaService->determineNextAction($lead))
        ->toBe('Make first contact.');

    // Stage drives the advice.
    $lead->update(['status' => LeadStatus::Contacted]);

    expect($this->nbaService->determineNextAction($lead))
        ->toBe('Book a demo.');
});
