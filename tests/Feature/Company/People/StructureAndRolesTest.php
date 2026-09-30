<?php

use App\DepartmentStatus;
use App\Models\CompanyDepartment;
use App\Models\CompanyEmployee;
use App\Models\CompanyTeam;
use App\Models\User;
use App\TeamStatus;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * The HQ org chart and the role map.
 *
 * Nine departments, nine teams, fourteen roles and roughly ninety permissions
 * existed in this database with no surface at all — `company_employees` files
 * people against a department and a team, and neither could be seen or changed
 * except by writing SQL.
 */
beforeEach(function () {
    setPermissionsTeamId(0);

    $this->admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);

    $this->department = CompanyDepartment::create([
        'name' => 'Revenue',
        'code' => 'REV',
        'status' => DepartmentStatus::Active,
        'sort_order' => 1,
    ]);
});

/* ─────────────────────────────── departments ───────────────────────────── */

it('lists departments with their teams and headcount', function () {
    $team = $this->department->teams()->create([
        'name' => 'Inside Sales',
        'code' => 'REV-IS',
        'status' => TeamStatus::Active,
    ]);

    CompanyEmployee::create([
        'user_id' => User::factory()->create()->id,
        'employee_code' => 'EMP-STRUCT-1',
        'designation' => 'Rep',
        'department_id' => $this->department->id,
        'team_id' => $team->id,
        'employment_status' => 'active',
        'joining_date' => now()->toDateString(),
    ]);

    $this->actingAs($this->admin)
        ->get(route('company.people.structure.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/People/StructureWorkspace')
            ->has('departments', 1)
            ->where('departments.0.teams_count', 1)
            ->where('departments.0.employees_count', 1)
            ->where('departments.0.teams.0.name', 'Inside Sales')
        );
});

it('creates a department', function () {
    $this->actingAs($this->admin)
        ->post(route('company.people.structure.departments.store'), [
            'name' => 'Customer Operations',
            'code' => 'CUSOPS',
            'status' => DepartmentStatus::Active->value,
        ])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('company_departments', ['code' => 'CUSOPS', 'status' => 'active']);
});

it('refuses a duplicate department code', function () {
    $this->actingAs($this->admin)
        ->post(route('company.people.structure.departments.store'), [
            'name' => 'Another Revenue',
            'code' => 'REV',
            'status' => DepartmentStatus::Active->value,
        ])
        ->assertSessionHasErrors('code');
});

it('retires a department by status rather than deleting it', function () {
    /* Employees point at it by foreign key; the row has to survive. */
    $this->actingAs($this->admin)
        ->put(route('company.people.structure.departments.update', $this->department), [
            'name' => $this->department->name,
            'code' => $this->department->code,
            'status' => DepartmentStatus::Inactive->value,
        ])
        ->assertSessionHasNoErrors();

    expect($this->department->fresh()->status)->toBe(DepartmentStatus::Inactive)
        ->and(CompanyDepartment::whereKey($this->department->id)->exists())->toBeTrue();
});

/* ────────────────────────────────── teams ──────────────────────────────── */

it('creates a team inside its department', function () {
    $this->actingAs($this->admin)
        ->post(route('company.people.structure.teams.store', $this->department), [
            'name' => 'Field Sales',
            'code' => 'REV-FS',
            'status' => TeamStatus::Active->value,
        ])
        ->assertSessionHasNoErrors();

    $team = CompanyTeam::where('code', 'REV-FS')->sole();

    expect($team->department_id)->toBe($this->department->id);
});

it('refuses a team reached through the wrong department', function () {
    $other = CompanyDepartment::create(['name' => 'Support', 'code' => 'SUP', 'status' => DepartmentStatus::Active]);
    $team = $other->teams()->create(['name' => 'Tier 1', 'code' => 'SUP-T1', 'status' => TeamStatus::Active]);

    $this->actingAs($this->admin)
        ->put(route('company.people.structure.teams.update', [$this->department, $team]), [
            'name' => 'Stolen',
            'code' => 'SUP-T1',
            'status' => TeamStatus::Active->value,
        ])
        ->assertNotFound();

    expect($team->fresh()->name)->toBe('Tier 1');
});

/* ────────────────────────────── authorisation ──────────────────────────── */

it('keeps the org chart from a user without the permissions', function () {
    $nobody = User::factory()->create(['user_type' => UserType::CompanyUser]);

    $this->actingAs($nobody)
        ->get(route('company.people.structure.index'))
        ->assertForbidden();
});

it('lets an HR manager read the chart but a viewer not change it', function () {
    /*
     * `employees.view` reads, `departments.manage` writes — the split the two
     * policies already defined and nothing had ever registered.
     */
    foreach (['employees.view', 'departments.manage'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $viewer = User::factory()->create(['user_type' => UserType::CompanyUser]);
    $viewer->givePermissionTo('employees.view');

    $this->actingAs($viewer)->get(route('company.people.structure.index'))->assertOk();

    $this->actingAs($viewer)
        ->post(route('company.people.structure.departments.store'), [
            'name' => 'Nope',
            'code' => 'NOPE',
            'status' => DepartmentStatus::Active->value,
        ])
        ->assertForbidden();
});

/* ────────────────────────────────── roles ──────────────────────────────── */

it('lists roles with their permission and holder counts', function () {
    $role = Role::findOrCreate('Finance Manager', 'web');
    Permission::findOrCreate('view-revenue', 'web');
    $role->givePermissionTo('view-revenue');

    $this->actingAs($this->admin)
        ->get(route('company.people.roles.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/People/RolesWorkspace')
            ->has('roles')
            ->has('permissionCount')
        );
});

it('groups the permission matrix by module across every naming convention', function () {
    /*
     * Permission names come in four shapes — `view-crm`, `crm.view`, `view_crm`
     * and `manage billing`. The matrix has to be legible without renaming any
     * of them.
     */
    $role = Role::findOrCreate('Matrix Role', 'web');

    foreach (['view-crm', 'crm.view', 'view_crm', 'manage billing'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $role->givePermissionTo('view-crm');

    $this->actingAs($this->admin)
        ->get(route('company.people.roles.show', $role))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/People/RoleDetail')
            ->where('role.name', 'Matrix Role')
            ->has('matrix')
            ->where('matrix', fn ($matrix) => collect($matrix)->pluck('module')->contains('Crm'))
        );
});

it('assigns a role inside its own team scope and puts the previous one back', function () {
    /*
     * The team id is global request state. Leaving it moved makes every later
     * permission check look in the wrong team and silently find nothing.
     */
    $role = Role::findOrCreate('Assignable', 'web');
    $target = User::factory()->create(['user_type' => UserType::CompanyUser]);

    setPermissionsTeamId(0);

    $this->actingAs($this->admin)
        ->post(route('company.people.roles.assign', $role), ['user_id' => $target->id])
        ->assertSessionHasNoErrors();

    expect(getPermissionsTeamId())->toBe(0);

    setPermissionsTeamId($role->branch_id);
    expect($target->fresh()->hasRole('Assignable'))->toBeTrue();
});

it('revokes a role from a user', function () {
    $role = Role::findOrCreate('Removable', 'web');
    $target = User::factory()->create(['user_type' => UserType::CompanyUser]);

    setPermissionsTeamId($role->branch_id);
    $target->assignRole($role);
    setPermissionsTeamId(0);

    $this->actingAs($this->admin)
        ->post(route('company.people.roles.revoke', $role), ['user_id' => $target->id])
        ->assertSessionHasNoErrors();

    setPermissionsTeamId($role->branch_id);
    expect($target->fresh()->hasRole('Removable'))->toBeFalse();
});

it('keeps the roles surface from a user without roles.manage', function () {
    $nobody = User::factory()->create(['user_type' => UserType::CompanyUser]);

    $this->actingAs($nobody)->get(route('company.people.roles.index'))->assertForbidden();
    $this->actingAs($nobody)->get(route('company.people.roles.show', Role::findOrCreate('Any', 'web')))->assertForbidden();
});

it('offers no way to edit or delete a role', function () {
    /*
     * `spatie_roles` carries no column marking a system role, and every seeded
     * role has a null `branch_id` — the seeders' template scope. There is no
     * safe rule for who may strip permissions from `Organization Admin`, so the
     * surface does not offer one.
     */
    foreach (['company.people.roles.store', 'company.people.roles.update', 'company.people.roles.destroy'] as $name) {
        expect(Route::has($name))->toBeFalse("route {$name} should not exist yet");
    }
});
