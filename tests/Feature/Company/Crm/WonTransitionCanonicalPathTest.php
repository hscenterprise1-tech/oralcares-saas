<?php

use App\LeadStatus;
use App\Models\CommercialDocument;
use App\Models\Lead;
use App\Models\MarketingContact;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Task;
use App\Models\User;
use App\Services\CRM\LeadFollowupSyncService;
use App\Services\CustomerProvisioningEngine;
use App\Services\LeadWorkspaceService;
use App\TaskPriority;
use App\TaskStatus;
use App\TaskType;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase 0 regression suite.
 *
 * Prior to Phase 0 there were two ways for a Lead to reach Won:
 *
 *   1. LeadWorkspaceService::transitionStage()  — cancelled tasks, suppressed
 *      marketing, logged the stage change, then provisioned.
 *   2. LeadController@convert -> CustomerProvisioningEngine::provision() —
 *      wrote status = Won directly, skipping all of the above.
 *
 * These tests pin the invariant that there is now exactly ONE canonical Won
 * path, and that every supported entry point runs its full side effects.
 */
function wonReadyLead(array $attributes = []): Lead
{
    $lead = Lead::factory()->create($attributes);

    CommercialDocument::factory()->create([
        'lead_id' => $lead->id,
        'type' => 'agreement',
        'status' => 'accepted',
    ]);

    $invoice = CommercialDocument::factory()->create([
        'lead_id' => $lead->id,
        'type' => 'invoice',
        'status' => 'paid',
    ]);

    Payment::factory()->create([
        'lead_id' => $lead->id,
        'commercial_document_id' => $invoice->id,
        'status' => 'successful',
    ]);

    return $lead->fresh();
}

it('cancels pending sales tasks when a lead is won via transitionStage', function () {
    $actor = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $lead = Lead::factory()->create(['status' => LeadStatus::Negotiation]);

    $pending = Task::create([
        'title' => 'Chase signature',
        'type' => TaskType::FollowUp,
        'status' => TaskStatus::Pending,
        'priority' => TaskPriority::High,
        'due_at' => now()->addDay(),
        'taskable_type' => $lead->getMorphClass(),
        'taskable_id' => $lead->id,
        'creator_id' => $actor->id,
    ]);

    app(LeadWorkspaceService::class)
        ->transitionStage($lead, LeadStatus::Won->value, $actor, 'test');

    expect($pending->fresh()->status->value ?? $pending->fresh()->status)
        ->toBe(TaskStatus::Cancelled->value);
});

it('clears the follow-up schedule when a lead is won', function () {
    $actor = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $lead = Lead::factory()->create([
        'status' => LeadStatus::Negotiation,
        'next_followup_at' => now()->addDays(3),
        'followup_notes' => 'Call back Tuesday',
    ]);

    app(LeadWorkspaceService::class)
        ->transitionStage($lead, LeadStatus::Won->value, $actor, 'test');

    $lead->refresh();

    expect($lead->next_followup_at)->toBeNull()
        ->and($lead->followup_notes)->toBeNull();
});

it('suppresses the linked marketing contact when a lead is won', function () {
    $actor = User::factory()->create(['user_type' => UserType::MasterAdmin]);

    $contact = MarketingContact::create([
        'organization_id' => Organization::factory()->create()->id,
        'name' => 'Suppression Target',
        'phone' => '+919876500011',
        'normalized_phone' => '919876500011',
        'marketing_status' => 'subscribed',
    ]);

    $lead = Lead::factory()->create([
        'status' => LeadStatus::Negotiation,
        'marketing_contact_id' => $contact->id,
    ]);

    app(LeadWorkspaceService::class)
        ->transitionStage($lead, LeadStatus::Won->value, $actor, 'test');

    expect($contact->fresh()->marketing_status)->not->toBe('subscribed');
});

it('writes a stage-change activity when a lead is won', function () {
    $actor = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $lead = Lead::factory()->create(['status' => LeadStatus::Negotiation]);

    app(LeadWorkspaceService::class)
        ->transitionStage($lead, LeadStatus::Won->value, $actor, 'test');

    expect($lead->activities()->count())->toBeGreaterThan(0);
});

it('provisions an organization when a lead is won', function () {
    $actor = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $lead = Lead::factory()->create([
        'status' => LeadStatus::Negotiation,
        'company_name' => 'Northgate Dental',
    ]);

    app(LeadWorkspaceService::class)
        ->transitionStage($lead, LeadStatus::Won->value, $actor, 'test');

    $this->assertDatabaseHas('organizations', ['name' => 'Northgate Dental']);
    expect($lead->fresh()->organization_id)->not->toBeNull();
});

it('runs the full canonical side effects when won through the convert endpoint', function () {
    $actor = User::factory()->create(['user_type' => UserType::MasterAdmin]);

    $contact = MarketingContact::create([
        'organization_id' => Organization::factory()->create()->id,
        'name' => 'Convert Path Contact',
        'phone' => '+919876500022',
        'normalized_phone' => '919876500022',
        'marketing_status' => 'subscribed',
    ]);
    $lead = wonReadyLead([
        'status' => LeadStatus::Negotiation,
        'company_name' => 'Eastside Ortho',
        'marketing_contact_id' => $contact->id,
        'next_followup_at' => now()->addDays(2),
    ]);

    $pending = Task::create([
        'title' => 'Send contract',
        'type' => TaskType::FollowUp,
        'status' => TaskStatus::Pending,
        'priority' => TaskPriority::High,
        'due_at' => now()->addDay(),
        'taskable_type' => $lead->getMorphClass(),
        'taskable_id' => $lead->id,
        'creator_id' => $actor->id,
    ]);

    $this->actingAs($actor)
        ->post(route('company.crm.leads.convert', $lead))
        ->assertRedirect();

    $lead->refresh();

    // The convert path must produce an outcome indistinguishable from the
    // pipeline path — this is the specific bypass Phase 0 closed.
    expect($lead->status->value ?? $lead->status)->toBe(LeadStatus::Won->value)
        ->and($lead->organization_id)->not->toBeNull()
        ->and($lead->next_followup_at)->toBeNull()
        ->and($pending->fresh()->status->value ?? $pending->fresh()->status)->toBe(TaskStatus::Cancelled->value)
        ->and($contact->fresh()->marketing_status)->not->toBe('subscribed')
        ->and($lead->activities()->count())->toBeGreaterThan(0);
});

it('refuses conversion through the commercial gate without a settled quotation', function () {
    $actor = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $lead = Lead::factory()->create(['status' => LeadStatus::Negotiation]);

    $this->actingAs($actor)
        ->post(route('company.crm.leads.convert', $lead))
        ->assertSessionHas('error', 'Lead cannot be converted without an approved quotation settled in full.');

    expect($lead->fresh()->status->value ?? $lead->fresh()->status)
        ->not->toBe(LeadStatus::Won->value);
});

/**
 * Master Audit P0 — Won transition transaction safety.
 *
 * The task-cancellation mass update and the lead's own status save used to
 * be two separate, unguarded statements: a failure between them (the save
 * itself, or a step it triggers) left pending tasks cancelled against a lead
 * that was never actually marked Won. `transitionStage()` now wraps that
 * span — task cancellation, the save, and the follow-up-cache resync — in
 * one transaction, so a failure anywhere in it leaves nothing changed.
 */
it('leaves nothing changed if a step inside the Won transition fails before it commits', function () {
    $actor = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $lead = Lead::factory()->create(['status' => LeadStatus::Negotiation]);

    $pending = Task::create([
        'title' => 'Chase signature',
        'type' => TaskType::FollowUp,
        'status' => TaskStatus::Pending,
        'priority' => TaskPriority::High,
        'due_at' => now()->addDay(),
        'taskable_type' => $lead->getMorphClass(),
        'taskable_id' => $lead->id,
        'creator_id' => $actor->id,
    ]);

    $this->mock(LeadFollowupSyncService::class, function ($mock) {
        $mock->shouldReceive('sync')->andThrow(new RuntimeException('simulated failure after the task cancellation'));
    });

    expect(fn () => app(LeadWorkspaceService::class)->transitionStage($lead, LeadStatus::Won->value, $actor, 'test'))
        ->toThrow(RuntimeException::class);

    expect($lead->fresh()->status->value ?? $lead->fresh()->status)->not->toBe(LeadStatus::Won->value)
        ->and($pending->fresh()->status->value ?? $pending->fresh()->status)->toBe(TaskStatus::Pending->value);
});

/**
 * Provisioning stays outside the transaction by design — it is idempotent
 * and independently retryable — but a failure there must still be visible
 * rather than swallowed, and must not be allowed to undo the sale that
 * already committed.
 */
it('keeps the Won status committed even when provisioning fails afterward, and surfaces the error', function () {
    $actor = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $lead = Lead::factory()->create(['status' => LeadStatus::Negotiation]);

    $this->mock(CustomerProvisioningEngine::class, function ($mock) {
        $mock->shouldReceive('provision')->andThrow(new RuntimeException('simulated provisioning outage'));
    });

    expect(fn () => app(LeadWorkspaceService::class)->transitionStage($lead, LeadStatus::Won->value, $actor, 'test'))
        ->toThrow(RuntimeException::class);

    expect($lead->fresh()->status->value ?? $lead->fresh()->status)->toBe(LeadStatus::Won->value);
});
