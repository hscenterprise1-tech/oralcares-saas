<?php

use App\DemoStatus;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Services\LeadWorkspaceService;
use App\TaskPriority;
use App\TaskStatus;
use App\TaskType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase 3 — Demo cancellation.
 *
 * Before this suite, a Demo had no way to be cancelled short of hard-deleting
 * the row (`DemoController::destroy()`): no reason, no timestamp, no actor, no
 * trace it ever happened. `DemoStatus::Cancelled` already existed as an enum
 * case and the reminder pipeline already ignored it — only the write path was
 * missing. These tests pin cancellation as a proper lifecycle transition:
 * status change, mandatory reason, audit trail, record preserved.
 */
it('cancels a scheduled demo with a reason, timestamp and actor', function () {
    $user = crmUser([], ['view-crm', 'view-leads', 'view-demos']);
    $lead = Lead::factory()->create(['organization_id' => $user->organization_id]);
    $demo = Demo::factory()->create([
        'lead_id' => $lead->id,
        'status' => 'scheduled',
        'presenter_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->post(route('company.crm.demos.cancel', $demo), ['cancellation_reason' => 'Client rescheduled indefinitely'])
        ->assertSessionHasNoErrors();

    $demo->refresh();

    expect($demo->status->value ?? $demo->status)->toBe(DemoStatus::Cancelled->value)
        ->and($demo->cancellation_reason)->toBe('Client rescheduled indefinitely')
        ->and($demo->cancelled_at)->not->toBeNull()
        ->and($demo->cancelled_by_id)->toBe($user->id);

    // Preserved, never deleted — still queryable and auditable.
    $this->assertDatabaseHas('demos', ['id' => $demo->id]);
});

it('will not cancel a demo without a reason', function () {
    $user = crmUser([], ['view-crm', 'view-leads', 'view-demos']);
    $lead = Lead::factory()->create(['organization_id' => $user->organization_id]);
    $demo = Demo::factory()->create([
        'lead_id' => $lead->id,
        'status' => 'scheduled',
        'presenter_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->post(route('company.crm.demos.cancel', $demo), [])
        ->assertSessionHasErrors('cancellation_reason');

    expect($demo->fresh()->status->value ?? $demo->fresh()->status)->toBe('scheduled');
});

it('refuses to cancel an already-completed demo', function () {
    $user = crmUser([], ['view-crm', 'view-leads', 'view-demos']);
    $lead = Lead::factory()->create(['organization_id' => $user->organization_id]);
    $demo = Demo::factory()->create([
        'lead_id' => $lead->id,
        'status' => 'completed',
        'presenter_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->post(route('company.crm.demos.cancel', $demo), ['cancellation_reason' => 'Too late'])
        ->assertSessionHasErrors('cancellation_reason');

    expect($demo->fresh()->status->value ?? $demo->fresh()->status)->toBe('completed');
});

it('refuses to cancel an already-cancelled demo', function () {
    $user = crmUser([], ['view-crm', 'view-leads', 'view-demos']);
    $lead = Lead::factory()->create(['organization_id' => $user->organization_id]);
    $demo = Demo::factory()->create([
        'lead_id' => $lead->id,
        'status' => 'scheduled',
        'presenter_id' => $user->id,
    ]);

    app(LeadWorkspaceService::class)->cancelDemo($demo, 'First cancellation', $user);

    expect(fn () => app(LeadWorkspaceService::class)->cancelDemo($demo->fresh(), 'Second cancellation', $user))
        ->toThrow(InvalidArgumentException::class);

    expect($demo->fresh()->cancellation_reason)->toBe('First cancellation');
});

it('writes an audit activity when a demo is cancelled', function () {
    $user = crmUser([], ['view-crm', 'view-leads', 'view-demos']);
    $lead = Lead::factory()->create(['organization_id' => $user->organization_id]);
    $demo = Demo::factory()->create([
        'lead_id' => $lead->id,
        'status' => 'scheduled',
        'presenter_id' => $user->id,
    ]);

    app(LeadWorkspaceService::class)->cancelDemo($demo, 'Budget frozen this quarter', $user);

    $activity = $lead->activities()->latest()->first();

    expect($activity)->not->toBeNull()
        ->and($activity->description)->toContain('cancelled')
        ->and($activity->description)->toContain('Budget frozen this quarter')
        ->and($activity->properties['demo_id'] ?? null)->toBe($demo->id);
});

it('cancels the pending demo-prep task tied to this demo, and no other task on the lead', function () {
    $user = crmUser([], ['view-crm', 'view-leads', 'view-demos']);
    $lead = Lead::factory()->create(['organization_id' => $user->organization_id]);
    $demo = Demo::factory()->create([
        'lead_id' => $lead->id,
        'status' => 'scheduled',
        'scheduled_at' => now()->addDays(2),
        'presenter_id' => $user->id,
    ]);

    $prepTask = Task::create([
        'title' => "Demo Scheduled: {$lead->company_name}",
        'type' => TaskType::Demo,
        'status' => TaskStatus::Pending,
        'priority' => TaskPriority::High,
        'due_at' => $demo->scheduled_at,
        'assigned_user_id' => $user->id,
        'creator_id' => $user->id,
        'taskable_type' => $lead->getMorphClass(),
        'taskable_id' => $lead->id,
    ]);

    $unrelatedFollowUp = Task::create([
        'title' => 'Unrelated follow-up',
        'type' => TaskType::FollowUp,
        'status' => TaskStatus::Pending,
        'priority' => TaskPriority::Medium,
        'due_at' => now()->addWeek(),
        'assigned_user_id' => $user->id,
        'creator_id' => $user->id,
        'taskable_type' => $lead->getMorphClass(),
        'taskable_id' => $lead->id,
    ]);

    app(LeadWorkspaceService::class)->cancelDemo($demo, 'Client unresponsive', $user);

    expect($prepTask->fresh()->status->value ?? $prepTask->fresh()->status)->toBe(TaskStatus::Cancelled->value)
        ->and($unrelatedFollowUp->fresh()->status->value ?? $unrelatedFollowUp->fresh()->status)->toBe(TaskStatus::Pending->value);
});

it('keeps tenant isolation on demo cancellation', function () {
    $ownerOrg = Organization::factory()->create();
    $strangerOrg = Organization::factory()->create();

    $owner = crmUser(['organization_id' => $ownerOrg->id], ['view-crm', 'view-leads', 'view-demos']);
    $stranger = crmUser(['organization_id' => $strangerOrg->id], ['view-crm', 'view-leads', 'view-demos']);

    $lead = Lead::factory()->create(['organization_id' => $owner->organization_id]);
    $demo = Demo::factory()->create([
        'lead_id' => $lead->id,
        'status' => 'scheduled',
        'presenter_id' => $owner->id,
    ]);

    $this->actingAs($stranger)
        ->post(route('company.crm.demos.cancel', $demo), ['cancellation_reason' => 'Not my demo'])
        ->assertForbidden();

    expect($demo->fresh()->status->value ?? $demo->fresh()->status)->toBe('scheduled');
});

it('blocks a user without demo permissions from cancelling', function () {
    $user = crmUser([], ['view-tasks']);
    $lead = Lead::factory()->create(['organization_id' => $user->organization_id]);
    $demo = Demo::factory()->create([
        'lead_id' => $lead->id,
        'status' => 'scheduled',
        'presenter_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->post(route('company.crm.demos.cancel', $demo), ['cancellation_reason' => 'No permission'])
        ->assertForbidden();
});
