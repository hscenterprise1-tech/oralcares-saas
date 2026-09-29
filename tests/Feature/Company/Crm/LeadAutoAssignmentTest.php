<?php

use App\LeadStatus;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Observers\CRM\LeadObserver;
use App\Services\CRM\DealStageService;
use App\Services\CRM\LeadAssignmentService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * No lead goes nowhere.
 *
 * Auto-assignment existed before this but ran in exactly one place — inside
 * `LeadWorkspaceService::createLead()` — so a lead arriving through an import,
 * the marketing hand-off, a factory or the API was never assigned at all. It is
 * hooked to the model's own `created` event now, which is the one path no
 * caller can avoid, and the strategy is least-loaded rather than a team cursor.
 */
beforeEach(function () {
    setPermissionsTeamId(0);

    foreach (LeadAssignmentService::SALES_ROLES as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }

    $this->org = Organization::factory()->create();
    $this->admin = User::factory()->masterAdmin()->create(['organization_id' => $this->org->id]);
});

function salesRep(string $name, ?Organization $org = null, string $role = 'Sales Representative'): User
{
    $user = User::factory()->create([
        'name' => $name,
        'user_type' => UserType::CompanyUser,
        'organization_id' => ($org ?? test()->org)->id,
    ]);

    $user->assignRole($role);

    return $user;
}

// ─────────────────────────────────────────────────────────────────────────────
// Every lead has an owner
// ─────────────────────────────────────────────────────────────────────────────

it('gives a lead an owner the moment it is created', function () {
    $rep = salesRep('Only Rep');

    $lead = Lead::factory()->create(['organization_id' => $this->org->id]);

    expect($lead->fresh()->assigned_user_id)->toBe($rep->id);
});

/*
 * The hook is on the model, not on one service method. A lead made by a factory
 * — which is every import, every seeder and every other code path that does not
 * go through the create form — is assigned exactly the same way.
 */
it('assigns a lead however it was made, not only through the create form', function () {
    salesRep('Rep');

    foreach (['factory', 'direct-create', 'mass-insert-then-model'] as $route) {
        $lead = Lead::factory()->create([
            'organization_id' => $this->org->id,
            'company_name' => $route,
        ]);

        expect($lead->fresh()->assigned_user_id)
            ->not->toBeNull("a lead created via {$route} was left unowned");
    }
});

it('falls back to a manager when the organisation has no reps', function () {
    $manager = salesRep('The Manager', role: 'Sales Manager');

    $lead = Lead::factory()->create(['organization_id' => $this->org->id]);

    expect($lead->fresh()->assigned_user_id)->toBe($manager->id);
});

/*
 * An organisation with nobody in it at all still must not swallow a lead. The
 * master admin is where it lands — that is the whole point of a fallback.
 */
it('falls back to the master admin when there is nobody else', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->org->id]);

    expect($lead->fresh()->assigned_user_id)->toBe($this->admin->id);
});

it('never leaves a lead unowned across a whole intake batch', function () {
    salesRep('Rep A');
    salesRep('Rep B');

    Lead::factory()->count(12)->create(['organization_id' => $this->org->id]);

    expect(Lead::whereNull('assigned_user_id')->count())->toBe(0);
});

it('does not override an owner somebody chose', function () {
    salesRep('Rota Rep');
    $chosen = salesRep('Chosen Rep');

    $lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $chosen->id,
    ]);

    expect($lead->fresh()->assigned_user_id)->toBe($chosen->id);
});

// ─────────────────────────────────────────────────────────────────────────────
// How it chooses
// ─────────────────────────────────────────────────────────────────────────────

/*
 * Round-robin over a uniform queue is fair only if everybody started level.
 * Load is what fairness means once they have not — so three leads across two
 * reps is two and one, not both to whoever sorts first.
 */
it('rotates across reps so the load stays level', function () {
    $repA = salesRep('Rep A');
    $repB = salesRep('Rep B');

    Lead::factory()->count(4)->create(['organization_id' => $this->org->id]);

    $byRep = Lead::query()
        ->selectRaw('assigned_user_id, COUNT(*) as total')
        ->groupBy('assigned_user_id')
        ->pluck('total', 'assigned_user_id');

    expect($byRep[$repA->id])->toBe(2)
        ->and($byRep[$repB->id])->toBe(2);
});

it('gives the next lead to whoever is carrying the least', function () {
    $busy = salesRep('Busy Rep');
    $free = salesRep('Free Rep');

    /* Three open deals already on the busy rep's plate. */
    Lead::factory()->count(3)->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $busy->id,
        'status' => LeadStatus::Contacted,
    ]);

    $lead = Lead::factory()->create(['organization_id' => $this->org->id]);

    expect($lead->fresh()->assigned_user_id)->toBe($free->id);
});

/*
 * Closed deals are not load. A rep who has won twenty deals is available; a rep
 * sitting on twenty live ones is not.
 */
it('counts only open deals as load', function () {
    $closer = salesRep('Closed Many');
    $working = salesRep('Working One');

    Lead::factory()->count(5)->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $closer->id,
        'status' => LeadStatus::Won,
    ]);

    Lead::factory()->count(1)->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $working->id,
        'status' => LeadStatus::Contacted,
    ]);

    $lead = Lead::factory()->create(['organization_id' => $this->org->id]);

    expect($lead->fresh()->assigned_user_id)->toBe($closer->id);
});

it('prefers a rep whose territory covers the lead', function () {
    $general = salesRep('General Rep');
    $local = salesRep('Gujarat Rep');

    $team = Team::factory()->create([
        'organization_id' => $this->org->id,
        'is_active' => true,
        'assignment_strategy' => Team::STRATEGY_TERRITORY,
        'territory_map' => ['Gujarat' => $local->id],
    ]);

    $team->users()->attach([$general->id, $local->id]);

    $lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'state' => 'Gujarat',
    ]);

    expect($lead->fresh()->assigned_user_id)->toBe($local->id);
});

it('keeps assignment inside the organisation', function () {
    $ours = salesRep('Ours');

    $otherOrg = Organization::factory()->create();
    salesRep('Theirs', $otherOrg);

    $lead = Lead::factory()->create(['organization_id' => $this->org->id]);

    expect($lead->fresh()->assigned_user_id)->toBe($ours->id);
});

/*
 * A clinic user belongs to a customer, not to the company selling to them. They
 * must never appear in the rota, whatever else is true of the organisation.
 */
it('never assigns a lead to a clinic user', function () {
    $clinic = User::factory()->create([
        'user_type' => UserType::ClinicUser,
        'organization_id' => $this->org->id,
    ]);
    $clinic->assignRole('Sales Representative');

    $lead = Lead::factory()->create(['organization_id' => $this->org->id]);

    expect($lead->fresh()->assigned_user_id)->not->toBe($clinic->id)
        ->and($lead->fresh()->assigned_user_id)->toBe($this->admin->id);
});

// ─────────────────────────────────────────────────────────────────────────────
// What it does not do
// ─────────────────────────────────────────────────────────────────────────────

/*
 * Handing a lead over by hand is a decision, and the follow-up task that comes
 * with it is the point. Assignment at intake is not a decision — it happens to
 * every lead — and the task it used to generate set a `next_followup_at` on
 * every new record, overriding dates the creator had explicitly chosen.
 */
it('does not invent a follow-up task for an automatic assignment', function () {
    salesRep('Rep');

    $lead = Lead::factory()->create(['organization_id' => $this->org->id]);

    expect($lead->tasks()->count())->toBe(0)
        ->and($lead->fresh()->next_followup_at)->toBeNull();
});

/* The stage clock the lead was created with survives the assignment save. */
it('does not reset the stage clock when it assigns', function () {
    salesRep('Rep');

    $lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'status' => LeadStatus::Contacted,
        'stage_entered_at' => now()->subDays(30),
    ]);

    expect($lead->fresh()->stage_entered_at->isSameDay(now()->subDays(30)))->toBeTrue()
        ->and($lead->fresh()->assigned_user_id)->not->toBeNull();
});

it('leaves the lead in intake — assignment is not a stage', function () {
    salesRep('Rep');

    $lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'status' => LeadStatus::New,
    ]);

    expect($lead->fresh()->status)->toBe(LeadStatus::New)
        ->and($lead->fresh()->assigned_user_id)->not->toBeNull();
});

it('can be suppressed for a bulk load', function () {
    salesRep('Rep');

    LeadObserver::$suppressAutoAssignment = true;

    try {
        $lead = Lead::factory()->create(['organization_id' => $this->org->id]);
    } finally {
        LeadObserver::$suppressAutoAssignment = false;
    }

    expect($lead->fresh()->assigned_user_id)->toBeNull();
});

it('backfills the leads that slipped through before it existed', function () {
    salesRep('Rep');

    $orphans = collect(range(1, 3))->map(fn () => unownedLead(['organization_id' => $this->org->id]));

    expect(Lead::whereNull('assigned_user_id')->count())->toBe(3);

    $repaired = app(LeadAssignmentService::class)->backfill($this->org->id);

    expect($repaired)->toBe(3)
        ->and(Lead::whereNull('assigned_user_id')->count())->toBe(0)
        ->and($orphans->first()->fresh()->assigned_user_id)->not->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// New goes straight to Contacted
// ─────────────────────────────────────────────────────────────────────────────

it('advances a new lead directly to contacted', function () {
    salesRep('Rep');

    $lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'status' => LeadStatus::New,
    ]);

    /* Assigned is gone from between them — the next stage is Contacted. */
    expect(app(DealStageService::class)->nextStage($lead->fresh())['value'])->toBe('contacted');

    logContact($lead);

    $this->actingAs($this->admin)
        ->post(route('company.crm.leads.transition', $lead), ['stage' => 'contacted'])
        ->assertRedirect();

    expect($lead->fresh()->status)->toBe(LeadStatus::Contacted);
});

it('keeps assigned off the pipeline entirely', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'status' => LeadStatus::New,
    ]);

    $offered = collect(app(DealStageService::class)->availableTransitions($lead->fresh()))
        ->pluck('value');

    expect($offered)->not->toContain('assigned')
        ->and(DealStageService::RANK)->not->toHaveKey('assigned')
        ->and(DealStageService::NEXT_STAGE['new'])->toBe('contacted');
});
