<?php

use App\Models\Contact;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Support\CrmScope;
use App\TaskStatus;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * CRM final gap closure — `tasks` never carried its own tenant column.
 * Scoping rode entirely on `assigned_user_id`, so an unassigned task (an
 * automation-created one, or one a rep unassigned) was invisible to every
 * manager in its own organisation, and — the real severity — `TaskPolicy`
 * had no tenant check of its own at all: a user with `view-tasks` in one
 * organisation could view, update or delete another organisation's task by
 * id alone. This suite pins the column's auto-derivation and the closed
 * authorization/scoping gap.
 */
beforeEach(function () {
    $this->orgA = Organization::factory()->create();
    $this->orgB = Organization::factory()->create();

    $this->repA = grantCrmPermissions(User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->orgA->id,
    ]));

    $this->managerA = grantCrmPermissions(User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->orgA->id,
    ]), ['view-crm', 'view-tasks', 'view-operations']);

    $this->userB = grantCrmPermissions(User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->orgB->id,
    ]));
});

/* ── Auto-derivation ──────────────────────────────────────────────────── */

it('derives organization_id from the taskable lead on creation', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->orgA->id]);

    $task = Task::create([
        'title' => 'Chase signature',
        'status' => TaskStatus::Pending,
        'due_at' => now()->addDay(),
        'taskable_type' => $lead->getMorphClass(),
        'taskable_id' => $lead->id,
    ]);

    expect($task->fresh()->organization_id)->toBe($this->orgA->id);
});

it('derives organization_id from the assignee when there is no taskable', function () {
    $task = Task::create([
        'title' => 'Standalone reminder',
        'status' => TaskStatus::Pending,
        'due_at' => now()->addDay(),
        'assigned_user_id' => $this->repA->id,
    ]);

    expect($task->fresh()->organization_id)->toBe($this->orgA->id);
});

it('derives organization_id from a taskable contact', function () {
    $contact = Contact::create([
        'organization_id' => $this->orgA->id,
        'lead_id' => Lead::factory()->create(['organization_id' => $this->orgA->id])->id,
        'first_name' => 'Priya',
        'is_primary' => false,
    ]);

    $task = Task::create([
        'title' => 'Follow up with contact',
        'status' => TaskStatus::Pending,
        'due_at' => now()->addDay(),
        'taskable_type' => $contact->getMorphClass(),
        'taskable_id' => $contact->id,
    ]);

    expect($task->fresh()->organization_id)->toBe($this->orgA->id);
});

it('leaves organization_id null when nothing traces to a tenant, rather than guessing', function () {
    $task = Task::create([
        'title' => 'Orphan task',
        'status' => TaskStatus::Pending,
        'due_at' => now()->addDay(),
    ]);

    expect($task->fresh()->organization_id)->toBeNull();
});

it('never overwrites an explicitly set organization_id', function () {
    $task = Task::create([
        'title' => 'Explicit tenant',
        'status' => TaskStatus::Pending,
        'due_at' => now()->addDay(),
        'assigned_user_id' => $this->repA->id,
        'organization_id' => $this->orgB->id,
    ]);

    expect($task->fresh()->organization_id)->toBe($this->orgB->id);
});

/* ── CrmScope::tasks() ────────────────────────────────────────────────── */

it('surfaces an unassigned task to a manager in its own organization', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->orgA->id]);
    $unassigned = Task::create([
        'title' => 'Automation-created follow-up',
        'status' => TaskStatus::Pending,
        'due_at' => now()->addDay(),
        'taskable_type' => $lead->getMorphClass(),
        'taskable_id' => $lead->id,
    ]);

    expect($unassigned->assigned_user_id)->toBeNull()
        ->and(CrmScope::tasks($this->managerA)->pluck('id')->all())->toContain($unassigned->id);
});

it('keeps one organization out of another organization tasks through CrmScope', function () {
    $taskB = Task::create([
        'title' => "Org B's task",
        'status' => TaskStatus::Pending,
        'due_at' => now()->addDay(),
        'assigned_user_id' => $this->userB->id,
    ]);

    expect(CrmScope::tasks($this->managerA)->pluck('id')->all())->not->toContain($taskB->id);
});

it('reads zero tasks for a manager whose organization cannot be resolved', function () {
    Task::create([
        'title' => "Org A's task",
        'status' => TaskStatus::Pending,
        'due_at' => now()->addDay(),
        'assigned_user_id' => $this->repA->id,
    ]);

    $orphanManager = grantCrmPermissions(User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => null,
    ]), ['view-crm', 'view-tasks', 'view-operations']);

    expect(CrmScope::tasks($orphanManager)->count())->toBe(0);
});

/* ── TaskPolicy / HTTP cross-tenant isolation ────────────────────────── */

it('refuses to let a user in one organization update another organization task', function () {
    $taskA = Task::create([
        'title' => "Org A's task",
        'status' => TaskStatus::Pending,
        'due_at' => now()->addDay(),
        'assigned_user_id' => $this->repA->id,
    ]);

    $this->actingAs($this->userB)
        ->put(route('company.crm.tasks.update', $taskA), [
            'title' => 'Hijacked',
            'status' => 'pending',
            'type' => 'to_do',
            'priority' => 'medium',
            'due_at' => now()->addDay()->toDateTimeString(),
        ])
        ->assertForbidden();

    expect($taskA->fresh()->title)->toBe("Org A's task");
});

it('refuses to let a user in one organization delete another organization task', function () {
    $taskA = Task::create([
        'title' => "Org A's task",
        'status' => TaskStatus::Pending,
        'due_at' => now()->addDay(),
        'creator_id' => $this->repA->id,
        'assigned_user_id' => $this->repA->id,
    ]);

    $this->actingAs($this->userB)
        ->delete(route('company.crm.tasks.destroy', $taskA))
        ->assertForbidden();

    expect(Task::find($taskA->id))->not->toBeNull();
});

it('still lets the owning organization update its own task', function () {
    $taskA = Task::create([
        'title' => "Org A's task",
        'status' => TaskStatus::Pending,
        'due_at' => now()->addDay(),
        'assigned_user_id' => $this->repA->id,
    ]);

    $this->actingAs($this->managerA)
        ->put(route('company.crm.tasks.update', $taskA), [
            'title' => 'Updated title',
            'status' => 'pending',
            'type' => 'to_do',
            'priority' => 'medium',
            'due_at' => now()->addDay()->toDateTimeString(),
        ])
        ->assertSessionHasNoErrors();

    expect($taskA->fresh()->title)->toBe('Updated title');
});

it('refuses access to a task with no resolvable tenant, even with the right permission', function () {
    $orphan = Task::create([
        'title' => 'Nothing traces this to a tenant',
        'status' => TaskStatus::Pending,
        'due_at' => now()->addDay(),
    ]);

    expect($orphan->organization_id)->toBeNull();

    $this->actingAs($this->managerA)
        ->put(route('company.crm.tasks.update', $orphan), [
            'title' => 'Should be refused',
            'status' => 'pending',
            'type' => 'to_do',
            'priority' => 'medium',
            'due_at' => now()->addDay()->toDateTimeString(),
        ])
        ->assertForbidden();
});
