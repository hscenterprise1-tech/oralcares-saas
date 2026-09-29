<?php

use App\Models\Organization;
use App\Models\Team;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->admin = User::factory()->masterAdmin()->create([
        'organization_id' => $this->organization->id,
    ]);
    actingAs($this->admin);
});

it('displays the teams index scoped to the current organization', function () {
    Team::factory()->create(['organization_id' => $this->organization->id]);
    Team::factory()->create(); // different org, should not appear

    get(route('company.crm.teams.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Crm/Teams/Index')
            ->has('teams', 1)
        );
});

it('creates a round-robin team with members', function () {
    $repA = User::factory()->companyUser()->create(['organization_id' => $this->organization->id]);
    $repB = User::factory()->companyUser()->create(['organization_id' => $this->organization->id]);

    post(route('company.crm.teams.store'), [
        'name' => 'Test Sales Team',
        'assignment_strategy' => 'round_robin',
        'member_ids' => [$repA->id, $repB->id],
    ])->assertRedirect();

    $team = Team::where('name', 'Test Sales Team')->firstOrFail();

    expect($team->organization_id)->toBe($this->organization->id);
    expect($team->users()->count())->toBe(2);
});

it('creates a territory team and sanitizes invalid territory entries', function () {
    $rep = User::factory()->companyUser()->create(['organization_id' => $this->organization->id]);
    $foreignUser = User::factory()->companyUser()->create(); // different org

    post(route('company.crm.teams.store'), [
        'name' => 'Territory Team',
        'assignment_strategy' => 'territory',
        'territory_map' => [
            'Maharashtra' => $rep->id,
            'Gujarat' => $foreignUser->id, // invalid — not in this org, should be dropped
        ],
    ])->assertRedirect();

    $team = Team::where('name', 'Territory Team')->firstOrFail();

    expect($team->territory_map)->toBe(['Maharashtra' => $rep->id]);
});

it('updates a team and resyncs its members', function () {
    $team = Team::factory()->create(['organization_id' => $this->organization->id]);
    $repA = User::factory()->companyUser()->create(['organization_id' => $this->organization->id]);
    $repB = User::factory()->companyUser()->create(['organization_id' => $this->organization->id]);
    $team->users()->attach($repA->id);

    put(route('company.crm.teams.update', $team), [
        'name' => 'Renamed Team',
        'assignment_strategy' => 'round_robin',
        'member_ids' => [$repB->id],
    ])->assertRedirect();

    $team->refresh();

    expect($team->name)->toBe('Renamed Team');
    expect($team->users()->pluck('users.id')->all())->toBe([$repB->id]);
});

it('deletes a team', function () {
    $team = Team::factory()->create(['organization_id' => $this->organization->id]);

    delete(route('company.crm.teams.destroy', $team))->assertRedirect();

    expect(Team::find($team->id))->toBeNull();
});
