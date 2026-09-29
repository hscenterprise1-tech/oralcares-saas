<?php

use App\Models\CompanyEmployee;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Final QA bug hunt — cross-tenant writes reachable through record ids in
 * the request body rather than the URL, which none of these endpoints
 * checked before: a quotation raised against a foreign lead, a task filed
 * against a foreign lead, an assignment handing a record to a user outside
 * the acting tenant, and a foreign user added to a sales team.
 */
beforeEach(function () {
    $this->orgA = Organization::factory()->create();
    $this->orgB = Organization::factory()->create();

    $this->repA = grantCrmPermissions(User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->orgA->id,
    ]));

    $this->userB = grantCrmPermissions(User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->orgB->id,
    ]));

    $this->leadB = Lead::factory()->create(['organization_id' => $this->orgB->id]);
});

it('refuses to raise a quotation against another organisation\'s lead', function () {
    $this->actingAs($this->repA)
        ->post(route('company.crm.quotations.store'), [
            'lead_id' => $this->leadB->id,
            'items' => [[
                'title' => 'Attempted cross-tenant line',
                'quantity' => 1,
                'unit_price' => 1000,
            ]],
        ])
        ->assertForbidden();

    expect(CompanyQuotation::where('lead_id', $this->leadB->id)->count())->toBe(0);
});

it('refuses to file a task against another organisation\'s lead via the global task composer', function () {
    $this->actingAs($this->repA)
        ->post(route('company.crm.tasks.store'), [
            'title' => 'Injected cross-tenant task',
            'status' => 'pending',
            'type' => 'follow_up',
            'priority' => 'medium',
            'due_at' => now()->addDay()->toDateString(),
            'taskable_type' => 'lead',
            'taskable_id' => $this->leadB->id,
        ])
        ->assertSessionHasErrors('taskable_id');

    expect(Task::where('taskable_id', $this->leadB->id)->where('taskable_type', 'lead')->count())->toBe(0);
});

it('refuses to assign a lead task to a user outside the acting organisation', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->orgA->id]);

    $this->actingAs($this->repA)
        ->post(route('company.crm.leads.tasks.store', $lead), [
            'title' => 'Follow up',
            'assigned_user_id' => $this->userB->id,
        ])
        ->assertSessionHasErrors('assigned_user_id');

    expect(Task::where('title', 'Follow up')->first()?->assigned_user_id)->not->toBe($this->userB->id);
});

it('refuses to assign a lead to a user outside the acting organisation via the dedicated assign endpoint', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->orgA->id]);

    $this->actingAs($this->repA)
        ->post(route('company.crm.leads.assign', $lead), ['assigned_user_id' => $this->userB->id])
        ->assertSessionHasErrors('assigned_user_id');

    expect($lead->fresh()->assigned_user_id)->not->toBe($this->userB->id);
});

it('refuses to add a user from another organisation to a sales team', function () {
    $team = Team::factory()->create(['organization_id' => $this->orgA->id]);
    $manager = grantCrmPermissions(User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->orgA->id,
    ]), ['crm.teams.manage', 'crm.teams.view', 'view-crm']);

    $this->actingAs($manager)
        ->post(route('company.crm.teams.members.store', $team), ['user_id' => $this->userB->id])
        ->assertSessionHasErrors('user_id');

    expect($team->fresh()->users()->pluck('users.id'))->not->toContain($this->userB->id);
});

it('still allows raising a quotation against the acting organisation\'s own lead', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->orgA->id]);
    CompanyEmployee::factory()->create(['user_id' => $this->repA->id]);

    $this->actingAs($this->repA)
        ->post(route('company.crm.quotations.store'), [
            'lead_id' => $lead->id,
            'items' => [[
                'title' => 'Legitimate same-tenant line',
                'quantity' => 1,
                'unit_price' => 1000,
            ]],
        ])
        ->assertSessionHasNoErrors();

    expect(CompanyQuotation::where('lead_id', $lead->id)->exists())->toBeTrue();
});

it('still allows assigning a lead to a colleague in the same organisation', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->orgA->id]);
    $colleague = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->orgA->id,
    ]);

    $this->actingAs($this->repA)
        ->post(route('company.crm.leads.assign', $lead), ['assigned_user_id' => $colleague->id])
        ->assertSessionHasNoErrors();

    expect($lead->fresh()->assigned_user_id)->toBe($colleague->id);
});
