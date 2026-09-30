<?php

use App\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadWorkspaceService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase 1 — stage velocity tracking.
 *
 * Previously there was no way to know how long a lead had sat in a stage, so
 * bottleneck and cycle-time analysis were impossible. LeadObserver now banks
 * time per stage on every transition.
 */
it('stamps stage_entered_at when a lead is created', function () {
    $lead = Lead::factory()->create(['status' => LeadStatus::New]);

    expect($lead->stage_entered_at)->not->toBeNull();
});

it('resets stage_entered_at on a stage change', function () {
    $actor = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $lead = Lead::factory()->create(['status' => LeadStatus::New]);

    $originalEnteredAt = $lead->stage_entered_at;

    $this->travel(2)->days();

    app(LeadWorkspaceService::class)
        ->transitionStage($lead, LeadStatus::Contacted->value, $actor, 'test');

    $lead->refresh();

    expect($lead->stage_entered_at->greaterThan($originalEnteredAt))->toBeTrue();
});

it('banks time spent in the outgoing stage', function () {
    $actor = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $lead = Lead::factory()->create(['status' => LeadStatus::New]);

    $this->travel(3)->days();

    app(LeadWorkspaceService::class)
        ->transitionStage($lead, LeadStatus::Contacted->value, $actor, 'test');

    $lead->refresh();

    expect($lead->stage_durations)->toHaveKey('new')
        ->and($lead->stage_durations['new'])->toBeGreaterThan(0);
});

it('accumulates duration across repeated visits to a stage', function () {
    $actor = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $lead = Lead::factory()->create(['status' => LeadStatus::New]);
    $service = app(LeadWorkspaceService::class);

    /*
     * Bounced between two working stages rather than back to intake.
     *
     * A qualified deal can no longer return to New — sending one back there
     * hides it from every working queue and from the conversion rates measured
     * out of them. The behaviour under test is unchanged: a stage visited twice
     * accumulates both visits.
     */
    $service->transitionStage($lead, LeadStatus::Contacted->value, $actor, 'start');

    $this->travel(2)->days();
    $service->transitionStage($lead->fresh(), LeadStatus::DemoScheduled->value, $actor, 'forward');

    $this->travel(1)->days();
    $service->transitionStage($lead->fresh(), LeadStatus::Contacted->value, $actor, 'back');

    $this->travel(2)->days();
    $service->transitionStage($lead->fresh(), LeadStatus::DemoScheduled->value, $actor, 'again');

    $lead->refresh();

    // Contacted is the repeated stage: 2 days, then 2 days again. Demo
    // Scheduled was visited once in between, for 1 day.
    expect($lead->stage_durations['contacted'])->toBeGreaterThan($lead->stage_durations['demo_scheduled']);
});

it('reports current stage age in days', function () {
    $lead = Lead::factory()->create(['status' => LeadStatus::New]);

    $this->travel(5)->days();

    expect($lead->fresh()->stage_age_days)->toBe(5);
});

it('finds leads stale beyond a threshold and excludes terminal stages', function () {
    $stale = Lead::factory()->create(['status' => LeadStatus::Contacted]);
    $won = Lead::factory()->create(['status' => LeadStatus::Won]);
    $lost = Lead::factory()->create(['status' => LeadStatus::Lost]);

    $this->travel(20)->days();

    $fresh = Lead::factory()->create(['status' => LeadStatus::Contacted]);

    $staleIds = Lead::staleFor(14)->pluck('id')->all();

    expect($staleIds)->toContain($stale->id)
        ->and($staleIds)->not->toContain($won->id)
        ->and($staleIds)->not->toContain($lost->id)
        ->and($staleIds)->not->toContain($fresh->id);
});
