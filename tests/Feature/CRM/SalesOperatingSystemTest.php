<?php

use App\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadAiCopilotService;
use App\Services\LeadWorkspaceService;
use App\Services\SalesAutomationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('sales automation engine ingests lead with deduplication and welcome whatsapp', function () {
    $engine = app(SalesAutomationEngine::class);

    $result = $engine->ingestLead([
        'company_name' => 'MaxCare Multispecialty Hospital',
        'contact_name' => 'Dr. Rajesh Gupta',
        'email' => 'gupta@maxcare.com',
        'phone' => '+919988776655',
        'source' => 'website_demo',
        'expected_mrr' => 35000,
    ]);

    expect($result['isDuplicate'])->toBeFalse();
    $lead = $result['lead'];

    expect($lead->id)->not->toBeNull();
    /*
     * The welcome goes through the Marketing account now. Without a connected
     * one it is recorded as undelivered rather than as a send that never
     * happened — which is the point of the change.
     */
    $whatsapp = $lead->activities()->where('type', 'whatsapp')->first();
    expect($whatsapp)->not->toBeNull()
        ->and($whatsapp->description)->toContain('WhatsApp');

    // Ingest duplicate phone
    $dupResult = $engine->ingestLead([
        'company_name' => 'MaxCare Duplicate',
        'email' => 'other@maxcare.com',
        'phone' => '+919988776655',
        'source' => 'facebook_ads',
    ]);

    expect($dupResult['isDuplicate'])->toBeTrue();
    expect($dupResult['lead']->id)->toBe($lead->id);
});

test('call disposition updates stage and adds call log', function () {
    $engine = app(SalesAutomationEngine::class);
    $user = User::factory()->create();

    $lead = Lead::factory()->create([
        'status' => LeadStatus::New,
    ]);

    $updatedLead = $engine->logCallDisposition($lead, [
        'duration_seconds' => 120,
        'disposition' => 'interested',
        'notes' => 'Client requested multi-branch quote',
    ], $user);

    expect($updatedLead->status->value)->toBe('contacted');
    expect($updatedLead->callLogs)->not->toBeEmpty();
    expect($updatedLead->callLogs->first()->disposition)->toBe('interested');
});

test('ai copilot service computes buying probability and next best action', function () {
    $copilot = app(LeadAiCopilotService::class);

    $lead = Lead::factory()->create([
        'company_name' => 'Apex Eye Hospital',
        'contact_name' => 'Dr. S. K. Roy',
        'status' => LeadStatus::DemoCompleted,
        'expected_mrr' => 40000,
        'lead_score' => 88,
    ]);

    $analysis = $copilot->analyzeLead($lead);

    expect($analysis)->toHaveKeys(['buyingProbability', 'leadScore', 'riskLevel', 'nextBestAction', 'suggestedReply']);
    expect($analysis['buyingProbability'])->toBeGreaterThan(50);
    expect($analysis['nextBestAction'])->toContain('Quotation');
});

test('lead workspace service returns sales os payload with active lead context', function () {
    $workspace = app(LeadWorkspaceService::class);

    $lead = Lead::factory()->create([
        'company_name' => 'Lifeline Diagnostic Center',
    ]);

    $payload = $workspace->getSalesOsPayload([], $lead->id);

    expect($payload)->toHaveKeys(['metrics', 'pipelineStages', 'leadsList', 'activeLead', 'copilotAnalysis']);
    expect($payload['activeLead']->id)->toBe($lead->id);
    expect($payload['copilotAnalysis'])->not->toBeNull();
});
