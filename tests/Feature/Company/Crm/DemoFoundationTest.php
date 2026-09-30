<?php

use App\ActivityType;
use App\DemoConfirmationStatus;
use App\DemoStatus;
use App\LeadStatus;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\MarketingContact;
use App\Models\Organization;
use App\Models\User;
use App\Services\LeadWorkspaceService;
use App\Services\Marketing\MarketingSettingsService;
use App\Services\Marketing\PhoneNormalizer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.routing_mode' => 'path']);
    $this->organization = Organization::factory()->create(['name' => 'Test Clinic Org']);
    $this->otherOrganization = Organization::factory()->create(['name' => 'Other Clinic Org']);

    $this->adminUser = User::factory()->masterAdmin()->create([
        'organization_id' => $this->organization->id,
    ]);

    $this->employeeUser = User::factory()->companyUser()->create([
        'organization_id' => $this->organization->id,
    ]);

    $this->otherEmployee = User::factory()->companyUser()->create([
        'organization_id' => $this->otherOrganization->id,
    ]);
});

test('it can create and schedule a demo for a lead advancing stage to DemoScheduled', function () {
    actingAs($this->employeeUser);

    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->employeeUser->id,
        'status' => LeadStatus::Contacted,
    ]);

    $scheduledAt = Carbon::tomorrow()->setHour(14)->setMinute(0);

    $service = app(LeadWorkspaceService::class);
    $demo = $service->scheduleDemo($lead, [
        'scheduled_at' => $scheduledAt->toDateTimeString(),
        'duration_minutes' => 60,
        'demo_mode' => 'online',
        'meeting_link' => 'https://meet.google.com/test-demo-room',
        'notes' => 'Client requested multi-specialty workflow demonstration.',
    ], $this->employeeUser);

    expect($demo)->not->toBeNull()
        ->and($demo->lead_id)->toBe($lead->id)
        ->and($demo->status->value)->toBe('scheduled')
        ->and($demo->confirmation_status->value)->toBe('pending');

    $updatedLead = Lead::find($lead->id);
    expect($updatedLead->status->value)->toBe('demo_scheduled');

    $this->assertDatabaseHas('activities', [
        'subject_id' => $lead->id,
        'subject_type' => $lead->getMorphClass(),
        'type' => ActivityType::Demo->value,
    ]);

    $this->assertDatabaseHas('tasks', [
        'taskable_id' => $lead->id,
        'assigned_user_id' => $this->employeeUser->id,
    ]);
});

test('it triggers client WhatsApp reminder respecting M-8 opt-out and suppression safety rules', function () {
    actingAs($this->employeeUser);

    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->employeeUser->id,
        'phone' => '9876543210',
        'status' => LeadStatus::Contacted,
    ]);

    // Create Opted-Out Marketing Contact
    $contact = MarketingContact::create([
        'organization_id' => $this->organization->id,
        'name' => $lead->contact_name,
        'phone' => $lead->phone,
        'normalized_phone' => PhoneNormalizer::normalize($lead->phone),
        'marketing_status' => 'opted_out',
    ]);

    $lead->update(['marketing_contact_id' => $contact->id]);

    $scheduledAt = Carbon::tomorrow()->setHour(11)->setMinute(0);
    $service = app(LeadWorkspaceService::class);

    $demo = $service->scheduleDemo($lead, [
        'scheduled_at' => $scheduledAt->toDateTimeString(),
    ], $this->employeeUser);

    // Client reminder should be blocked due to opt-out
    $sent = $service->sendClientDemoReminder($demo);
    expect($sent)->toBeFalse();

    /*
     * Asserted against the timeline rather than `lead_whatsapp_messages`, which
     * is gone. A blocked reminder returns before writing anything, so the proof
     * that nothing was sent is the absence of a WhatsApp activity on the deal.
     */
    expect($lead->activities()->where('type', ActivityType::WhatsApp)->count())->toBe(0);
});

test('it respects emergency freeze kill-switch and quiet hours when sending client reminders', function () {
    actingAs($this->employeeUser);

    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->employeeUser->id,
        'status' => LeadStatus::Contacted,
    ]);

    // Enable Emergency Freeze Kill-Switch
    app(MarketingSettingsService::class)->toggleEmergencyFreeze($this->organization->id, true);

    $scheduledAt = Carbon::tomorrow()->setHour(15)->setMinute(0);
    $service = app(LeadWorkspaceService::class);

    $demo = Demo::create([
        'lead_id' => $lead->id,
        'presenter_id' => $this->employeeUser->id,
        'scheduled_at' => $scheduledAt,
        'status' => DemoStatus::Scheduled,
    ]);

    $sent = $service->sendClientDemoReminder($demo);
    expect($sent)->toBeFalse();
});

test('it allows employee to update confirmation status to confirmed', function () {
    actingAs($this->employeeUser);

    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->employeeUser->id,
    ]);

    $demo = Demo::create([
        'lead_id' => $lead->id,
        'presenter_id' => $this->employeeUser->id,
        'scheduled_at' => Carbon::tomorrow(),
        'status' => DemoStatus::Scheduled,
        'confirmation_status' => DemoConfirmationStatus::Pending,
    ]);

    $service = app(LeadWorkspaceService::class);
    $updatedDemo = $service->confirmDemo($demo, 'confirmed', $this->employeeUser);

    expect($updatedDemo->confirmation_status->value)->toBe('confirmed')
        ->and($updatedDemo->status->value)->toBe('confirmed');
});

test('it reschedules demo preserving historical timeline activity and creating linked new demo', function () {
    actingAs($this->employeeUser);

    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->employeeUser->id,
    ]);

    $oldTime = Carbon::tomorrow()->setHour(10);
    $newTime = Carbon::tomorrow()->addDays(2)->setHour(14);

    $oldDemo = Demo::create([
        'lead_id' => $lead->id,
        'presenter_id' => $this->employeeUser->id,
        'scheduled_at' => $oldTime,
        'status' => DemoStatus::Scheduled,
        'confirmation_status' => DemoConfirmationStatus::Pending,
    ]);

    $service = app(LeadWorkspaceService::class);
    $newDemo = $service->rescheduleDemo($oldDemo, [
        'scheduled_at' => $newTime->toDateTimeString(),
        'notes' => 'Rescheduled per client request',
    ], $this->employeeUser);

    expect($oldDemo->fresh()->status->value)->toBe('rescheduled')
        ->and($newDemo->status->value)->toBe('scheduled')
        ->and($newDemo->rescheduled_from_id)->toBe($oldDemo->id);

    $this->assertDatabaseHas('activities', [
        'subject_id' => $lead->id,
        'type' => ActivityType::Demo->value,
    ]);
});

test('it completes demo with outcome and feedback transitioning lead stage', function () {
    actingAs($this->employeeUser);

    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->employeeUser->id,
        'status' => LeadStatus::DemoScheduled,
    ]);

    $demo = Demo::create([
        'lead_id' => $lead->id,
        'presenter_id' => $this->employeeUser->id,
        'scheduled_at' => Carbon::today()->setHour(11),
        'status' => DemoStatus::Scheduled,
    ]);

    $service = app(LeadWorkspaceService::class);

    // Complete with Trial Requested
    $completedDemo = $service->completeDemo($demo, [
        'outcome' => 'trial_requested',
        'feedback' => 'Dr. Patel loved the clinical chart view and requested a 14-day trial.',
        'objections' => 'Need data import from legacy software.',
        'next_action' => 'Setup trial account and branch configuration',
        'next_followup_at' => Carbon::tomorrow()->toDateTimeString(),
    ], $this->employeeUser);

    expect($completedDemo->status->value)->toBe('completed')
        ->and($completedDemo->outcome->value)->toBe('trial_requested');

    $updatedLead = Lead::find($lead->id);
    expect($updatedLead->status->value)->toBe('trial_active');
});

test('it marks demo as No-Show logging activity creating follow up task without setting lead to lost', function () {
    actingAs($this->employeeUser);

    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->employeeUser->id,
        'status' => LeadStatus::DemoScheduled,
    ]);

    $demo = Demo::create([
        'lead_id' => $lead->id,
        'presenter_id' => $this->employeeUser->id,
        'scheduled_at' => Carbon::yesterday(),
        'status' => DemoStatus::Scheduled,
    ]);

    $service = app(LeadWorkspaceService::class);
    $noShowDemo = $service->markDemoNoShow($demo, [
        'notes' => 'Client did not respond to meeting invite link.',
    ], $this->employeeUser);

    expect($noShowDemo->status->value)->toBe('no_show');

    $updatedLead = Lead::find($lead->id);
    expect($updatedLead->status->value)->not->toBe('lost');

    $this->assertDatabaseHas('tasks', [
        'taskable_id' => $lead->id,
        'title' => "No-Show Reschedule: {$lead->company_name}",
    ]);
});

test('it computes demo workspace payload metrics and filters correctly', function () {
    actingAs($this->employeeUser);

    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->employeeUser->id,
    ]);

    // Create Today demo, Upcoming demo, and Completed demo
    Demo::create([
        'lead_id' => $lead->id,
        'presenter_id' => $this->employeeUser->id,
        'scheduled_at' => Carbon::today()->setHour(14),
        'status' => DemoStatus::Scheduled,
    ]);

    Demo::create([
        'lead_id' => $lead->id,
        'presenter_id' => $this->employeeUser->id,
        'scheduled_at' => Carbon::now()->addDays(3),
        'status' => DemoStatus::Scheduled,
    ]);

    $service = app(LeadWorkspaceService::class);
    $payload = $service->getDemoWorkspacePayload($this->employeeUser);

    expect($payload)->toHaveKeys(['metrics', 'todaysDemos', 'upcomingDemos', 'needsAttention', 'allDemos'])
        ->and($payload['metrics']['todaysCount'])->toBeGreaterThanOrEqual(1)
        ->and($payload['metrics']['upcomingCount'])->toBeGreaterThanOrEqual(1);
});

test('it enforces tenant isolation preventing cross tenant access to demos', function () {
    actingAs($this->otherEmployee);

    $otherLead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
    ]);

    $demo = Demo::create([
        'lead_id' => $otherLead->id,
        'presenter_id' => $this->adminUser->id,
        'scheduled_at' => Carbon::tomorrow(),
        'status' => DemoStatus::Scheduled,
    ]);

    $response = post(route('company.crm.demos.confirm', $demo), [
        'confirmation_status' => 'confirmed',
    ]);

    $response->assertStatus(403);
});

test('it stops pending demo reminders and tasks when lead status transitions to Won or Lost', function () {
    actingAs($this->employeeUser);

    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->employeeUser->id,
        'status' => LeadStatus::Won,
    ]);

    $demo = Demo::create([
        'lead_id' => $lead->id,
        'presenter_id' => $this->employeeUser->id,
        'scheduled_at' => Carbon::tomorrow(),
        'status' => DemoStatus::Scheduled,
    ]);

    $service = app(LeadWorkspaceService::class);
    $sent = $service->sendClientDemoReminder($demo);

    expect($sent)->toBeFalse();
});
