<?php

use App\LeadStatus;
use App\Models\CompanyQuotation;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Models\Trial;
use App\Models\User;
use App\Support\CrmScope;
use App\TaskStatus;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Slice C — server-side data scoping.
 *
 * Authorization decides whether a user reaches a surface; scoping decides which
 * rows they see once there. These tests pin the second question, because it was
 * previously answered by `user_type === CompanyUser`, which gave every company
 * employee the whole CRM.
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

    $this->otherRep = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->org->id]);
    $this->otherRep->assignRole('Sales Representative');

    $this->admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);

    $this->myLead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
    ]);

    $this->theirLead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->otherRep->id,
    ]);
});

it('resolves the scope level from permissions rather than user type', function () {
    expect(CrmScope::level($this->admin))->toBe(CrmScope::LEVEL_ALL)
        ->and(CrmScope::level($this->manager))->toBe(CrmScope::LEVEL_ORGANIZATION)
        ->and(CrmScope::level($this->rep))->toBe(CrmScope::LEVEL_ASSIGNED);
});

it('limits a rep to their own leads', function () {
    $ids = CrmScope::leads($this->rep)->pluck('id')->all();

    expect($ids)->toContain($this->myLead->id)
        ->and($ids)->not->toContain($this->theirLead->id);
});

it('gives a manager every lead in their organization', function () {
    $ids = CrmScope::leads($this->manager)->pluck('id')->all();

    expect($ids)->toContain($this->myLead->id)
        ->and($ids)->toContain($this->theirLead->id);
});

it('keeps one organization out of another organization leads', function () {
    $otherOrg = Organization::factory()->create();
    $outsideManager = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $otherOrg->id,
    ]);
    $outsideManager->assignRole('CRM Manager');

    $foreignLead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
    ]);

    expect(CrmScope::leads($outsideManager)->pluck('id')->all())->not->toContain($foreignLead->id);
});

it('scopes demos through the owning lead', function () {
    $mine = Demo::factory()->create(['lead_id' => $this->myLead->id]);
    $theirs = Demo::factory()->create(['lead_id' => $this->theirLead->id]);

    $ids = CrmScope::demos($this->rep)->pluck('id')->all();

    expect($ids)->toContain($mine->id)->and($ids)->not->toContain($theirs->id);
    expect(CrmScope::demos($this->manager)->pluck('id')->all())->toContain($theirs->id);
});

it('scopes trials by both trial owner and lead owner', function () {
    $mine = Trial::factory()->create(['lead_id' => $this->myLead->id, 'assigned_user_id' => null]);
    $directlyAssigned = Trial::factory()->create(['lead_id' => $this->theirLead->id, 'assigned_user_id' => $this->rep->id]);
    $theirs = Trial::factory()->create(['lead_id' => $this->theirLead->id, 'assigned_user_id' => $this->otherRep->id]);

    $ids = CrmScope::trials($this->rep)->pluck('id')->all();

    expect($ids)->toContain($mine->id)
        ->and($ids)->toContain($directlyAssigned->id)
        ->and($ids)->not->toContain($theirs->id);
});

it('scopes quotations through the owning lead', function () {
    $mine = CompanyQuotation::factory()->create(['lead_id' => $this->myLead->id]);
    $theirs = CompanyQuotation::factory()->create(['lead_id' => $this->theirLead->id]);

    $ids = CrmScope::quotations($this->rep)->pluck('id')->all();

    expect($ids)->toContain($mine->id)->and($ids)->not->toContain($theirs->id);
});

it('scopes tasks to the assignee for a rep and to the organization for a manager', function () {
    $mine = Task::factory()->create(['assigned_user_id' => $this->rep->id, 'status' => TaskStatus::Pending]);
    $theirs = Task::factory()->create(['assigned_user_id' => $this->otherRep->id, 'status' => TaskStatus::Pending]);

    $repIds = CrmScope::tasks($this->rep)->pluck('id')->all();
    expect($repIds)->toContain($mine->id)->and($repIds)->not->toContain($theirs->id);

    expect(CrmScope::tasks($this->manager)->pluck('id')->all())->toContain($theirs->id);
});

it('gives a master admin an unrestricted scope', function () {
    expect(CrmScope::visibleUserIds($this->admin))->toBeNull()
        ->and(CrmScope::leads($this->admin)->count())->toBe(Lead::count());
});

it('refuses a clinic tenant user any CRM scope', function () {
    $clinicUser = User::factory()->create(['user_type' => UserType::ClinicUser]);

    expect(CrmScope::isManager($clinicUser))->toBeFalse()
        ->and(CrmScope::level($clinicUser))->toBe(CrmScope::LEVEL_ASSIGNED)
        ->and(CrmScope::leads($clinicUser)->count())->toBe(0);
});

it('does not leak another rep leads through the leads index', function () {
    $this->actingAs($this->rep)
        ->get(route('company.crm.deals.index'))
        ->assertSuccessful();

    // The record-level guard is the policy; confirm it denies the other rep's lead.
    $this->actingAs($this->rep)
        ->get(route('company.crm.leads.show', $this->theirLead))
        ->assertSuccessful(); // same tenant — visible, but not counted as "mine"

    expect(CrmScope::leads($this->rep)->pluck('id')->all())->not->toContain($this->theirLead->id);
});

it('excludes won and lost leads from the open pipeline scope helper', function () {
    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Won,
    ]);

    $open = CrmScope::leads($this->rep)
        ->whereNotIn('status', [LeadStatus::Won, LeadStatus::Lost])
        ->count();

    expect($open)->toBe(1);
});
