<?php

use App\LeadStatus;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\TaskStatus;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Slice C — Sales Teams.
 *
 * Roster is readable by any CRM user; every mutation and every performance
 * figure is management-only.
 */
beforeEach(function () {
    setPermissionsTeamId(0);
    Role::firstOrCreate(['name' => 'CRM Manager', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Sales Representative', 'guard_name' => 'web']);
    grantCrmManagerPermissionsToRole(Role::findByName('CRM Manager', 'web'));
    grantCrmPermissionsToRole(Role::findByName('Sales Representative', 'web'));

    $this->org = Organization::factory()->create();

    $this->manager = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->org->id]);
    $this->manager->assignRole('CRM Manager');

    $this->rep = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->org->id]);
    $this->rep->assignRole('Sales Representative');

    $this->team = Team::create([
        'organization_id' => $this->org->id,
        'name' => 'West Region',
        'key' => 'west-region-'.uniqid(),
        'assignment_strategy' => Team::STRATEGY_ROUND_ROBIN,
        'is_active' => true,
    ]);
});

it('lists teams for a manager with workload', function () {
    $this->team->users()->attach($this->rep->id);
    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Contacted,
        'expected_mrr' => 10000,
        'expected_arr' => 0,
    ]);

    $this->actingAs($this->manager)
        ->get(route('company.crm.teams.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Company/Crm/Teams/Index')
            ->where('can.manage', true)
            ->where('can.view_performance', true)
            ->where('teams.0.workload.open_leads', 1)
            ->where('teams.0.workload.open_pipeline_value', fn ($v) => (float) $v === 120000.0)
        );
});

it('shows a rep the roster but withholds workload and management affordances', function () {
    $this->team->users()->attach($this->rep->id);

    $this->actingAs($this->rep)
        ->get(route('company.crm.teams.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.manage', false)
            ->where('can.view_performance', false)
            ->where('teams.0.workload', null)
        );
});

it('creates a team with a manager and members', function () {
    $this->actingAs($this->manager)
        ->post(route('company.crm.teams.store'), [
            'name' => 'North Region',
            'assignment_strategy' => Team::STRATEGY_ROUND_ROBIN,
            'manager_id' => $this->manager->id,
            'member_ids' => [$this->rep->id],
        ])
        ->assertRedirect();

    $team = Team::where('name', 'North Region')->first();

    expect($team)->not->toBeNull()
        ->and($team->manager_id)->toBe($this->manager->id)
        ->and($team->users->pluck('id')->all())->toBe([$this->rep->id]);
});

it('updates a team', function () {
    $this->actingAs($this->manager)
        ->put(route('company.crm.teams.update', $this->team), [
            'name' => 'West Region Renamed',
            'assignment_strategy' => Team::STRATEGY_TERRITORY,
        ])
        ->assertRedirect();

    expect($this->team->fresh()->name)->toBe('West Region Renamed')
        ->and($this->team->fresh()->assignment_strategy)->toBe(Team::STRATEGY_TERRITORY);
});

it('adds and removes a member without disturbing the rest of the roster', function () {
    $second = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->org->id]);
    $this->team->users()->attach($this->rep->id);

    $this->actingAs($this->manager)
        ->post(route('company.crm.teams.members.store', $this->team), ['user_id' => $second->id])
        ->assertRedirect();

    expect($this->team->fresh()->users->pluck('id')->all())->toContain($this->rep->id, $second->id);

    $this->actingAs($this->manager)
        ->delete(route('company.crm.teams.members.destroy', [$this->team, $second]))
        ->assertRedirect();

    $remaining = $this->team->fresh()->users->pluck('id')->all();
    expect($remaining)->toContain($this->rep->id)->and($remaining)->not->toContain($second->id);
});

it('clears the manager when that manager is removed from the team', function () {
    $this->team->users()->attach($this->manager->id);
    $this->team->update(['manager_id' => $this->manager->id]);

    $this->actingAs($this->manager)
        ->delete(route('company.crm.teams.members.destroy', [$this->team, $this->manager]))
        ->assertRedirect();

    expect($this->team->fresh()->manager_id)->toBeNull();
});

it('refuses to add a clinic tenant user to a sales team', function () {
    $clinicUser = User::factory()->create(['user_type' => UserType::ClinicUser]);

    $this->actingAs($this->manager)
        ->post(route('company.crm.teams.members.store', $this->team), ['user_id' => $clinicUser->id])
        ->assertSessionHasErrors('user_id');

    expect($this->team->fresh()->users)->toBeEmpty();
});

it('toggles a team between active and inactive', function () {
    $this->actingAs($this->manager)
        ->post(route('company.crm.teams.toggle-active', $this->team))
        ->assertRedirect();

    expect($this->team->fresh()->is_active)->toBeFalse();

    $this->actingAs($this->manager)
        ->post(route('company.crm.teams.toggle-active', $this->team));

    expect($this->team->fresh()->is_active)->toBeTrue();
});

it('shows a team detail page with member performance for a manager', function () {
    $this->team->users()->attach($this->rep->id);
    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Won,
    ]);
    Task::factory()->create([
        'assigned_user_id' => $this->rep->id,
        'status' => TaskStatus::Pending,
        'due_at' => now()->subWeek(),
    ]);

    $this->actingAs($this->manager)
        ->get(route('company.crm.teams.show', $this->team))
        ->assertSuccessful()
        ->assertInertia(function (Assert $page) {
            $props = $page->toArray()['props'];

            expect($props['memberPerformance'])->toHaveCount(1)
                ->and($props['memberPerformance'][0]['user_id'])->toBe($this->rep->id)
                ->and($props['memberPerformance'][0]['wins'])->toBe(1)
                ->and($props['memberPerformance'][0]['overdue_followups'])->toBe(1);
        });
});

it('withholds member performance from a rep on the detail page', function () {
    $this->team->users()->attach($this->rep->id);

    $this->actingAs($this->rep)
        ->get(route('company.crm.teams.show', $this->team))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('memberPerformance', null));
});

it('denies every team mutation to a sales representative', function () {
    $this->actingAs($this->rep)
        ->post(route('company.crm.teams.store'), ['name' => 'Nope', 'assignment_strategy' => 'round_robin'])
        ->assertForbidden();

    $this->actingAs($this->rep)
        ->put(route('company.crm.teams.update', $this->team), ['name' => 'Nope', 'assignment_strategy' => 'round_robin'])
        ->assertForbidden();

    $this->actingAs($this->rep)
        ->post(route('company.crm.teams.members.store', $this->team), ['user_id' => $this->rep->id])
        ->assertForbidden();

    $this->actingAs($this->rep)
        ->post(route('company.crm.teams.toggle-active', $this->team))
        ->assertForbidden();

    $this->actingAs($this->rep)
        ->delete(route('company.crm.teams.destroy', $this->team))
        ->assertForbidden();
});

it('refuses to administer another organization team', function () {
    $otherOrg = Organization::factory()->create();
    $outsideManager = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $otherOrg->id]);
    $outsideManager->assignRole('CRM Manager');

    $this->actingAs($outsideManager)
        ->put(route('company.crm.teams.update', $this->team), [
            'name' => 'Hijacked',
            'assignment_strategy' => 'round_robin',
        ])
        ->assertForbidden();

    expect($this->team->fresh()->name)->toBe('West Region');
});

it('ignores a manager who does not belong to the organization', function () {
    $outsider = User::factory()->create(['user_type' => UserType::CompanyUser]);

    $this->actingAs($this->manager)
        ->put(route('company.crm.teams.update', $this->team), [
            'name' => 'West Region',
            'assignment_strategy' => 'round_robin',
            'manager_id' => $outsider->id,
        ])
        ->assertRedirect();

    expect($this->team->fresh()->manager_id)->toBeNull();
});
