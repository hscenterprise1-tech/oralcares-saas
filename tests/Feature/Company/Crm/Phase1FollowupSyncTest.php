<?php

use App\LeadStatus;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Services\LeadWorkspaceService;
use App\TaskPriority;
use App\TaskStatus;
use App\TaskType;
use App\UserType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase 1 item 3 — one authoritative next-follow-up.
 *
 * THE RULE
 *
 *   leads.next_followup_at = the earliest due_at among that lead's PENDING
 *   tasks whose type is a contact type (follow_up or call).
 *   NULL when there are none.
 *
 * Why those two types: both mean "you owe this lead contact by this date",
 * which is exactly what the column and the overdue queues represent. A `demo`
 * task is scheduled work with its own `demos.scheduled_at`, and `to_do` is
 * generic admin, so neither moves the follow-up date.
 *
 * Scope: only tasks whose `taskable` is a Lead. Tasks attached to any other
 * polymorphic subject are untouched.
 *
 * Before this, completeFollowup() was the only path that kept the two in step;
 * assignment tasks, the task controllers and win-back scheduling all wrote one
 * side and left the other stale.
 */
function leadFor(array $attributes = []): Lead
{
    return Lead::factory()->create(array_merge([
        'status' => LeadStatus::Contacted,
        'next_followup_at' => null,
    ], $attributes));
}

function followupTask(Lead $lead, string $dueAt, TaskType $type = TaskType::FollowUp, TaskStatus $status = TaskStatus::Pending): Task
{
    return Task::create([
        'title' => 'Follow up',
        'type' => $type,
        'status' => $status,
        'priority' => TaskPriority::Medium,
        'due_at' => $dueAt,
        'taskable_type' => $lead->getMorphClass(),
        'taskable_id' => $lead->id,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Create
// ─────────────────────────────────────────────────────────────────────────────

it('sets the lead follow-up date when a follow-up task is created', function () {
    $lead = leadFor();

    followupTask($lead, now()->addDays(3)->toDateTimeString());

    expect($lead->fresh()->next_followup_at->toDateString())
        ->toBe(now()->addDays(3)->toDateString());
});

it('treats a call task as a follow-up', function () {
    $lead = leadFor();

    followupTask($lead, now()->addDays(2)->toDateTimeString(), TaskType::Call);

    expect($lead->fresh()->next_followup_at)->not->toBeNull();
});

it('ignores demo and to-do tasks', function () {
    $lead = leadFor();

    followupTask($lead, now()->addDay()->toDateTimeString(), TaskType::Demo);
    followupTask($lead, now()->addDay()->toDateTimeString(), TaskType::ToDo);

    expect($lead->fresh()->next_followup_at)->toBeNull();
});

it('sets the follow-up date automatically when a lead is assigned', function () {
    // LeadObserver creates a follow-up task on assignment. It previously left
    // next_followup_at null, so the lead looked like it had no next action.
    $rep = User::factory()->create(['user_type' => UserType::CompanyUser]);
    $lead = leadFor();

    app(LeadWorkspaceService::class)->assignLead($lead, $rep, null, 'manual', notify: false);

    expect($lead->fresh()->next_followup_at)->not->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Multiple tasks — the earliest wins
// ─────────────────────────────────────────────────────────────────────────────

it('uses the earliest pending follow-up when several exist', function () {
    $lead = leadFor();

    followupTask($lead, now()->addDays(9)->toDateTimeString());
    followupTask($lead, now()->addDays(2)->toDateTimeString());
    followupTask($lead, now()->addDays(5)->toDateTimeString());

    expect($lead->fresh()->next_followup_at->toDateString())
        ->toBe(now()->addDays(2)->toDateString());
});

it('advances to the next earliest when the soonest is completed', function () {
    $lead = leadFor();

    $soonest = followupTask($lead, now()->addDays(2)->toDateTimeString());
    followupTask($lead, now()->addDays(6)->toDateTimeString());

    $soonest->update(['status' => TaskStatus::Completed]);

    expect($lead->fresh()->next_followup_at->toDateString())
        ->toBe(now()->addDays(6)->toDateString());
});

it('cannot store an undated task, so the rule never has to rank a null', function () {
    // `tasks.due_at` is NOT NULL at the schema level. The service's
    // whereNotNull guard is defence in depth, not a live branch — pinning that
    // here so a future migration relaxing the column fails loudly.
    $lead = leadFor();

    expect(fn () => Task::create([
        'title' => 'Undated',
        'type' => TaskType::FollowUp,
        'status' => TaskStatus::Pending,
        'priority' => TaskPriority::Low,
        'due_at' => null,
        'taskable_type' => $lead->getMorphClass(),
        'taskable_id' => $lead->id,
    ]))->toThrow(QueryException::class);
});

// ─────────────────────────────────────────────────────────────────────────────
// Reschedule
// ─────────────────────────────────────────────────────────────────────────────

it('moves the follow-up date when a task is rescheduled', function () {
    $lead = leadFor();
    $task = followupTask($lead, now()->addDays(3)->toDateTimeString());

    $task->update(['due_at' => now()->addDays(10)]);

    expect($lead->fresh()->next_followup_at->toDateString())
        ->toBe(now()->addDays(10)->toDateString());
});

it('moves the follow-up date when a win-back is rescheduled', function () {
    $actor = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $lead = leadFor(['status' => LeadStatus::Lost]);
    $service = app(LeadWorkspaceService::class);

    $service->scheduleWinBack($lead, now()->addDays(30)->toDateString(), null, $actor);
    $first = $lead->fresh()->next_followup_at;

    $service->scheduleWinBack($lead->fresh(), now()->addDays(45)->toDateString(), null, $actor);

    expect($lead->fresh()->next_followup_at->toDateString())
        ->toBe(now()->addDays(45)->toDateString())
        ->and($first->toDateString())->toBe(now()->addDays(30)->toDateString());
});

// ─────────────────────────────────────────────────────────────────────────────
// Complete, cancel, delete — no stale dates
// ─────────────────────────────────────────────────────────────────────────────

it('clears the follow-up date when the only task is completed', function () {
    $lead = leadFor();
    $task = followupTask($lead, now()->addDays(3)->toDateTimeString());

    $task->update(['status' => TaskStatus::Completed]);

    expect($lead->fresh()->next_followup_at)->toBeNull();
});

it('clears the follow-up date when the only task is cancelled', function () {
    $lead = leadFor();
    $task = followupTask($lead, now()->addDays(3)->toDateTimeString());

    $task->update(['status' => TaskStatus::Cancelled]);

    expect($lead->fresh()->next_followup_at)->toBeNull();
});

it('clears the follow-up date when the only task is deleted', function () {
    $lead = leadFor();
    $task = followupTask($lead, now()->addDays(3)->toDateTimeString());

    $task->delete();

    expect($lead->fresh()->next_followup_at)->toBeNull();
});

it('restores the follow-up date when a deleted task is restored', function () {
    $lead = leadFor();
    $task = followupTask($lead, now()->addDays(3)->toDateTimeString());
    $task->delete();

    $task->restore();

    expect($lead->fresh()->next_followup_at)->not->toBeNull();
});

it('leaves no stale date when a lead is won', function () {
    $actor = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $lead = leadFor(['status' => LeadStatus::Negotiation]);
    followupTask($lead, now()->addDays(3)->toDateTimeString());

    app(LeadWorkspaceService::class)->transitionStage($lead, LeadStatus::Won->value, $actor, 'closed');

    expect($lead->fresh()->next_followup_at)->toBeNull();
});

it('keeps the date correct through a complete-follow-up cycle', function () {
    $actor = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $lead = leadFor();
    followupTask($lead, now()->addDays(1)->toDateTimeString());

    app(LeadWorkspaceService::class)->completeFollowup($lead, [
        'notes' => 'spoke to the practice manager',
        'next_followup_at' => now()->addDays(7)->toDateTimeString(),
        'next_followup_notes' => 'send the quote',
    ], $actor);

    $lead->refresh();

    expect($lead->next_followup_at->toDateString())->toBe(now()->addDays(7)->toDateString())
        ->and($lead->followup_notes)->toBe('send the quote')
        ->and(Task::where('taskable_id', $lead->id)->where('status', TaskStatus::Pending)->count())->toBe(1);
});

it('clears the date when a follow-up is completed with nothing scheduled after it', function () {
    $actor = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $lead = leadFor();
    followupTask($lead, now()->addDays(1)->toDateTimeString());

    app(LeadWorkspaceService::class)->completeFollowup($lead, ['notes' => 'closed out'], $actor);

    expect($lead->fresh()->next_followup_at)->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Isolation — unrelated polymorphic tasks are untouched
// ─────────────────────────────────────────────────────────────────────────────

it('does not touch leads when a task belongs to another subject', function () {
    $lead = leadFor();
    followupTask($lead, now()->addDays(5)->toDateTimeString());
    $before = $lead->fresh()->next_followup_at;

    $organization = Organization::factory()->create();
    Task::create([
        'title' => 'Account review',
        'type' => TaskType::FollowUp,
        'status' => TaskStatus::Pending,
        'priority' => TaskPriority::Medium,
        'due_at' => now()->addDay(),
        'taskable_type' => $organization->getMorphClass(),
        'taskable_id' => $organization->id,
    ]);

    expect($lead->fresh()->next_followup_at->toDateString())->toBe($before->toDateString());
});

it('does not affect other leads', function () {
    $a = leadFor();
    $b = leadFor();

    followupTask($a, now()->addDays(3)->toDateTimeString());

    expect($b->fresh()->next_followup_at)->toBeNull();
});

it('reassigns the date correctly when a task moves between leads', function () {
    $a = leadFor();
    $b = leadFor();
    $task = followupTask($a, now()->addDays(3)->toDateTimeString());

    $task->update(['taskable_id' => $b->id]);

    expect($a->fresh()->next_followup_at)->toBeNull()
        ->and($b->fresh()->next_followup_at)->not->toBeNull();
});
