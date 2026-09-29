<?php

use App\ActivityType;
use App\LeadStatus;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\MarketingContact;
use App\Models\MarketingContactSuppressionLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\CustomerProvisioningEngine;
use App\Services\LeadWorkspaceService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

/**
 * Phase 1 items 1 and 2 — one event, one record.
 *
 * Two writers logged every stage change: LeadWorkspaceService::transitionStage()
 * wrote a rich activity (reason + properties), and LeadObserver::updated() wrote
 * a plain one for any status change from any code path. Both fired, so every
 * transition produced two timeline rows and Won fired the WhatsApp hand-off
 * twice.
 *
 * The observer is now the single writer for both, because it is the only hook
 * that cannot be bypassed — a `$lead->update(['status' => ...])` anywhere still
 * logs. transitionStage hands it the reason and actor through an ephemeral
 * context, the same pattern already used for assignment.
 */
function stageActivitiesFor(Lead $lead): Collection
{
    return Activity::where('subject_type', $lead->getMorphClass())
        ->where('subject_id', $lead->id)
        ->where('description', 'like', 'Moved to %')
        ->get();
}

function actor(): User
{
    return User::factory()->create(['user_type' => UserType::MasterAdmin]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Item 1 — exactly one stage-change activity
// ─────────────────────────────────────────────────────────────────────────────

it('writes exactly one activity for one stage transition', function () {
    $lead = Lead::factory()->create(['status' => LeadStatus::New]);

    app(LeadWorkspaceService::class)
        ->transitionStage($lead, LeadStatus::Contacted->value, actor(), 'first call made');

    expect(stageActivitiesFor($lead))->toHaveCount(1);
});

it('keeps the reason and stage properties on the single activity', function () {
    $lead = Lead::factory()->create(['status' => LeadStatus::New]);

    app(LeadWorkspaceService::class)
        ->transitionStage($lead, LeadStatus::Contacted->value, actor(), 'first call made');

    $activity = stageActivitiesFor($lead)->sole();

    /*
     * The description reads in stage *names* now — it used to print the raw
     * enum values, which is what a person saw in their activity feed. The
     * machine values live in `properties`, which is what the movement report
     * reads, and asserting both is the point of this test: the sentence is for
     * people and the payload is for code, and neither may lose the other's
     * information.
     */
    expect($activity->description)->toContain('New')
        ->and($activity->description)->toContain('Contacted')
        ->and($activity->description)->toContain('first call made')
        ->and($activity->properties['previous_stage'])->toBe('new')
        ->and($activity->properties['new_stage'])->toBe('contacted')
        ->and($activity->type)->toBe(ActivityType::SystemUpdate);
});

it('attributes the activity to the acting user', function () {
    $user = actor();
    $lead = Lead::factory()->create(['status' => LeadStatus::New]);

    app(LeadWorkspaceService::class)
        ->transitionStage($lead, LeadStatus::Contacted->value, $user, 'test');

    expect(stageActivitiesFor($lead)->sole()->user_id)->toBe($user->id);
});

it('still logs a stage change made outside transitionStage', function () {
    // The observer is the catch-all: a direct update must not slip past the
    // timeline just because it bypassed the service.
    $lead = Lead::factory()->create(['status' => LeadStatus::New]);

    $lead->update(['status' => LeadStatus::Contacted]);

    expect(stageActivitiesFor($lead))->toHaveCount(1);
});

it('writes no stage activity when a save does not change the stage', function () {
    $lead = Lead::factory()->create(['status' => LeadStatus::New]);

    app(LeadWorkspaceService::class)->transitionStage($lead, LeadStatus::Contacted->value, actor(), 'test');

    $lead->refresh();
    $lead->update(['followup_notes' => 'unrelated edit']);
    $lead->touch();
    $lead->save();

    expect(stageActivitiesFor($lead))->toHaveCount(1);
});

it('writes one activity per transition across a full lifecycle', function () {
    $user = actor();
    $service = app(LeadWorkspaceService::class);
    $lead = Lead::factory()->create(['status' => LeadStatus::New]);

    /* `assigned` is retired; New goes straight to Contacted. */
    $service->transitionStage($lead, LeadStatus::Contacted->value, $user);
    $service->transitionStage($lead->fresh(), LeadStatus::DemoScheduled->value, $user);
    $service->transitionStage($lead->fresh(), LeadStatus::Negotiation->value, $user);

    expect(stageActivitiesFor($lead))->toHaveCount(3);
});

it('writes exactly one creation activity for a new lead', function () {
    // Same defect class as the stage duplication: createLead() and
    // LeadObserver::created() both logged the birth of a lead.
    $result = app(LeadWorkspaceService::class)->createLead([
        'company_name' => 'Single Entry Dental',
        'contact_name' => 'Test Person',
        'email' => 'single@entry.test',
        'phone' => '+919000000123',
    ], actor());

    $created = Activity::where('subject_id', $result['lead']->id)
        ->where('subject_type', $result['lead']->getMorphClass())
        ->where('description', 'like', 'Lead created%')
        ->get();

    expect($created)->toHaveCount(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// Item 2 — exactly one WhatsApp hand-off on Won
// ─────────────────────────────────────────────────────────────────────────────

function leadWithMarketingContact(): array
{
    $org = Organization::factory()->create();

    $contact = MarketingContact::create([
        'organization_id' => $org->id,
        'name' => 'Hand-off Target',
        'phone' => '+919876500777',
        'normalized_phone' => '919876500777',
        'marketing_status' => 'subscribed',
    ]);

    $lead = Lead::factory()->create([
        'status' => LeadStatus::Negotiation,
        'organization_id' => $org->id,
        'marketing_contact_id' => $contact->id,
    ]);

    return [$lead, $contact];
}

it('suppresses the marketing contact exactly once when a lead is won', function () {
    [$lead, $contact] = leadWithMarketingContact();

    app(LeadWorkspaceService::class)
        ->transitionStage($lead, LeadStatus::Won->value, actor(), 'closed');

    expect($contact->fresh()->marketing_status)->toBe('suppressed')
        ->and(MarketingContactSuppressionLog::where('contact_id', $contact->id)->count())
        ->toBe(1);
});

it('does not repeat the hand-off when the won lead is saved again', function () {
    [$lead, $contact] = leadWithMarketingContact();

    app(LeadWorkspaceService::class)
        ->transitionStage($lead, LeadStatus::Won->value, actor(), 'closed');

    $lead->refresh();
    $lead->update(['followup_notes' => 'post-win note']);
    $lead->touch();

    expect(MarketingContactSuppressionLog::where('contact_id', $contact->id)->count())
        ->toBe(1);
});

it('does not repeat the hand-off when provisioning is re-run', function () {
    [$lead, $contact] = leadWithMarketingContact();

    app(LeadWorkspaceService::class)
        ->transitionStage($lead, LeadStatus::Won->value, actor(), 'closed');

    app(CustomerProvisioningEngine::class)->provision($lead->fresh());
    app(CustomerProvisioningEngine::class)->provision($lead->fresh());

    expect(MarketingContactSuppressionLog::where('contact_id', $contact->id)->count())
        ->toBe(1);
});

it('does not repeat the hand-off when transitionStage is called on an already-won lead', function () {
    [$lead, $contact] = leadWithMarketingContact();
    $user = actor();

    app(LeadWorkspaceService::class)->transitionStage($lead, LeadStatus::Won->value, $user, 'closed');
    app(LeadWorkspaceService::class)->transitionStage($lead->fresh(), LeadStatus::Won->value, $user, 'again');

    expect(MarketingContactSuppressionLog::where('contact_id', $contact->id)->count())
        ->toBe(1)
        ->and(stageActivitiesFor($lead))->toHaveCount(1);
});

it('still fires the hand-off for a win made outside transitionStage', function () {
    [$lead, $contact] = leadWithMarketingContact();

    $lead->update(['status' => LeadStatus::Won]);

    expect($contact->fresh()->marketing_status)->toBe('suppressed')
        ->and(MarketingContactSuppressionLog::where('contact_id', $contact->id)->count())
        ->toBe(1);
});
