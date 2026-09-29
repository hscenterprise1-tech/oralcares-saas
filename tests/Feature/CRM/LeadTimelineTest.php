<?php

use App\ActivityType;
use App\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use App\Services\CRM\LeadManagementService;
use App\Services\CRM\LeadScoringEngine;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new LeadManagementService(new LeadScoringEngine);
});

it('logs activity when a lead is created', function () {
    $lead = $this->service->createLead([
        'company_name' => 'Timeline Test Corp',
        'contact_name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'phone' => '123456789',
        'status' => LeadStatus::New->value,
    ]);

    expect($lead->activities)->toHaveCount(1);

    $activity = $lead->activities->first();
    expect($activity->type)->toBe(ActivityType::SystemUpdate)
        ->and($activity->description)->toContain('Lead created');
});

it('logs activity when lead status changes', function () {
    $lead = Lead::factory()->create(['status' => LeadStatus::New]);

    // Clear initial creation activity
    $lead->activities()->delete();

    $this->service->updateLead($lead, ['status' => LeadStatus::Contacted]);

    $lead->refresh();

    expect($lead->activities)->toHaveCount(1);

    $activity = $lead->activities->first();
    expect($activity->type)->toBe(ActivityType::SystemUpdate)
        ->and($activity->description)->toBe('Moved to Contacted from New');
});

it('logs activity when lead is assigned', function () {
    $lead = Lead::factory()->create();
    $user = User::factory()->create(['name' => 'Sales Rep 1', 'user_type' => UserType::CompanyUser]);

    $lead->activities()->delete();

    $this->service->assignLead($lead, $user);

    $lead->refresh();

    expect($lead->activities)->toHaveCount(1);

    $activity = $lead->activities->first();
    expect($activity->type)->toBe(ActivityType::SystemUpdate)
        ->and($activity->description)->toBe('Lead reassigned to Sales Rep 1');
});
