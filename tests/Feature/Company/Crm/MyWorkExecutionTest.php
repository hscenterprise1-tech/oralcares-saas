<?php

use App\LeadStatus;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Models\Trial;
use App\Models\User;
use App\Services\CRM\TrialExtensionApprovalService;
use App\Services\LeadWorkspaceService;
use App\Services\MyWorkspaceService;
use App\TaskPriority;
use App\TaskStatus;
use App\TaskType;
use App\TrialExtensionRequestStatus;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Step 6 — the "My Work" Daily Execution Cockpit.
 *
 * My Work already existed (`MyWorkspaceService`/`LeadWorkspaceService`) with
 * three of the four requested queues — callbacks, demos, expiring trials —
 * scoped assigned-to-me-only for every persona. The one genuinely missing
 * queue was management approvals, which this suite pins as the deliberate
 * exception: it is the one part of My Work that is team-wide for whoever
 * holds approval authority, because a manager cannot approve their own team's
 * requests by only ever seeing their own leads.
 */
beforeEach(function () {
    setPermissionsTeamId(0);
    Role::firstOrCreate(['name' => 'CRM Manager', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Sales Representative', 'guard_name' => 'web']);
    grantCrmManagerPermissionsToRole(Role::findByName('CRM Manager', 'web'));
    grantCrmPermissionsToRole(Role::findByName('Sales Representative', 'web'));

    $this->org = Organization::factory()->create();

    $this->rep = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->org->id]);
    $this->rep->assignRole('Sales Representative');

    $this->manager = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->org->id]);
    $this->manager->assignRole('CRM Manager');

    $this->otherRep = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->org->id]);
    $this->otherRep->assignRole('Sales Representative');
});

it("keeps a rep's callbacks and demos to leads assigned to them", function () {
    $myLead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Contacted,
    ]);
    $theirLead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->otherRep->id,
        'status' => LeadStatus::Contacted,
    ]);

    Demo::factory()->create(['lead_id' => $myLead->id, 'scheduled_at' => now()]);
    Demo::factory()->create(['lead_id' => $theirLead->id, 'scheduled_at' => now()]);

    $payload = app(MyWorkspaceService::class)->payload($this->rep);

    expect(collect($payload['workload']['demos'])->pluck('lead_id')->all())
        ->toBe([$myLead->id]);
});

it('gives a rep no pending approvals queue at all', function () {
    $trial = Trial::factory()->create([
        'lead_id' => Lead::factory()->create(['organization_id' => $this->org->id])->id,
        'status' => 'active',
    ]);

    app(TrialExtensionApprovalService::class)->requestExtension($trial, $this->otherRep, 5, 'Needs more evaluation time.');

    $payload = app(MyWorkspaceService::class)->payload($this->rep);

    expect($payload['can_approve_extensions'])->toBeFalse()
        ->and($payload['pending_approvals']['count'])->toBe(0)
        ->and($payload['pending_approvals']['items'])->toBe([]);
});

it("surfaces a manager's whole team's pending extension requests, not only their own leads", function () {
    $repLead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
    ]);
    $trial = Trial::factory()->create(['lead_id' => $repLead->id, 'status' => 'active']);

    $request = app(TrialExtensionApprovalService::class)
        ->requestExtension($trial, $this->rep, 7, 'Clinic asked for another week to onboard staff.');

    $payload = app(MyWorkspaceService::class)->payload($this->manager);

    expect($payload['can_approve_extensions'])->toBeTrue()
        ->and($payload['pending_approvals']['count'])->toBe(1)
        ->and($payload['pending_approvals']['items'][0]['id'])->toBe($request->id)
        ->and($payload['pending_approvals']['items'][0]['additional_days'])->toBe(7);

    /* Ranked ahead of every other focus item, including overdue follow-ups. */
    expect($payload['todays_focus']['items'][0]['kind'])->toBe('pending_approval');
});

it('renders the pending approvals queue on the My Work page for an approver', function () {
    $repLead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
    ]);
    $trial = Trial::factory()->create(['lead_id' => $repLead->id, 'status' => 'active']);
    app(TrialExtensionApprovalService::class)->requestExtension($trial, $this->rep, 3, 'Extra week for the front desk team.');

    $this->actingAs($this->manager)
        ->get(route('company.crm.my-workspace'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Company/Crm/MyWorkspace')
            ->where('pending_approvals.count', 1)
            ->where('can_approve_extensions', true)
        );
});

it('approving an extension from the existing trial route clears it from the pending queue on reload', function () {
    $repLead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
    ]);
    $trial = Trial::factory()->create(['lead_id' => $repLead->id, 'status' => 'active', 'end_date' => now()->addDays(10)]);
    $request = app(TrialExtensionApprovalService::class)
        ->requestExtension($trial, $this->rep, 4, 'One more week before their board meeting.');

    expect(app(MyWorkspaceService::class)->payload($this->manager)['pending_approvals']['count'])->toBe(1);

    $this->actingAs($this->manager)
        ->post(route('company.crm.trials.extension-requests.approve', [$trial, $request]))
        ->assertRedirect();

    expect($request->fresh()->status)->toBe(TrialExtensionRequestStatus::Approved)
        ->and($trial->fresh()->end_date->isSameDay(now()->addDays(14)))->toBeTrue();

    $payload = app(MyWorkspaceService::class)->payload($this->manager);
    expect($payload['pending_approvals']['count'])->toBe(0);
});

it('rejecting an extension from the existing trial route clears it from the pending queue without moving end_date', function () {
    $repLead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
    ]);
    $originalEnd = now()->addDays(2);
    $trial = Trial::factory()->create(['lead_id' => $repLead->id, 'status' => 'active', 'end_date' => $originalEnd]);
    $request = app(TrialExtensionApprovalService::class)
        ->requestExtension($trial, $this->rep, 10, 'Wants a much longer runway.');

    $this->actingAs($this->manager)
        ->post(route('company.crm.trials.extension-requests.reject', [$trial, $request]), [
            'rejection_reason' => 'Too long a runway for this stage of the deal.',
        ])
        ->assertRedirect();

    expect($request->fresh()->status)->toBe(TrialExtensionRequestStatus::Rejected)
        ->and($trial->fresh()->end_date->isSameDay($originalEnd))->toBeTrue();

    $payload = app(MyWorkspaceService::class)->payload($this->manager);
    expect($payload['pending_approvals']['count'])->toBe(0);
});

it('carries city on followups, presenter on demos, and the 48-hour expiry flag on trials', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'city' => 'Pune',
    ]);

    Task::create([
        'title' => "Follow-up: {$lead->company_name}",
        'type' => TaskType::FollowUp,
        'status' => TaskStatus::Pending,
        'priority' => TaskPriority::Medium,
        'due_at' => now()->subDay(),
        'assigned_user_id' => $this->rep->id,
        'taskable_type' => $lead->getMorphClass(),
        'taskable_id' => $lead->id,
    ]);

    Demo::factory()->create([
        'lead_id' => $lead->id,
        'scheduled_at' => now()->addDay(),
        'presenter_id' => $this->rep->id,
    ]);

    Trial::factory()->create([
        'lead_id' => $lead->id,
        'status' => 'active',
        'end_date' => now()->addHours(30),
    ]);

    $payload = app(MyWorkspaceService::class)->payload($this->rep);

    expect($payload['workload']['followups']['overdue'][0]['city'])->toBe('Pune')
        ->and($payload['workload']['demos'][0]['presenter'])->toBe($this->rep->name)
        ->and($payload['workload']['trials'][0]['is_expiring_48h'])->toBeTrue()
        ->and($payload['workload']['trials'][0])->toHaveKey('telemetry');
});

/**
 * Master Audit P0 — the Win-backs tab used to read `next_followup_at`, a
 * field the win-back workflow never writes to. `scheduleWinBack()` and the
 * `ProcessDueWinBacksJob` automation both key off `winback_scheduled_at`
 * (`LeadWorkspaceService::scheduleWinBack/processDueWinBacks`) — the tab must
 * read the same field, not a coincidentally-similar one.
 */
it('shows the winback tab date from winback_scheduled_at, not next_followup_at', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Lost,
    ]);

    app(LeadWorkspaceService::class)->scheduleWinBack($lead, now()->addDays(9)->toDateString());

    $payload = app(MyWorkspaceService::class)->payload($this->rep);

    expect($payload['workload']['winbacks'][0]['scheduled_winback'])
        ->toBe($lead->fresh()->winback_scheduled_at->format('d M Y'));
});

it('shows a lost lead as unscheduled when no winback has been scheduled, even with a next_followup_at set', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Lost,
    ]);

    Task::create([
        'title' => "Follow-up: {$lead->company_name}",
        'type' => TaskType::FollowUp,
        'status' => TaskStatus::Pending,
        'priority' => TaskPriority::Medium,
        'due_at' => now()->addDays(3),
        'assigned_user_id' => $this->rep->id,
        'taskable_type' => $lead->getMorphClass(),
        'taskable_id' => $lead->id,
    ]);

    $payload = app(MyWorkspaceService::class)->payload($this->rep);

    expect($payload['workload']['winbacks'][0]['scheduled_winback'])->toBe('Unscheduled');
});
