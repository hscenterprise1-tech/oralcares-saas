<?php

use App\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('lead workspace service builds full payload with grouped stages and metrics', function () {
    $service = app(LeadWorkspaceService::class);

    Lead::factory()->create([
        'company_name' => 'SmileCare Dental',
        'status' => LeadStatus::New,
        'expected_mrr' => 15000,
        'priority' => 'high',
    ]);

    Lead::factory()->create([
        'company_name' => 'City Multispecialty Hospital',
        'status' => LeadStatus::Won,
        'expected_mrr' => 45000,
    ]);

    $payload = $service->getWorkspacePayload();

    expect($payload)->toHaveKeys(['metrics', 'pipelineStages', 'leadsList', 'filters', 'assignableUsers']);
    expect($payload['metrics']['totalLeadsCount'])->toBe(2);
    expect($payload['pipelineStages'])->not->toBeEmpty();
});

test('lead stage can be transitioned with audit activity created', function () {
    $service = app(LeadWorkspaceService::class);
    $user = User::factory()->create();

    $lead = Lead::factory()->create([
        'status' => LeadStatus::New,
    ]);

    $updatedLead = $service->transitionStage($lead, 'contacted', $user, 'Qualified budget requirement');

    expect($updatedLead->status->value)->toBe('contacted');
    expect($updatedLead->activities)->not->toBeEmpty();

    $latestActivity = $updatedLead->fresh()->activities()->latest('id')->first();
    expect($latestActivity->description)->toContain('Moved to Contacted from New');
});

test('communication log entry can be added to lead', function () {
    $service = app(LeadWorkspaceService::class);
    $user = User::factory()->create();

    $lead = Lead::factory()->create();

    $activity = $service->logCommunication($lead, [
        'type' => 'call',
        'summary' => 'Discussed multi-branch deployment pricing',
        'sentiment' => 'positive',
    ], $user);

    expect($activity->id)->not->toBeNull();
    expect($activity->description)->toContain('Discussed multi-branch deployment pricing');

    $latestActivity = $lead->fresh()->activities()->latest('id')->first();
    expect($latestActivity->description)->toContain('Discussed multi-branch deployment pricing');
});
