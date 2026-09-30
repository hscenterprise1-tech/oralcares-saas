<?php

use App\LeadStatus;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Services\CRM\LeadFollowupSyncService;
use App\Services\LeadWorkspaceService;
use App\TaskPriority;
use App\TaskStatus;
use App\TaskType;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase 3 — Won/Lost task & follow-up lifecycle parity.
 *
 * Before this suite, `transitionStage()` cancelled a Won deal's pending tasks
 * and cleared its follow-up cache, but did nothing of the sort for Lost: a
 * lead marked Lost kept every open task pending and its follow-up date intact
 * forever, unless a human happened to notice and clean it up by hand. These
 * tests pin the explicit lifecycle policy — Won and Lost both stop future
 * sales follow-up work; only Won additionally provisions and suppresses
 * marketing, which stays untouched here.
 */
function taskFor(Lead $lead, User $actor, array $overrides = []): Task
{
    return Task::create(array_merge([
        'title' => 'Chase signature',
        'type' => TaskType::FollowUp,
        'status' => TaskStatus::Pending,
        'priority' => TaskPriority::High,
        'due_at' => now()->addDay(),
        'taskable_type' => $lead->getMorphClass(),
        'taskable_id' => $lead->id,
        'creator_id' => $actor->id,
    ], $overrides));
}

it('cancels pending sales tasks when a lead is marked lost', function () {
    $actor = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $lead = Lead::factory()->create(['status' => LeadStatus::Negotiation]);
    $pending = taskFor($lead, $actor);

    app(LeadWorkspaceService::class)
        ->transitionStage($lead, LeadStatus::Lost->value, $actor, 'Price');

    expect($pending->fresh()->status->value ?? $pending->fresh()->status)
        ->toBe(TaskStatus::Cancelled->value);
});

it('clears the follow-up schedule when a lead is marked lost', function () {
    $actor = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $lead = Lead::factory()->create([
        'status' => LeadStatus::Negotiation,
        'next_followup_at' => now()->addDays(3),
        'followup_notes' => 'Call back Tuesday',
    ]);

    app(LeadWorkspaceService::class)
        ->transitionStage($lead, LeadStatus::Lost->value, $actor, 'Price');

    $lead->refresh();

    expect($lead->next_followup_at)->toBeNull()
        ->and($lead->followup_notes)->toBeNull();
});

it('leaves an already-completed task alone when a lead is marked lost', function () {
    $actor = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $lead = Lead::factory()->create(['status' => LeadStatus::Negotiation]);
    $completed = taskFor($lead, $actor, [
        'title' => 'Send brochure',
        'status' => TaskStatus::Completed,
        'completed_at' => now()->subDay(),
    ]);

    app(LeadWorkspaceService::class)
        ->transitionStage($lead, LeadStatus::Lost->value, $actor, 'Price');

    expect($completed->fresh()->status->value ?? $completed->fresh()->status)
        ->toBe(TaskStatus::Completed->value);
});

it('leaves an already-cancelled task alone when a lead is marked lost', function () {
    $actor = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $lead = Lead::factory()->create(['status' => LeadStatus::Negotiation]);
    $cancelled = taskFor($lead, $actor, [
        'title' => 'Old cadence task',
        'status' => TaskStatus::Cancelled,
    ]);

    app(LeadWorkspaceService::class)
        ->transitionStage($lead, LeadStatus::Lost->value, $actor, 'Price');

    expect($cancelled->fresh()->status->value ?? $cancelled->fresh()->status)
        ->toBe(TaskStatus::Cancelled->value);
});

it('writes a stage-change activity when a lead is marked lost', function () {
    $actor = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $lead = Lead::factory()->create(['status' => LeadStatus::Negotiation]);

    app(LeadWorkspaceService::class)
        ->transitionStage($lead, LeadStatus::Lost->value, $actor, 'Price');

    expect($lead->activities()->count())->toBeGreaterThan(0);
});

it('does not provision an organization or touch marketing suppression when a lead is marked lost', function () {
    $actor = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $lead = Lead::factory()->create([
        'status' => LeadStatus::Negotiation,
        'company_name' => 'Southgate Dental',
    ]);

    app(LeadWorkspaceService::class)
        ->transitionStage($lead, LeadStatus::Lost->value, $actor, 'Price');

    $this->assertDatabaseMissing('organizations', ['name' => 'Southgate Dental']);
    expect($lead->fresh()->customer_organization_id)->toBeNull();
});

it('does not resurrect a cancelled task or follow-up date if the same lead is marked lost twice', function () {
    $actor = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $lead = Lead::factory()->create(['status' => LeadStatus::Negotiation]);
    $pending = taskFor($lead, $actor);

    app(LeadWorkspaceService::class)
        ->transitionStage($lead, LeadStatus::Lost->value, $actor, 'Price');

    // Lost is terminal — DealStageService blocks re-transitioning into it
    // without reopening first. Repeating the exact same call (a retried
    // request, a double-submitted form) is a same-status no-op, not an
    // illegal edge, and must not throw or re-run the side effects.
    app(LeadWorkspaceService::class)
        ->transitionStage($lead->fresh(), LeadStatus::Lost->value, $actor, 'Price');

    expect($pending->fresh()->status->value ?? $pending->fresh()->status)
        ->toBe(TaskStatus::Cancelled->value)
        ->and($lead->fresh()->next_followup_at)->toBeNull();
});

it('keeps tenant isolation when cancelling tasks on a lost lead', function () {
    $actor = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $otherLead = Lead::factory()->create(['status' => LeadStatus::Negotiation]);
    $otherTask = taskFor($otherLead, $actor);

    $lead = Lead::factory()->create(['status' => LeadStatus::Negotiation]);
    taskFor($lead, $actor);

    app(LeadWorkspaceService::class)
        ->transitionStage($lead, LeadStatus::Lost->value, $actor, 'Price');

    expect($otherTask->fresh()->status->value ?? $otherTask->fresh()->status)
        ->toBe(TaskStatus::Pending->value);
});

/**
 * Same transaction-safety guarantee Won already has: task cancellation, the
 * status save, and the follow-up resync commit or roll back together.
 */
it('leaves nothing changed if a step inside the Lost transition fails before it commits', function () {
    $actor = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $lead = Lead::factory()->create(['status' => LeadStatus::Negotiation]);
    $pending = taskFor($lead, $actor);

    $this->mock(LeadFollowupSyncService::class, function ($mock) {
        $mock->shouldReceive('sync')->andThrow(new RuntimeException('simulated failure after the task cancellation'));
    });

    expect(fn () => app(LeadWorkspaceService::class)->transitionStage($lead, LeadStatus::Lost->value, $actor, 'Price'))
        ->toThrow(RuntimeException::class);

    expect($lead->fresh()->status->value ?? $lead->fresh()->status)->not->toBe(LeadStatus::Lost->value)
        ->and($pending->fresh()->status->value ?? $pending->fresh()->status)->toBe(TaskStatus::Pending->value);
});
