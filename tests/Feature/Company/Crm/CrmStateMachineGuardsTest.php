<?php

use App\DemoStatus;
use App\LeadStatus;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Models\Trial;
use App\Models\User;
use App\TaskPriority;
use App\TaskStatus;
use App\TaskType;
use App\TrialStatus;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Final QA bug hunt — every one of these transitions was previously reachable
 * through an ordinary "update" or "action" endpoint with no check against the
 * record's current state, letting a direct HTTP request move a lead, demo,
 * trial or task through an impossible transition (regress a Won deal,
 * resurrect a cancelled demo, complete an already-cancelled trial, etc).
 */
beforeEach(function () {
    $this->org = Organization::factory()->create();

    $this->rep = grantCrmPermissions(User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->org->id,
    ]));
});

/* ── Lead status bypass via the generic update endpoint ───────────────── */

it('refuses to drag a Won deal back to an earlier stage through the ordinary lead-update endpoint', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'status' => LeadStatus::Won,
    ]);

    $this->actingAs($this->rep)
        ->put(route('company.crm.leads.update', $lead), ['status' => LeadStatus::Contacted->value])
        ->assertSessionHasErrors('stage');

    expect($lead->fresh()->status)->toBe(LeadStatus::Won);
});

it('still allows an ordinary field edit through the same endpoint when status is not touched', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'status' => LeadStatus::Contacted,
    ]);

    $this->actingAs($this->rep)
        ->put(route('company.crm.leads.update', $lead), ['company_name' => 'Renamed Clinic'])
        ->assertSessionHasNoErrors();

    expect($lead->fresh()->company_name)->toBe('Renamed Clinic');
});

it('refuses to assign a lead to a user outside the acting tenant through the update endpoint', function () {
    $otherOrg = Organization::factory()->create();
    $otherUser = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $otherOrg->id,
    ]);

    $lead = Lead::factory()->create(['organization_id' => $this->org->id]);

    $this->actingAs($this->rep)
        ->put(route('company.crm.leads.update', $lead), ['assigned_user_id' => $otherUser->id])
        ->assertSessionHasErrors('assigned_user_id');

    expect($lead->fresh()->assigned_user_id)->not->toBe($otherUser->id);
});

/* ── Demo status machine ───────────────────────────────────────────────── */

it('refuses to resurrect a cancelled demo through the generic demo-update endpoint', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->org->id]);
    $demo = Demo::factory()->create([
        'lead_id' => $lead->id,
        'presenter_id' => $this->rep->id,
        'status' => DemoStatus::Cancelled,
    ]);

    $this->actingAs($this->rep)
        ->put(route('company.crm.demos.update', $demo), ['status' => DemoStatus::Scheduled->value])
        ->assertSessionHasErrors('status');

    expect($demo->fresh()->status)->toBe(DemoStatus::Cancelled);
});

it('refuses to confirm an already-completed demo', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->org->id]);
    $demo = Demo::factory()->create([
        'lead_id' => $lead->id,
        'presenter_id' => $this->rep->id,
        'status' => DemoStatus::Completed,
    ]);

    $this->actingAs($this->rep)
        ->post(route('company.crm.demos.confirm', $demo))
        ->assertSessionHasErrors('confirmation_status');

    expect($demo->fresh()->status)->toBe(DemoStatus::Completed);
});

it('refuses to complete an already-cancelled demo', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->org->id]);
    $demo = Demo::factory()->create([
        'lead_id' => $lead->id,
        'presenter_id' => $this->rep->id,
        'status' => DemoStatus::Cancelled,
    ]);

    $this->actingAs($this->rep)
        ->post(route('company.crm.demos.complete', $demo), ['outcome' => 'interested'])
        ->assertSessionHasErrors('outcome');

    expect($demo->fresh()->status)->toBe(DemoStatus::Cancelled);
});

it('refuses to mark an already-completed demo as no-show', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->org->id]);
    $demo = Demo::factory()->create([
        'lead_id' => $lead->id,
        'presenter_id' => $this->rep->id,
        'status' => DemoStatus::Completed,
    ]);

    $this->actingAs($this->rep)
        ->post(route('company.crm.demos.no-show', $demo))
        ->assertSessionHasErrors('notes');

    expect($demo->fresh()->status)->toBe(DemoStatus::Completed);
});

it('still lets a scheduled demo be confirmed', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->org->id]);
    $demo = Demo::factory()->create([
        'lead_id' => $lead->id,
        'presenter_id' => $this->rep->id,
        'status' => DemoStatus::Scheduled,
    ]);

    $this->actingAs($this->rep)
        ->post(route('company.crm.demos.confirm', $demo))
        ->assertSessionHasNoErrors();

    expect($demo->fresh()->status)->toBe(DemoStatus::Confirmed);
});

/* ── Trial status machine ─────────────────────────────────────────────── */

it('refuses to complete an already-cancelled trial', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->org->id]);
    $trial = Trial::factory()->create([
        'lead_id' => $lead->id,
        'status' => TrialStatus::Cancelled,
    ]);

    $this->actingAs($this->rep)
        ->post(route('company.crm.trials.complete', $trial), ['outcome' => 'interested'])
        ->assertSessionHasErrors('outcome');

    expect($trial->fresh()->status)->toBe(TrialStatus::Cancelled);
});

it('refuses a new extension request on an already-completed trial', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->org->id]);
    $trial = Trial::factory()->create([
        'lead_id' => $lead->id,
        'status' => TrialStatus::Completed,
    ]);

    $this->actingAs($this->rep)
        ->post(route('company.crm.trials.extension-requests.store', $trial), [
            'additional_days' => 7,
            'reason' => 'Wants more time to evaluate',
        ])
        ->assertSessionHasErrors('additional_days');

    expect($trial->fresh()->extensionRequests()->count())->toBe(0);
});

it('still lets an active trial be completed', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->org->id]);
    $trial = Trial::factory()->create([
        'lead_id' => $lead->id,
        'status' => TrialStatus::Active,
    ]);

    $this->actingAs($this->rep)
        ->post(route('company.crm.trials.complete', $trial), ['outcome' => 'interested'])
        ->assertSessionHasNoErrors();

    expect($trial->fresh()->status)->toBe(TrialStatus::Completed);
});

/* ── Task completion guard ────────────────────────────────────────────── */

it('refuses to complete an already-cancelled task on a deal', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
    ]);

    $task = Task::create([
        'title' => 'Follow up',
        'type' => TaskType::FollowUp,
        'status' => TaskStatus::Cancelled,
        'priority' => TaskPriority::Medium,
        'due_at' => now()->addDay(),
        'creator_id' => $this->rep->id,
        'taskable_type' => $lead->getMorphClass(),
        'taskable_id' => $lead->id,
    ]);

    $this->actingAs($this->rep)
        ->post(route('company.crm.leads.tasks.complete', [$lead, $task]))
        ->assertSessionHasErrors('status');

    expect($task->fresh()->status)->toBe(TaskStatus::Cancelled);
});
