<?php

use App\Models\User;
use App\UserType;
use Database\Seeders\CrmRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Slice C — CRM role model.
 *
 * Pins the persona mapping: which company roles hold CRM access, which
 * deliberately hold none, and that reps cannot reach management surfaces.
 */
beforeEach(function () {
    setPermissionsTeamId(0);
    $this->seed(CrmRolePermissionSeeder::class);
});

function userWithRole(string $roleName): User
{
    setPermissionsTeamId(0);
    $user = User::factory()->create(['user_type' => UserType::CompanyUser]);
    $user->assignRole(Role::findByName($roleName, 'web'));
    $user->forgetCachedPermissions();

    return $user->fresh();
}

it('grants CRM read access to CRM personas', function (string $roleName) {
    $this->actingAs(userWithRole($roleName))
        ->get(route('company.crm.deals.index'))
        ->assertSuccessful();
})->with(['CRM Manager', 'Sales Manager', 'Sales Representative', 'Sales Executive']);

it('denies CRM entirely to non-CRM personas', function (string $roleName) {
    $this->actingAs(userWithRole($roleName))
        ->get(route('company.crm.leads.index'))
        ->assertForbidden();
})->with([
    'Finance Manager',
    'Support Manager',
    'Support Executive',
    'HR Manager',
    'Operations Manager',
]);

it('denies the management operations view to a sales representative', function () {
    // view-operations is a manager signal — it unlocks team performance.
    $this->actingAs(userWithRole('Sales Representative'))
        ->get(route('company.crm.operations.index'))
        ->assertForbidden();
});

it('allows the management operations view to a CRM manager', function () {
    /*
     * Loss triage is the Deals "Lost" view now, so the old URL redirects
     * there. The permission it guards is unchanged — a rep is still refused
     * above, a manager is still let through — which is what this pins.
     */
    $this->actingAs(userWithRole('CRM Manager'))
        ->get(route('company.crm.operations.index'))
        ->assertRedirect('/company/crm/deals?view=lost');
});

it('denies plan catalog administration to a sales representative', function () {
    $this->actingAs(userWithRole('Sales Representative'))
        ->get(route('company.admin.packages.index'))
        ->assertForbidden();
});

it('denies sales team administration to a sales representative', function () {
    $this->actingAs(userWithRole('Sales Representative'))
        ->post(route('company.crm.teams.store'), [
            'name' => 'Unauthorized Team',
            'assignment_strategy' => 'round_robin',
        ])
        ->assertForbidden();
});

it('allows sales team administration to a CRM manager', function () {
    $this->actingAs(userWithRole('CRM Manager'))
        ->get(route('company.crm.teams.index'))
        ->assertSuccessful();
});

it('gives a master admin full access without any role', function () {
    $admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);

    expect($admin->roles)->toBeEmpty();

    $this->actingAs($admin)->get(route('company.crm.deals.index'))->assertSuccessful();
    $this->actingAs($admin)->get(route('company.crm.my-workspace'))->assertSuccessful();
    $this->actingAs($admin)->get(route('company.admin.packages.index'))->assertSuccessful();
});

it('leaves a company user with no role without CRM access', function () {
    $user = User::factory()->create(['user_type' => UserType::CompanyUser]);

    $this->actingAs($user)
        ->get(route('company.crm.leads.index'))
        ->assertForbidden();
});

it('preserves non-CRM permissions when the seeder re-runs', function () {
    $role = Role::findByName('Finance Manager', 'web');
    $role->givePermissionTo(Permission::findOrCreate('view-invoices', 'web'));

    $this->seed(CrmRolePermissionSeeder::class);

    expect($role->fresh()->hasPermissionTo('view-invoices'))->toBeTrue();
});

it('assigns a CRM role through the artisan command', function () {
    $user = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'email' => 'rep@oralcares.in',
    ]);

    $this->artisan('crm:assign-role', [
        'email' => 'rep@oralcares.in',
        'role' => 'Sales Representative',
    ])->assertSuccessful();

    setPermissionsTeamId(0);
    expect($user->fresh()->hasRole('Sales Representative'))->toBeTrue();
});

it('refuses to assign a CRM role to a clinic tenant user', function () {
    User::factory()->create([
        'user_type' => UserType::ClinicUser,
        'email' => 'clinic@tenant.in',
    ]);

    $this->artisan('crm:assign-role', [
        'email' => 'clinic@tenant.in',
        'role' => 'Sales Representative',
    ])->assertFailed();
});

it('refuses an unknown role', function () {
    User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'email' => 'someone@oralcares.in',
    ]);

    $this->artisan('crm:assign-role', [
        'email' => 'someone@oralcares.in',
        'role' => 'Supreme Overlord',
    ])->assertFailed();
});
