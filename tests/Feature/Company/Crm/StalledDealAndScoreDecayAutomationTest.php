<?php

use App\ActivityType;
use App\Jobs\EscalateStalledDealsJob;
use App\Jobs\RecalculateStaleLeadScoresJob;
use App\LeadStatus;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Services\CRM\LeadScoringService;
use App\Services\LeadWorkspaceService;
use App\Shared\Notification\Models\EnterpriseNotification;
use App\TaskStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * CRM final gap closure — staleness and lead scoring were both fully
 * defined (`Lead::scopeStaleFor()`, `LeadScoringService::calculateScore()`)
 * but nothing acted on either over time: a stalled deal was a badge nobody
 * saw a task for, and a lead's score only ever moved on write, so a lead
 * gone quiet for months still carried the figure it earned the day it was
 * last touched. Neither job invents a new definition — both reuse the
 * existing one and add the missing "and then what" on top of it.
 */
beforeEach(function () {
    $this->org = Organization::factory()->create();
    $this->rep = User::factory()->companyUser()->create(['organization_id' => $this->org->id]);
});

/* ── EscalateStalledDealsJob ─────────────────────────────────────────── */

it('creates one follow-up task and one notification for a stalled, assigned deal', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Negotiation,
        'stage_entered_at' => now()->subDays(30),
        'company_name' => 'Quiet Clinic',
    ]);

    (new EscalateStalledDealsJob)->handle(app(LeadWorkspaceService::class));

    $task = Task::where('taskable_type', $lead->getMorphClass())
        ->where('taskable_id', $lead->id)
        ->first();

    expect($task)->not->toBeNull()
        ->and($task->title)->toBe('Stalled deal: Quiet Clinic')
        ->and($task->status)->toBe(TaskStatus::Pending)
        ->and($task->assigned_user_id)->toBe($this->rep->id);

    expect(EnterpriseNotification::where('notifiable_id', $this->rep->id)->count())->toBe(1);
});

it('does not create a second task on a later run while the first is still pending', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Negotiation,
        'stage_entered_at' => now()->subDays(30),
    ]);

    $job = new EscalateStalledDealsJob;
    $job->handle(app(LeadWorkspaceService::class));
    $job->handle(app(LeadWorkspaceService::class));

    expect(Task::where('taskable_type', $lead->getMorphClass())->where('taskable_id', $lead->id)->count())->toBe(1)
        ->and(EnterpriseNotification::where('notifiable_id', $this->rep->id)->count())->toBe(1);
});

it('raises a new escalation once the first one has actually been resolved', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Negotiation,
        'stage_entered_at' => now()->subDays(30),
    ]);

    (new EscalateStalledDealsJob)->handle(app(LeadWorkspaceService::class));
    Task::where('taskable_id', $lead->id)->update(['status' => TaskStatus::Completed->value]);

    (new EscalateStalledDealsJob)->handle(app(LeadWorkspaceService::class));

    expect(Task::where('taskable_type', $lead->getMorphClass())->where('taskable_id', $lead->id)->count())->toBe(2);
});

it('never flags a won or lost deal as stalled', function () {
    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Won,
        'stage_entered_at' => now()->subDays(90),
    ]);
    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Lost,
        'stage_entered_at' => now()->subDays(90),
    ]);

    (new EscalateStalledDealsJob)->handle(app(LeadWorkspaceService::class));

    expect(Task::count())->toBe(0);
});

it('leaves a recently-moved deal alone', function () {
    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Negotiation,
        'stage_entered_at' => now(),
    ]);

    (new EscalateStalledDealsJob)->handle(app(LeadWorkspaceService::class));

    expect(Task::count())->toBe(0);
});

it('skips an unassigned stalled deal, leaving it to the unassigned-lead SLA job instead', function () {
    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => null,
        'status' => LeadStatus::Negotiation,
        'stage_entered_at' => now()->subDays(30),
    ]);

    (new EscalateStalledDealsJob)->handle(app(LeadWorkspaceService::class));

    expect(Task::count())->toBe(0);
});

it('keeps each tenant\'s stalled-deal escalation notifying only that tenant\'s own owner', function () {
    $orgB = Organization::factory()->create();
    $repB = User::factory()->companyUser()->create(['organization_id' => $orgB->id]);

    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Negotiation,
        'stage_entered_at' => now()->subDays(30),
        'company_name' => 'Org A Deal',
    ]);
    Lead::factory()->create([
        'organization_id' => $orgB->id,
        'assigned_user_id' => $repB->id,
        'status' => LeadStatus::Negotiation,
        'stage_entered_at' => now()->subDays(30),
        'company_name' => 'Org B Deal',
    ]);

    (new EscalateStalledDealsJob)->handle(app(LeadWorkspaceService::class));

    $repANotification = EnterpriseNotification::where('notifiable_id', $this->rep->id)->first();
    $repBNotification = EnterpriseNotification::where('notifiable_id', $repB->id)->first();

    expect($repANotification->data['metadata']['lead_id'] ?? null)
        ->not->toBe($repBNotification->data['metadata']['lead_id'] ?? null);
});

/* ── RecalculateStaleLeadScoresJob ───────────────────────────────────── */

/**
 * The formula's own "activity recency" component is what has to move for
 * this job to prove anything — `LeadObserver` already scores every lead
 * correctly *as of the moment it last saved*, so the only way a recompute
 * can legitimately differ is a fact the lead was never re-saved to pick up:
 * here, an activity logged after creation, with nothing touching the lead
 * again afterward — exactly the gap this job exists to close.
 */
it('recomputes the score of an open lead nobody touched today', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->org->id]);
    $initialScore = $lead->fresh()->automated_score;

    $activity = $lead->activities()->create([
        'type' => ActivityType::Note,
        'description' => 'Something happened, but nobody re-saved the lead.',
    ]);
    DB::table('activities')->where('id', $activity->id)->update(['created_at' => now()->subDays(3)]);
    DB::table('leads')->where('id', $lead->id)->update(['updated_at' => now()->subDays(10)]);

    (new RecalculateStaleLeadScoresJob)->handle(app(LeadScoringService::class));

    $fresh = $lead->fresh();

    expect($fresh->automated_score)->toBe($initialScore + 20)
        ->and($fresh->lead_score)->toBe($fresh->automated_score);
});

it('does not touch a lead that was already updated today', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->org->id]);
    $initialScore = $lead->fresh()->automated_score;

    // Backdate an activity exactly as the test above does — if the job ran
    // despite today's own updated_at, the score would move; it must not.
    $activity = $lead->activities()->create(['type' => ActivityType::Note, 'description' => 'Recent.']);
    DB::table('activities')->where('id', $activity->id)->update(['created_at' => now()->subDays(3)]);

    (new RecalculateStaleLeadScoresJob)->handle(app(LeadScoringService::class));

    expect($lead->fresh()->automated_score)->toBe($initialScore);
});

it('never rewrites a won or lost lead\'s score', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'status' => LeadStatus::Won,
    ]);
    $initialScore = $lead->fresh()->automated_score;

    $activity = $lead->activities()->create(['type' => ActivityType::Note, 'description' => 'Backdated.']);
    DB::table('activities')->where('id', $activity->id)->update(['created_at' => now()->subDays(3)]);
    DB::table('leads')->where('id', $lead->id)->update(['updated_at' => now()->subDays(10)]);

    (new RecalculateStaleLeadScoresJob)->handle(app(LeadScoringService::class));

    expect($lead->fresh()->automated_score)->toBe($initialScore);
});

it('does not move updated_at, which the stalled-deal definition itself reads', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->org->id]);
    DB::table('leads')->where('id', $lead->id)->update(['updated_at' => now()->subDays(10)]);
    $before = $lead->fresh()->updated_at;

    (new RecalculateStaleLeadScoresJob)->handle(app(LeadScoringService::class));

    expect($lead->fresh()->updated_at->equalTo($before))->toBeTrue();
});

it('is idempotent — running it twice in a row leaves the same score both times', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->org->id]);
    DB::table('leads')->where('id', $lead->id)->update(['updated_at' => now()->subDays(10)]);

    $job = new RecalculateStaleLeadScoresJob;
    $job->handle(app(LeadScoringService::class));
    $afterFirst = $lead->fresh()->automated_score;

    DB::table('leads')->where('id', $lead->id)->update(['updated_at' => now()->subDays(10)]);
    $job->handle(app(LeadScoringService::class));

    expect($lead->fresh()->automated_score)->toBe($afterFirst);
});
