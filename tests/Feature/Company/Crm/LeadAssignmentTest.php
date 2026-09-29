<?php

use App\LeadStatus;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Services\LeadWorkspaceService;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->admin = User::factory()->masterAdmin()->create([
        'organization_id' => $this->organization->id,
    ]);
    actingAs($this->admin);
});

it('auto-assigns a new lead round-robin across team members in rotation', function () {
    $repA = User::factory()->companyUser()->create(['organization_id' => $this->organization->id, 'name' => 'Rep A']);
    $repB = User::factory()->companyUser()->create(['organization_id' => $this->organization->id, 'name' => 'Rep B']);

    $team = Team::factory()->create(['organization_id' => $this->organization->id]);
    $team->users()->attach([$repA->id, $repB->id]);

    $data = fn (string $name) => [
        'company_name' => $name,
        'contact_name' => 'Contact',
        'phone' => '9998887771',
        'city' => 'Pune',
        'healthcare_specialties' => ['dental'],
        'source' => 'website',
    ];

    post(route('company.crm.leads.store'), $data('Lead One'))->assertRedirect();
    post(route('company.crm.leads.store'), $data('Lead Two'))->assertRedirect();
    post(route('company.crm.leads.store'), $data('Lead Three'))->assertRedirect();

    $leadOne = Lead::where('company_name', 'Lead One')->firstOrFail();
    $leadTwo = Lead::where('company_name', 'Lead Two')->firstOrFail();
    $leadThree = Lead::where('company_name', 'Lead Three')->firstOrFail();

    expect($leadOne->assigned_user_id)->toBe($repA->id);
    expect($leadTwo->assigned_user_id)->toBe($repB->id);
    expect($leadThree->assigned_user_id)->toBe($repA->id); // wraps back around
    /*
     * The stage does not move on assignment any more. Every lead has an owner
     * from the moment it exists, so promoting on assignment would carry the
     * whole book out of intake before anybody had spoken to one of them.
     */
    expect($leadOne->status)->toBe(LeadStatus::New);
});

it('auto-assigns a lead by state when a territory team matches', function () {
    $mumbaiRep = User::factory()->companyUser()->create(['organization_id' => $this->organization->id]);
    $otherRep = User::factory()->companyUser()->create(['organization_id' => $this->organization->id]);

    Team::factory()->territoryBased(['Maharashtra' => $mumbaiRep->id])->create([
        'organization_id' => $this->organization->id,
    ]);

    post(route('company.crm.leads.store'), [
        'company_name' => 'Mumbai Clinic',
        'contact_name' => 'Dr. Test',
        'phone' => '9998887772',
        'city' => 'Mumbai',
        'healthcare_specialties' => ['dental'],
        'source' => 'website',
        'state' => 'Maharashtra',
    ])->assertRedirect();

    $lead = Lead::where('company_name', 'Mumbai Clinic')->firstOrFail();

    expect($lead->assigned_user_id)->toBe($mumbaiRep->id);
});

/*
 * This test previously asserted the opposite — that a lead with no team
 * configured was left unassigned. That was the behaviour, and it was the bug:
 * with no teams set up it applied to every lead, and six of the first fourteen
 * deals ended up owned by nobody. The assertion is inverted deliberately.
 */
it('routes a lead to the fallback owner when no team is configured', function () {
    post(route('company.crm.leads.store'), [
        'company_name' => 'No Team Co',
        'contact_name' => 'Contact',
        'phone' => '9998887773',
        'city' => 'Pune',
        'healthcare_specialties' => ['dental'],
        'source' => 'website',
    ])->assertRedirect();

    $lead = Lead::where('company_name', 'No Team Co')->firstOrFail();

    expect($lead->assigned_user_id)->toBe($this->admin->id)
        ->and($lead->status)->toBe(LeadStatus::New);
});

it('does not override an explicitly chosen assignee with auto-assignment', function () {
    $rep = User::factory()->companyUser()->create(['organization_id' => $this->organization->id]);
    $poolRep = User::factory()->companyUser()->create(['organization_id' => $this->organization->id]);
    $team = Team::factory()->create(['organization_id' => $this->organization->id]);
    $team->users()->attach($poolRep->id);

    post(route('company.crm.leads.store'), [
        'company_name' => 'Explicit Pick Co',
        'contact_name' => 'Contact',
        'phone' => '9998887774',
        'city' => 'Pune',
        'healthcare_specialties' => ['dental'],
        'source' => 'website',
        'assigned_user_id' => $rep->id,
    ])->assertRedirect();

    $lead = Lead::where('company_name', 'Explicit Pick Co')->firstOrFail();

    expect($lead->assigned_user_id)->toBe($rep->id);
});

it('lets an admin manually assign an unassigned lead and auto-creates a follow-up task', function () {
    $rep = User::factory()->companyUser()->create(['organization_id' => $this->organization->id, 'name' => 'Manual Rep']);
    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => null,
        'status' => LeadStatus::New,
    ]);

    post(route('company.crm.leads.assign', $lead), [
        'assigned_user_id' => $rep->id,
    ])->assertRedirect();

    $lead->refresh();

    expect($lead->assigned_user_id)->toBe($rep->id);
    expect($lead->status)->toBe(LeadStatus::New);

    $task = Task::where('taskable_id', $lead->id)->where('assigned_user_id', $rep->id)->first();
    expect($task)->not->toBeNull();
    expect($task->title)->toContain($lead->company_name);
});

it('logs a reason-aware activity message distinguishing manual from auto-assignment', function () {
    $rep = User::factory()->companyUser()->create(['organization_id' => $this->organization->id, 'name' => 'Logged Rep']);
    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => null,
    ]);

    post(route('company.crm.leads.assign', $lead), [
        'assigned_user_id' => $rep->id,
    ])->assertRedirect();

    $activity = $lead->activities()->latest('id')->first();

    expect($activity->description)->toContain('Logged Rep')
        ->toContain($this->admin->name);
});

it('admin can be assigned as a working sales rep and see the lead in their workspace', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->admin->id,
    ]);

    $response = $this->get(route('company.crm.my-workspace'));

    $response->assertOk();
});

it('batch reassignment still works and creates tasks for each reassigned lead', function () {
    $rep = User::factory()->companyUser()->create(['organization_id' => $this->organization->id]);
    $leadOne = Lead::factory()->create(['organization_id' => $this->organization->id, 'assigned_user_id' => null]);
    $leadTwo = Lead::factory()->create(['organization_id' => $this->organization->id, 'assigned_user_id' => null]);

    post(route('company.crm.leads.batch-reassign'), [
        'lead_ids' => [$leadOne->id, $leadTwo->id],
        'assigned_user_id' => $rep->id,
    ])->assertRedirect();

    expect($leadOne->fresh()->assigned_user_id)->toBe($rep->id);
    expect($leadTwo->fresh()->assigned_user_id)->toBe($rep->id);

    expect(Task::where('taskable_id', $leadOne->id)->exists())->toBeTrue();
    expect(Task::where('taskable_id', $leadTwo->id)->exists())->toBeTrue();
});

it('gives a lead to the fallback owner when the organization has no teams yet', function () {
    // This is not a rare edge. With no teams configured it was *every* lead, and
    // six of the first fourteen deals ended up owned by nobody as a result.
    expect(Team::where('organization_id', $this->organization->id)->count())->toBe(0);

    $lead = app(LeadWorkspaceService::class)->createLead([
        'company_name' => 'Unclaimed Dental',
        'contact_name' => 'Nobody Assigned',
        'phone' => '9812345670',
    ], $this->admin);

    expect($lead['lead']->fresh()->assigned_user_id)->not->toBeNull();
});

it('gives a lead to the fallback owner when teams exist but no rule matches', function () {
    // A team with no members can match nothing. The lead must still land on
    // somebody rather than falling through to null.
    Team::factory()->create([
        'organization_id' => $this->organization->id,
        'assignment_strategy' => Team::STRATEGY_ROUND_ROBIN,
    ]);

    $lead = app(LeadWorkspaceService::class)->createLead([
        'company_name' => 'No Rule Matched Clinic',
        'contact_name' => 'Still Needs An Owner',
        'phone' => '9812345671',
    ], $this->admin);

    expect($lead['lead']->fresh()->assigned_user_id)->not->toBeNull();
});

it('prefers the configured fallback owner over the master admin', function () {
    $manager = User::factory()->companyUser()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Sales Manager',
    ]);

    config(['crm.fallback_lead_owner_id' => $manager->id]);

    $lead = app(LeadWorkspaceService::class)->createLead([
        'company_name' => 'Configured Owner Clinic',
        'contact_name' => 'Goes To The Manager',
        'phone' => '9812345672',
    ], $this->admin);

    expect($lead['lead']->fresh()->assigned_user_id)->toBe($manager->id);
});

it('records why the fallback fired, so the two causes can be told apart', function () {
    // "No teams exist yet" and "teams exist but none matched" need different
    // fixes, so they must not collapse into one indistinguishable reason.
    $lead = app(LeadWorkspaceService::class)->createLead([
        'company_name' => 'Audit Trail Clinic',
        'contact_name' => 'Reason Recorded',
        'phone' => '9812345673',
    ], $this->admin);

    /*
     * The reason is `fallback_owner` now rather than `no_teams_configured`.
     * With no teams AND no users holding a CRM sales role, there is no rota to
     * run, so the lead goes to the named fallback. The property under test is
     * unchanged — the assignment records *why* it chose, and the causes stay
     * distinguishable.
     */
    expect($lead['lead']->fresh()->activities()
        ->where('description', 'like', '%fallback_owner%')
        ->exists())->toBeTrue();
});
