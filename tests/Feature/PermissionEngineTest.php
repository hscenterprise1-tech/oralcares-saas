<?php

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Organization;
use App\Models\User;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Setup standard organization and branch
    $this->org = Organization::factory()->create();
    $this->clinic = Clinic::factory()->create(['organization_id' => $this->org->id]);

    $this->branchA = Branch::factory()->create([
        'organization_id' => $this->org->id,
        'clinic_id' => $this->clinic->id,
        'name' => 'Branch A',
    ]);

    $this->branchB = Branch::factory()->create([
        'organization_id' => $this->org->id,
        'clinic_id' => $this->clinic->id,
        'name' => 'Branch B',
    ]);

    // Make sure we have test permissions
    $this->permManage = Permission::firstOrCreate(['name' => 'manage', 'guard_name' => 'web']);
    $this->permView = Permission::firstOrCreate(['name' => 'view', 'guard_name' => 'web']);

    // Create Global Clinic Owner Role
    setPermissionsTeamId(null);
    $this->ownerRole = Role::firstOrCreate(['name' => 'Clinic Owner', 'guard_name' => 'web', 'branch_id' => null]);
    $this->ownerRole->givePermissionTo($this->permManage);

    // Create Scoped Branch Manager Role (scoped inherently because it is generic,
    // Spatie treats it as scoped if assigned with a team ID)
    $this->managerRole = Role::firstOrCreate(['name' => 'Branch Manager', 'guard_name' => 'web', 'branch_id' => null]);
    $this->managerRole->givePermissionTo($this->permManage);
});

test('global role grants access across all branches', function () {
    $user = User::factory()->create(['organization_id' => $this->org->id, 'user_type' => UserType::ClinicUser]);

    // Assign to Branch A
    setPermissionsTeamId($this->branchA->id);
    $user->assignRole($this->ownerRole);

    // Assign to Branch B
    setPermissionsTeamId($this->branchB->id);
    $user->assignRole($this->ownerRole);

    // Check branch A
    setPermissionsTeamId($this->branchA->id);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
    expect($user->hasPermissionTo('manage'))->toBeTrue();

    // Check branch B
    setPermissionsTeamId($this->branchB->id);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
    expect($user->hasPermissionTo('manage'))->toBeTrue();
});

test('scoped role grants access only to the assigned branch', function () {
    $user = User::factory()->create(['organization_id' => $this->org->id, 'user_type' => UserType::ClinicUser]);

    // Assign role to Branch A ONLY
    setPermissionsTeamId($this->branchA->id);
    $user->assignRole($this->managerRole);

    // User should have permission in Branch A
    setPermissionsTeamId($this->branchA->id);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
    expect($user->hasPermissionTo('manage'))->toBeTrue();

    // User should NOT have permission in Branch B
    setPermissionsTeamId($this->branchB->id);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
    expect($user->hasPermissionTo('manage'))->toBeFalse();
});
