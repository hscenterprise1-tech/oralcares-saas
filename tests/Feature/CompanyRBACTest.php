<?php

use App\EmploymentStatus;
use App\Models\CompanyDepartment;
use App\Models\CompanyEmployee;
use App\Models\CompanyPermission;
use App\Models\CompanyRole;
use App\Models\User;
use Database\Seeders\CompanyRBACSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

test('roles and permissions can be created and associated', function () {
    $role = CompanyRole::factory()->create(['name' => 'QA Lead', 'key' => 'qa-lead']);
    $permission1 = CompanyPermission::factory()->create(['key' => 'testing.run']);
    $permission2 = CompanyPermission::factory()->create(['key' => 'testing.report']);

    $role->permissions()->sync([$permission1->id, $permission2->id]);

    expect($role->permissions)->toHaveCount(2)
        ->and($role->permissions->pluck('key'))->toContain('testing.run', 'testing.report');
});

test('employee can have multiple roles and inherits permissions from them', function () {
    $role1 = CompanyRole::factory()->create(['key' => 'developer']);
    $role2 = CompanyRole::factory()->create(['key' => 'support-exec']);

    $perm1 = CompanyPermission::factory()->create(['key' => 'code.write']);
    $perm2 = CompanyPermission::factory()->create(['key' => 'support.reply']);

    $role1->permissions()->attach($perm1);
    $role2->permissions()->attach($perm2);

    $user = User::factory()->companyUser()->create();
    $employee = CompanyEmployee::factory()->create(['user_id' => $user->id]);

    $employee->roles()->sync([$role1->id, $role2->id]);

    $this->actingAs($user);

    expect($user->can('code.write'))->toBeTrue()
        ->and($user->can('support.reply'))->toBeTrue()
        ->and($user->can('nonexistent.permission'))->toBeFalse();
});

test('inactive or suspended employee is denied access regardless of roles', function () {
    $role = CompanyRole::factory()->create(['key' => 'ceo']);
    $perm = CompanyPermission::factory()->create(['key' => 'dashboard.view']);
    $role->permissions()->attach($perm);

    $user = User::factory()->companyUser()->create();

    // Inactive status
    $employee = CompanyEmployee::factory()->create([
        'user_id' => $user->id,
        'employment_status' => EmploymentStatus::Inactive,
    ]);
    $employee->roles()->attach($role);

    $this->actingAs($user);
    expect($user->can('dashboard.view'))->toBeFalse();

    // Suspended status
    $employee->update(['employment_status' => EmploymentStatus::Suspended]);
    expect($user->can('dashboard.view'))->toBeFalse();
});

test('inactive role permissions are not inherited by employee', function () {
    $role = CompanyRole::factory()->inactive()->create(['key' => 'inactive-role']);
    $perm = CompanyPermission::factory()->create(['key' => 'dashboard.view']);
    $role->permissions()->attach($perm);

    $user = User::factory()->companyUser()->create();
    $employee = CompanyEmployee::factory()->create(['user_id' => $user->id]);
    $employee->roles()->attach($role);

    $this->actingAs($user);
    expect($user->can('dashboard.view'))->toBeFalse();
});

test('master admin bypasses company RBAC and has all permissions', function () {
    $admin = User::factory()->masterAdmin()->create();

    // Create some permissions in db to register them as company permissions
    CompanyPermission::factory()->create(['key' => 'finance.manage']);

    $this->actingAs($admin);

    expect($admin->can('finance.manage'))->toBeTrue()
        ->and($admin->can('any.nonexistent.permission'))->toBeTrue();
});

test('laravel policies correctly authorize actions based on company permissions', function () {
    $role = CompanyRole::factory()->create(['key' => 'hr-mgr']);
    $perm = CompanyPermission::factory()->create(['key' => 'employees.manage']);
    $role->permissions()->attach($perm);

    $user = User::factory()->companyUser()->create();
    $employee = CompanyEmployee::factory()->create(['user_id' => $user->id]);
    $employee->roles()->attach($role);

    $this->actingAs($user);

    // Can create employee because has employees.manage permission
    expect(Gate::allows('create', CompanyEmployee::class))->toBeTrue();

    // Cannot manage departments because hr-mgr role lacks departments.manage
    expect(Gate::allows('create', CompanyDepartment::class))->toBeFalse();
});

test('seeder initializes default roles and permissions properly', function () {
    // Seed database
    $this->seed(CompanyRBACSeeder::class);

    // Assert roles created
    expect(CompanyRole::count())->toBe(11);

    $ceoRole = CompanyRole::where('key', 'ceo')->first();
    expect($ceoRole->permissions)->not->toBeEmpty();

    $financeExecRole = CompanyRole::where('key', 'finance-executive')->first();
    expect($financeExecRole->permissions->pluck('key'))->toContain('dashboard.view', 'finance.view')
        ->and($financeExecRole->permissions->pluck('key'))->not->toContain('finance.manage');
});
