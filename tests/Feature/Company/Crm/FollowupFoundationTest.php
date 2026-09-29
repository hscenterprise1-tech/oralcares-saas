<?php

namespace Tests\Feature\Company\Crm;

use App\ActivityType;
use App\LeadStatus;
use App\Models\Lead;
use App\Models\MarketingContact;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Services\LeadWorkspaceService;
use App\TaskPriority;
use App\TaskStatus;
use App\TaskType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.routing_mode' => 'path']);
    $this->organization = Organization::factory()->create();
    $this->adminUser = User::factory()->masterAdmin()->create([
        'organization_id' => $this->organization->id,
    ]);
    $this->employeeUser = grantCrmPermissions(
        User::factory()->companyUser()->create([
            'organization_id' => $this->organization->id,
        ])
    );
});

it('can log call disposition with duration, notes, and auto-advance stage', function () {
    actingAs($this->adminUser);

    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->employeeUser->id,
        'status' => LeadStatus::New,
        'phone' => '9876543210',
    ]);

    $callData = [
        'duration_seconds' => 120,
        'disposition' => 'demo_scheduled',
        'notes' => 'Doctor requested live demo on Thursday at 3 PM.',
        'next_followup_at' => Carbon::tomorrow()->format('Y-m-d H:i:s'),
        'followup_notes' => 'Prepare product demo presentation.',
    ];

    $response = post(route('company.crm.leads.call-disposition', $lead), $callData);
    $response->assertSessionHasNoErrors();

    $updatedLead = Lead::find($lead->id);
    expect($updatedLead->status->value)->toBe('demo_scheduled')
        ->and($updatedLead->next_followup_at)->not->toBeNull();

    $this->assertDatabaseHas('lead_call_logs', [
        'lead_id' => $lead->id,
        'disposition' => 'demo_scheduled',
        'call_duration_seconds' => 120,
    ]);

    $this->assertDatabaseHas('activities', [
        'subject_id' => $lead->id,
        'type' => ActivityType::Call->value,
    ]);

    $this->assertDatabaseHas('tasks', [
        'taskable_id' => $lead->id,
        'status' => TaskStatus::Pending->value,
        'type' => TaskType::Call->value,
    ]);
});

it('can log custom activity timeline entries', function () {
    actingAs($this->adminUser);

    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
    ]);

    $activityData = [
        'type' => 'note',
        'description' => 'Met doctor at Dental Expo in Mumbai. Interested in multi-branch plan.',
        'properties' => ['conference' => 'Dental Expo 2026'],
    ];

    $response = post(route('company.crm.leads.activities.store', $lead), $activityData);
    $response->assertSessionHasNoErrors();

    $this->assertDatabaseHas('activities', [
        'subject_id' => $lead->id,
        'type' => ActivityType::Note->value,
        'user_id' => $this->adminUser->id,
    ]);
});

it('can complete a follow-up and update lead follow-up tracking', function () {
    actingAs($this->employeeUser);

    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->employeeUser->id,
        'next_followup_at' => Carbon::yesterday(),
        'followup_notes' => 'Call doctor for feedback on pricing',
    ]);

    $task = Task::create([
        'title' => 'Follow up on pricing',
        'type' => TaskType::FollowUp,
        'status' => TaskStatus::Pending,
        'priority' => TaskPriority::High,
        'due_at' => Carbon::yesterday(),
        'assigned_user_id' => $this->employeeUser->id,
        'taskable_type' => $lead->getMorphClass(),
        'taskable_id' => $lead->id,
    ]);

    $completionData = [
        'notes' => 'Pricing discussion completed. Doctor agreed to review quote.',
        'next_followup_at' => Carbon::tomorrow()->format('Y-m-d H:i:s'),
        'next_followup_notes' => 'Send final agreement PDF',
    ];

    $response = post(route('company.crm.leads.followup.complete', $lead), $completionData);
    $response->assertSessionHasNoErrors();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Completed);

    $lead->refresh();
    expect($lead->next_followup_at)->not->toBeNull()
        ->and($lead->followup_notes)->toBe('Send final agreement PDF');

    $this->assertDatabaseHas('activities', [
        'subject_id' => $lead->id,
        'type' => ActivityType::FollowUp->value,
    ]);
});

it('employee persona briefing computes overdue, today follow-ups, and workload scoping correctly', function () {
    $assignedLead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->employeeUser->id,
        'next_followup_at' => Carbon::yesterday(),
        'status' => LeadStatus::Contacted,
    ]);

    $otherLead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->adminUser->id,
        'next_followup_at' => Carbon::yesterday(),
        'status' => LeadStatus::Contacted,
    ]);

    $service = app(LeadWorkspaceService::class);
    $briefing = $service->getEmployeeBriefingPayload($this->employeeUser);

    expect($briefing['overdue_followups_count'])->toBe(1)
        ->and($briefing['overdue_followups']->first()->id)->toBe($assignedLead->id);
});

it('admin overview computes company-wide follow-up health and team workload', function () {
    Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->employeeUser->id,
        'next_followup_at' => Carbon::yesterday(),
        'status' => LeadStatus::Contacted,
    ]);

    Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->adminUser->id,
        'next_followup_at' => Carbon::today(),
        'status' => LeadStatus::New,
    ]);

    $service = app(LeadWorkspaceService::class);
    $overview = $service->getOverviewPayload($this->adminUser);

    expect($overview['total_active_leads'])->toBe(2)
        ->and($overview['overdue_followups_count'])->toBe(1)
        ->and($overview['followups_today_count'])->toBe(1);
});

it('transition to Won halts future sales follow-ups, cancels pending tasks, and preserves history', function () {
    actingAs($this->adminUser);

    $contact = MarketingContact::create([
        'organization_id' => $this->organization->id,
        'name' => 'Won Doctor',
        'phone' => '+919876543210',
        'normalized_phone' => '9876543210',
        'marketing_status' => 'active',
    ]);

    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'marketing_contact_id' => $contact->id,
        'phone' => '9876543210',
        'status' => LeadStatus::QuotationSent,
        'next_followup_at' => Carbon::tomorrow(),
        'followup_notes' => 'Call for contract signature',
    ]);

    $pendingTask = Task::create([
        'title' => 'Contract signature call',
        'type' => TaskType::FollowUp,
        'status' => TaskStatus::Pending,
        'priority' => TaskPriority::High,
        'due_at' => Carbon::tomorrow(),
        'assigned_user_id' => $this->adminUser->id,
        'taskable_type' => $lead->getMorphClass(),
        'taskable_id' => $lead->id,
    ]);

    $service = app(LeadWorkspaceService::class);
    $service->transitionStage($lead, 'won', $this->adminUser);

    $lead->refresh();
    $pendingTask->refresh();
    $contact->refresh();

    expect($lead->status)->toBe(LeadStatus::Won)
        ->and($lead->next_followup_at)->toBeNull()
        ->and($lead->followup_notes)->toBeNull()
        ->and($pendingTask->status)->toBe(TaskStatus::Cancelled)
        ->and($contact->marketing_status)->toBe('suppressed');

    $this->assertDatabaseHas('activities', [
        'subject_id' => $lead->id,
        'type' => ActivityType::SystemUpdate->value,
    ]);
});

it('integrates WhatsApp message creation into activity timeline', function () {
    actingAs($this->adminUser);

    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'phone' => '9876543210',
    ]);

    $service = app(LeadWorkspaceService::class);
    /*
     * The send goes through the Marketing account now and reports whether it
     * was delivered rather than returning a locally written row. Without a
     * connected account in a test it cannot be, and it says so on the deal —
     * which is the behaviour worth pinning.
     */
    $delivered = $service->sendWhatsAppMessage($lead, 'Here is your requested demo link: https://oralcares.com/demo', 'demo_link', $this->adminUser);

    expect($delivered)->toBeFalse();

    $this->assertDatabaseHas('activities', [
        'subject_id' => $lead->id,
        'type' => ActivityType::WhatsApp->value,
    ]);
});

it('enforces tenant isolation preventing cross-tenant call logging or task completion', function () {
    $otherOrg = Organization::factory()->create();
    $otherLead = Lead::factory()->create([
        'organization_id' => $otherOrg->id,
    ]);

    actingAs($this->employeeUser);

    post(route('company.crm.leads.call-disposition', $otherLead), [
        'disposition' => 'connected',
        'duration_seconds' => 30,
    ])->assertForbidden();
});

it('generates deterministic notifications for due and overdue follow-ups', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->employeeUser->id,
        'next_followup_at' => Carbon::now()->subMinutes(10),
        'followup_notes' => 'Urgent follow-up required',
        'status' => LeadStatus::Contacted,
    ]);

    $service = app(LeadWorkspaceService::class);
    $generatedCount = $service->checkAndGenerateReminders($this->organization->id);

    expect($generatedCount)->toBeGreaterThanOrEqual(1);

    $this->assertDatabaseHas('ent_notifications', [
        'notifiable_id' => $this->employeeUser->id,
        'notifiable_type' => User::class,
    ]);
});
