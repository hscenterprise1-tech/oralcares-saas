<?php

use App\LeadStatus;
use App\Models\CompanyQuotation;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Models\Trial;
use App\Models\User;
use App\QuotationStatus;
use App\Services\MyWorkspaceService;
use App\TaskPriority;
use App\TaskStatus;
use App\TaskType;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Schedule a follow-up the supported way.
 *
 * Phase 1 made `leads.next_followup_at` a derived cache of the lead's pending
 * follow-up tasks, so writing the column directly no longer schedules
 * anything — the next task event recomputes it away. These tests now create
 * the task the date is meant to represent.
 */
function scheduleFollowupFor(Lead $lead, $dueAt): Task
{
    return Task::create([
        'title' => "Follow-up: {$lead->company_name}",
        'type' => TaskType::FollowUp,
        'status' => TaskStatus::Pending,
        'priority' => TaskPriority::Medium,
        'due_at' => $dueAt,
        'assigned_user_id' => $lead->assigned_user_id,
        'taskable_type' => $lead->getMorphClass(),
        'taskable_id' => $lead->id,
    ]);
}

/**
 * Slice C — the employee daily hub.
 *
 * My Workspace is assigned-scope for *every* persona, managers included: it
 * answers "what am I doing today", not "what may I see".
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

    $this->otherRep = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->org->id]);
    $this->otherRep->assignRole('Sales Representative');

    $this->myLead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Contacted,
    ]);

    $this->theirLead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->otherRep->id,
        'status' => LeadStatus::Contacted,
    ]);
});

it('renders the workspace with all daily sections', function () {
    $this->actingAs($this->rep)
        ->get(route('company.crm.my-workspace'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Company/Crm/MyWorkspace')
            ->has('todays_focus')
            ->has('tasks')
            ->has('upcoming')
            ->has('recent_activity')
            ->has('workload')
        );
});

it('shows only the acting rep assigned leads', function () {
    $this->actingAs($this->rep)
        ->get(route('company.crm.my-workspace'))
        ->assertInertia(function (Assert $page) {
            $ids = array_column($page->toArray()['props']['workload']['assigned_leads'], 'id');

            expect($ids)->toContain($this->myLead->id)
                ->and($ids)->not->toContain($this->theirLead->id);
        });
});

it('shows only the acting rep tasks', function () {
    $mine = Task::factory()->create(['assigned_user_id' => $this->rep->id, 'status' => TaskStatus::Pending]);
    $theirs = Task::factory()->create(['assigned_user_id' => $this->otherRep->id, 'status' => TaskStatus::Pending]);

    $this->actingAs($this->rep)
        ->get(route('company.crm.my-workspace'))
        ->assertInertia(function (Assert $page) use ($mine, $theirs) {
            $ids = array_column($page->toArray()['props']['tasks'], 'id');

            expect($ids)->toContain($mine->id)->and($ids)->not->toContain($theirs->id);
        });
});

it('scopes demos, trials and quotations to the acting rep', function () {
    Demo::factory()->create(['lead_id' => $this->myLead->id, 'scheduled_at' => now()->addDay()]);
    Demo::factory()->create(['lead_id' => $this->theirLead->id, 'scheduled_at' => now()->addDay()]);

    Trial::factory()->create(['lead_id' => $this->myLead->id, 'status' => 'active', 'end_date' => now()->addDays(3)]);
    Trial::factory()->create(['lead_id' => $this->theirLead->id, 'status' => 'active', 'end_date' => now()->addDays(3)]);

    CompanyQuotation::factory()->create(['lead_id' => $this->myLead->id, 'status' => QuotationStatus::Sent]);
    CompanyQuotation::factory()->create(['lead_id' => $this->theirLead->id, 'status' => QuotationStatus::Sent]);

    $this->actingAs($this->rep)
        ->get(route('company.crm.my-workspace'))
        ->assertInertia(function (Assert $page) {
            $workload = $page->toArray()['props']['workload'];

            expect($workload['demos'])->toHaveCount(1)
                ->and($workload['trials'])->toHaveCount(1)
                ->and($workload['quotations'])->toHaveCount(1);

            foreach (['demos', 'trials', 'quotations'] as $section) {
                expect($workload[$section][0]['lead_id'])->toBe($this->myLead->id);
            }
        });
});

it('ranks overdue follow-ups above every other focus item', function () {
    scheduleFollowupFor($this->myLead, now()->subDays(3));

    Task::factory()->create([
        'assigned_user_id' => $this->rep->id,
        'status' => TaskStatus::Pending,
        'due_at' => now(),
    ]);

    $this->actingAs($this->rep)
        ->get(route('company.crm.my-workspace'))
        ->assertInertia(function (Assert $page) {
            $focus = $page->toArray()['props']['todays_focus'];

            expect($focus['is_clear'])->toBeFalse()
                ->and($focus['items'][0]['kind'])->toBe('overdue_followup')
                ->and($focus['items'][0]['reason'])->toBe('Overdue follow-up');
        });
});

it('reports a clear queue when nothing is due', function () {
    $this->actingAs($this->rep)
        ->get(route('company.crm.my-workspace'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('todays_focus.is_clear', true)
            ->where('todays_focus.total', 0)
        );
});

it('merges demos, follow-ups, tasks, trials and quotations into one upcoming timeline', function () {
    scheduleFollowupFor($this->myLead, now()->addDays(2));
    Demo::factory()->create(['lead_id' => $this->myLead->id, 'scheduled_at' => now()->addDay()]);
    Trial::factory()->create(['lead_id' => $this->myLead->id, 'status' => 'active', 'end_date' => now()->addDays(4)]);
    CompanyQuotation::factory()->create([
        'lead_id' => $this->myLead->id,
        'status' => QuotationStatus::Sent,
        'valid_until' => now()->addDays(5),
    ]);
    Task::factory()->create([
        'assigned_user_id' => $this->rep->id,
        'status' => TaskStatus::Pending,
        'due_at' => now()->addDays(3),
    ]);

    $this->actingAs($this->rep)
        ->get(route('company.crm.my-workspace'))
        ->assertInertia(function (Assert $page) {
            $kinds = array_column($page->toArray()['props']['upcoming'], 'kind');

            expect($kinds)->toContain('demo')
                ->and($kinds)->toContain('followup')
                ->and($kinds)->toContain('task')
                ->and($kinds)->toContain('trial_deadline')
                ->and($kinds)->toContain('quotation_expiry');
        });
});

it('orders the upcoming timeline by date', function () {
    scheduleFollowupFor($this->myLead, now()->addDays(6));
    Demo::factory()->create(['lead_id' => $this->myLead->id, 'scheduled_at' => now()->addDay()]);

    $this->actingAs($this->rep)
        ->get(route('company.crm.my-workspace'))
        ->assertInertia(function (Assert $page) {
            $upcoming = $page->toArray()['props']['upcoming'];
            $dates = array_column($upcoming, 'occurs_at');
            $sorted = $dates;
            sort($sorted);

            expect($dates)->toBe($sorted);
        });
});

it('shows a manager only their own work here, not the whole team', function () {
    $manager = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->org->id]);
    $manager->assignRole('CRM Manager');

    $managerLead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $manager->id,
    ]);

    $this->actingAs($manager)
        ->get(route('company.crm.my-workspace'))
        ->assertInertia(function (Assert $page) use ($managerLead) {
            $ids = array_column($page->toArray()['props']['workload']['assigned_leads'], 'id');

            expect($ids)->toBe([$managerLead->id]);
        });
});

it('denies the workspace to a user without CRM permissions', function () {
    $outsider = User::factory()->create(['user_type' => UserType::CompanyUser]);

    $this->actingAs($outsider)
        ->get(route('company.crm.my-workspace'))
        ->assertForbidden();
});

/*
 * My work and the Overview's personal section read the same service.
 *
 * That is shared data, not duplicated functionality: `MyWorkspaceService`
 * computes the rep's queue once and each surface renders the slice it needs.
 * The Overview shows three keys — today's focus, tasks, and the unclaimed pile
 * — as a glance. This page renders those plus the seven workload tabs, the
 * filters and the activity feed, which is the whole reason it still exists.
 *
 * The distinction matters because deleting this page would have lost
 * `other_tasks`, `upcoming`, `recent_activity` and every tab, none of which the
 * Overview consumes.
 */
it('sends my work every key it renders, and the overview a subset of the same payload', function () {
    $payload = app(MyWorkspaceService::class)->payload($this->rep);

    /* What My work renders. */
    expect($payload)->toHaveKeys([
        'unclaimed',
        'currentUserId',
        'workload',
        'filters',
        'todays_focus',
        'tasks',
        'other_tasks',
        'upcoming',
        'recent_activity',
        /* The Pending Approvals queue — see MyWorkExecutionTest for its scoping rules. */
        'pending_approvals',
        'can_approve_extensions',
    ]);

    /* The seven tabs, which exist nowhere else. */
    expect($payload['workload'])->toHaveKeys([
        'counts', 'followups', 'demos', 'trials', 'quotations',
        'needs_next_action', 'winbacks', 'assigned_leads',
    ]);

    /* The Overview reads only these three of them. */
    expect($payload)->toHaveKeys(['todays_focus', 'tasks', 'unclaimed']);
});

it('keeps my work to the acting user even when they can see the whole tenant', function () {
    /*
     * "My work" is deliberately not "all work I am allowed to see". The service
     * pins `assigned_user_id` for every persona, managers and master admins
     * included, so this page never becomes a second Deals list.
     */
    $manager = User::factory()->create([
        'user_type' => UserType::MasterAdmin,
        'organization_id' => $this->org->id,
    ]);

    $payload = app(MyWorkspaceService::class)->payload($manager);

    $companies = collect($payload['workload']['assigned_leads'])->pluck('company_name');

    expect($companies)->not->toContain($this->myLead->company_name)
        ->and($companies)->not->toContain($this->theirLead->company_name);
});
