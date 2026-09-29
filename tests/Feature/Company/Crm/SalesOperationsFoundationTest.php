<?php

use App\LeadStatus;
use App\LostReason;
use App\Models\CompanyEmployee;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Services\LeadWorkspaceService;
use App\Support\CrmScope;
use App\TaskType;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->organization = Organization::factory()->create();

    $this->employeeUser = grantCrmPermissions(
        User::factory()->companyUser()->create([
            'organization_id' => $this->organization->id,
        ])
    );

    $this->companyEmployee = CompanyEmployee::create([
        'organization_id' => $this->organization->id,
        'user_id' => $this->employeeUser->id,
        'employee_code' => 'EMP-SALES01',
        'employment_status' => 'active',
    ]);
});

it('can triage a lost lead with loss reason, competitor name, and notes', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'status' => LeadStatus::Lost,
        'assigned_user_id' => $this->employeeUser->id,
    ]);

    $response = $this->actingAs($this->employeeUser)
        ->post(route('company.crm.leads.triage', $lead->id), [
            'lost_reason' => LostReason::Competitor->value,
            'lost_competitor' => 'Dentrix Enterprise',
            'notes' => 'Client selected Dentrix due to legacy feature preference.',
        ]);

    $response->assertRedirect();

    $lead->refresh();
    expect($lead->lost_competitor)->toBe('Dentrix Enterprise');
    expect($lead->lost_reason->value)->toBe('Competitor');
});

it('can schedule a win-back task for a lost lead', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'status' => LeadStatus::Lost,
        'assigned_user_id' => $this->employeeUser->id,
    ]);

    $winbackDate = now()->addDays(90)->format('Y-m-d');

    $response = $this->actingAs($this->employeeUser)
        ->post(route('company.crm.leads.winback', $lead->id), [
            'winback_date' => $winbackDate,
            'notes' => 'Follow-up after Dentrix annual contract expires.',
        ]);

    $response->assertRedirect();

    $lead->refresh();
    expect($lead->winback_scheduled_at)->not->toBeNull();

    $task = Task::where('taskable_id', $lead->id)->where('type', TaskType::FollowUp)->first();
    expect($task)->not->toBeNull();
    expect($task->title)->toContain('Win-Back Follow-up');
});

it('computes sales operations payload metrics and competitor loss breakdown', function () {
    Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'status' => LeadStatus::Lost,
        'lost_reason' => LostReason::Competitor,
        'lost_competitor' => 'Practo',
        'expected_mrr' => 50000,
    ]);

    Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'status' => LeadStatus::Won,
        'expected_mrr' => 100000,
    ]);

    $service = app(LeadWorkspaceService::class);
    $payload = $service->getSalesOperationsPayload($this->employeeUser);

    expect($payload['metrics']['total_lost'])->toBe(1);
    expect($payload['metrics']['total_won'])->toBe(1);
    expect($payload['metrics']['overall_win_rate'])->toBe(50.0);
    expect($payload['competitorLoss'])->not->toBeEmpty();
});

it('enforces tenant isolation preventing cross tenant deal triage', function () {
    $otherOrg = Organization::factory()->create();
    $otherLead = Lead::factory()->create([
        'organization_id' => $otherOrg->id,
        'status' => LeadStatus::Lost,
    ]);

    $response = $this->actingAs($this->employeeUser)
        ->post(route('company.crm.leads.triage', $otherLead->id), [
            'lost_reason' => LostReason::Price->value,
        ]);

    $response->assertStatus(403);
});

/*
 * The payload and the page that renders it.
 *
 * The service used to gather a hundred stale leads on every request and send
 * them as `staleLeads`, a prop the workspace has never declared. The count it
 * derived was wrong as well, because the list was capped at a hundred and the
 * cap became the answer. Stale leads are reported by the Overview instead.
 */
it('sends the operations workspace exactly the props it renders', function () {
    $payload = app(LeadWorkspaceService::class)
        ->getSalesOperationsPayload($this->employeeUser);

    expect(array_keys($payload))->toBe([
        'lostLeads',
        'winbackLeads',
        'competitorLoss',
        'lossReasons',
        'repScorecards',
        'metrics',
        'filters',
    ]);

    expect($payload)->not->toHaveKey('staleLeads')
        ->and($payload['metrics'])->not->toHaveKey('stale_lead_count')
        ->and($payload['metrics'])->not->toHaveKey('stale_threshold_days');
});

it('scheduling a win-back twice moves the task rather than stacking a second one', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->employeeUser->id,
        'status' => LeadStatus::Lost,
        'lost_reason' => LostReason::Price,
    ]);

    $service = app(LeadWorkspaceService::class);

    $service->scheduleWinBack($lead, now()->addDays(30)->toDateString(), null, $this->employeeUser);
    $service->scheduleWinBack($lead, now()->addDays(60)->toDateString(), null, $this->employeeUser);

    $tasks = Task::where('taskable_id', $lead->id)
        ->where('type', TaskType::FollowUp)
        ->where('title', 'like', 'Win-Back Follow-up:%')
        ->get();

    expect($tasks)->toHaveCount(1)
        ->and($tasks->first()->due_at->toDateString())->toBe(now()->addDays(60)->toDateString())
        ->and($lead->fresh()->winback_scheduled_at->toDateString())->toBe(now()->addDays(60)->toDateString());
});

it('triage records the loss and can schedule the win-back in one step', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->employeeUser->id,
        'status' => LeadStatus::Lost,
    ]);

    $this->actingAs($this->employeeUser)
        ->post(route('company.crm.leads.triage', $lead), [
            'lost_reason' => LostReason::Competitor->value,
            'lost_competitor' => 'Dentalyst',
            'notes' => 'Undercut us on the annual plan.',
            'winback_date' => now()->addDays(90)->toDateString(),
        ])
        ->assertSessionHasNoErrors();

    $fresh = $lead->fresh();

    expect($fresh->lost_reason->value ?? $fresh->lost_reason)->toBe('Competitor')
        ->and($fresh->lost_competitor)->toBe('Dentalyst')
        ->and($fresh->lost_at)->not->toBeNull()
        /* The chained win-back is the point of doing both in one form. */
        ->and($fresh->winback_scheduled_at->toDateString())->toBe(now()->addDays(90)->toDateString());
});

/* ─────────────────────────── loss reason is an enum ────────────────────── */

it('refuses a loss reason that is not one of the defined reasons', function () {
    /*
     * `leads.lost_reason` casts to `LostReason`, so a value outside it used to
     * be accepted by `required|string` and then throw a ValueError on the way
     * back out — a 500 where a field error belonged.
     */
    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->employeeUser->id,
        'status' => LeadStatus::Lost,
    ]);

    $this->actingAs($this->employeeUser)
        ->post(route('company.crm.leads.triage', $lead), [
            'lost_reason' => 'Budget',
        ])
        ->assertSessionHasErrors('lost_reason');

    expect($lead->fresh()->lost_reason)->toBeNull();
});

it('accepts every reason the triage form offers', function () {
    foreach (LostReason::cases() as $reason) {
        $lead = Lead::factory()->create([
            'organization_id' => $this->organization->id,
            'assigned_user_id' => $this->employeeUser->id,
            'status' => LeadStatus::Lost,
        ]);

        $this->actingAs($this->employeeUser)
            ->post(route('company.crm.leads.triage', $lead), ['lost_reason' => $reason->value])
            ->assertSessionHasNoErrors();

        expect($lead->fresh()->lost_reason)->toBe($reason);
    }
});

it('leaves a loss reason stored before the rule was tightened exactly as it is', function () {
    /* Existing values all sit inside the enum; nothing is rewritten. */
    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'status' => LeadStatus::Lost,
        'lost_reason' => LostReason::Competitor,
    ]);

    expect($lead->fresh()->lost_reason)->toBe(LostReason::Competitor);
});

/* ──────────────────────── canonical scope, same answer ─────────────────── */

it('scopes the operations payload exactly as CrmScope does', function () {
    /*
     * The payload used to hand-roll its tenant filter. Reaching this page needs
     * `view-operations`, the first entry in CrmScope::MANAGER_PERMISSIONS, so
     * every caller is a manager and the two rules agree — this pins that they
     * still do.
     */
    $otherOrg = Organization::factory()->create();

    Lead::factory()->count(2)->create([
        'organization_id' => $this->organization->id,
        'status' => LeadStatus::Lost,
        'lost_reason' => LostReason::Price,
    ]);

    Lead::factory()->count(3)->create([
        'organization_id' => $otherOrg->id,
        'status' => LeadStatus::Lost,
        'lost_reason' => LostReason::Price,
    ]);

    $payload = app(LeadWorkspaceService::class)
        ->getSalesOperationsPayload($this->employeeUser);

    $expected = CrmScope::leads($this->employeeUser)
        ->where('status', LeadStatus::Lost)
        ->count();

    expect(collect($payload['lostLeads'])->count())->toBe($expected)
        ->and($expected)->toBe(2);
});

it('shows a master admin every tenant, as the canonical scope does', function () {
    $otherOrg = Organization::factory()->create();
    $admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);

    Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'status' => LeadStatus::Lost,
        'lost_reason' => LostReason::Price,
    ]);
    Lead::factory()->create([
        'organization_id' => $otherOrg->id,
        'status' => LeadStatus::Lost,
        'lost_reason' => LostReason::Price,
    ]);

    $payload = app(LeadWorkspaceService::class)->getSalesOperationsPayload($admin);

    expect(collect($payload['lostLeads'])->count())->toBe(2);
});
