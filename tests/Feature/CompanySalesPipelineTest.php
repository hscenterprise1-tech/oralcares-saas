<?php

use App\DemoOutcome;
use App\Models\CompanyEmployee;
use App\Models\CompanyOpportunity;
use App\Models\CompanyReminder;
use App\Models\Lead;
use App\OpportunityStage;
use App\Services\DemoService;
use App\Services\SalesOpportunityService;
use App\Services\TrialService;
use App\TrialStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('sales opportunity can be created and stage transitioned', function () {
    $employee = CompanyEmployee::factory()->create();
    $lead = Lead::factory()->create();

    $service = app(SalesOpportunityService::class);
    $opportunity = $service->createOpportunity([
        'lead_id' => $lead->id,
        'title' => 'Dental Software Enterprise Deal',
        'value' => 5000.00,
    ], $employee);

    expect($opportunity)->not->toBeNull()
        ->and($opportunity->stage)->toBe(OpportunityStage::New)
        ->and((float) $opportunity->value)->toBe(5000.00);

    expect($opportunity->activities)->toHaveCount(1)
        ->and($opportunity->activities->first()->event)->toBe('Opportunity Created');

    $service->transitionStage($opportunity, OpportunityStage::Qualified, $employee, 'Lead meets budget criteria.');

    expect($opportunity->fresh()->stage)->toBe(OpportunityStage::Qualified)
        ->and($opportunity->fresh()->activities)->toHaveCount(2);

    $latestActivity = $opportunity->fresh()->activities->last();
    expect($latestActivity->event)->toBe('Stage Transitioned')
        ->and($latestActivity->description)->toContain('Lead meets budget criteria.');
});

test('demo can be scheduled and outcome logged', function () {
    $employee = CompanyEmployee::factory()->create();
    $opportunity = CompanyOpportunity::factory()->create(['assigned_id' => $employee->id]);

    $demoService = app(DemoService::class);
    $demo = $demoService->scheduleDemo($opportunity, [
        'scheduled_at' => now()->addDays(2),
        'presenter_id' => $employee->id,
        'meeting_link' => 'https://meet.google.com/abc-defg-hij',
        'notes' => 'Introduction demo for clinic manager.',
    ], $employee);

    expect($demo)->not->toBeNull()
        ->and($demo->outcome)->toBe(DemoOutcome::Scheduled);

    expect($opportunity->fresh()->stage)->toBe(OpportunityStage::DemoScheduled);

    $demoService->completeDemo($demo, DemoOutcome::Completed, 'Client loved the WhatsApp integration module.', $employee);

    expect($demo->fresh()->outcome)->toBe(DemoOutcome::Completed)
        ->and($demo->fresh()->feedback)->toBe('Client loved the WhatsApp integration module.');

    expect($opportunity->fresh()->stage)->toBe(OpportunityStage::DemoCompleted);
});

test('trial can be created and extended', function () {
    $employee = CompanyEmployee::factory()->create();
    $opportunity = CompanyOpportunity::factory()->create(['assigned_id' => $employee->id]);

    $trialService = app(TrialService::class);
    $trial = $trialService->createTrial($opportunity, [
        'start_date' => now(),
        'end_date' => now()->addDays(14),
        'max_users' => 10,
        'max_branches' => 2,
        'max_doctors' => 5,
        'storage_limit_gb' => 20,
        'modules_enabled' => ['patients', 'calendar', 'billing'],
        'feature_flags' => ['whatsapp_integration' => true],
    ], $employee);

    expect($trial)->not->toBeNull()
        ->and($trial->status)->toBe(TrialStatus::Active)
        ->and($trial->max_users)->toBe(10)
        ->and($trial->max_doctors)->toBe(5)
        ->and($trial->storage_limit_gb)->toBe(20)
        ->and($trial->modules_enabled)->toContain('patients', 'calendar', 'billing')
        ->and($trial->feature_flags['whatsapp_integration'])->toBeTrue();

    expect($opportunity->fresh()->stage)->toBe(OpportunityStage::TrialActive);

    $newEndDate = now()->addDays(30)->toDateString();
    $extension = $trialService->extendTrial($trial, $newEndDate, 'Client requested two more weeks for billing team review.', $employee);

    expect($trial->fresh()->status)->toBe(TrialStatus::Extended)
        ->and($trial->fresh()->end_date->toDateString())->toBe($newEndDate)
        ->and($trial->fresh()->extensions)->toHaveCount(1);

    expect($extension->reason)->toBe('Client requested two more weeks for billing team review.')
        ->and($extension->previous_end_date->toDateString())->toBe(now()->addDays(14)->toDateString())
        ->and($extension->new_end_date->toDateString())->toBe($newEndDate);

    $latestActivity = $opportunity->fresh()->activities->last();
    expect($latestActivity->event)->toBe('Trial Extended')
        ->and($latestActivity->description)->toContain('Reason: Client requested two more weeks');
});

test('reminders can be registered and fetched', function () {
    $opportunity = CompanyOpportunity::factory()->create();

    $reminder = CompanyReminder::create([
        'opportunity_id' => $opportunity->id,
        'reminder_type' => 'trial_expiry',
        'reminder_date' => now()->addDays(5),
        'status' => 'pending',
        'notes' => 'Call lead to review trial experience before expiry.',
    ]);

    expect($reminder->id)->not->toBeNull()
        ->and($reminder->reminder_type)->toBe('trial_expiry')
        ->and($reminder->status)->toBe('pending')
        ->and($reminder->opportunity->id)->toBe($opportunity->id);
});
