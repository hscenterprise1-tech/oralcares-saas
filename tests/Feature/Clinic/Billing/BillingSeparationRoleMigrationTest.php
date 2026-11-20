<?php

use App\ClinicPermission;
use App\ClinicRole;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * The role-template migration for the billing separation changes exactly
 * five grants on the existing global templates and nothing else: a
 * permission a template was given beyond its defaults, and any custom role,
 * stay as they are; running it twice changes nothing more.
 */

beforeEach(function () {
    setPermissionsTeamId(null);
    $this->migration = require base_path('database/migrations/2026_11_02_054332_apply_billing_separation_to_clinic_role_templates.php');
    $permission = fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    $template = fn (ClinicRole $role, array $without, array $with = []) => Role::firstOrCreate(['name' => $role->value, 'guard_name' => 'web', 'branch_id' => null])
        ->syncPermissions(collect($role->permissions())->diff($without)->merge($with)->unique()->map($permission)->all());

    // The templates as they were before the separation, one with a permission added by hand.
    $template(ClinicRole::ClinicAdmin, ['billing.view_own_fees', 'package.sell']);
    $template(ClinicRole::Doctor, ['billing.view_own_fees'], ['billing.view', 'reports.view']);
    // The desk was given package.sell later (2026_11_05), by its own migration, not this one.
    $template(ClinicRole::Receptionist, ['package.sell']);
    $template(ClinicRole::BillingStaff, ['package.sell']);
    $this->custom = Role::firstOrCreate(['name' => 'Front Desk Lite', 'guard_name' => 'web', 'branch_id' => null])
        ->syncPermissions([$permission('patient.view'), $permission('billing.view')]);

    $this->held = function (string $role): array {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return Role::query()->where('name', $role)->whereNull('branch_id')->sole()->permissions()->pluck('name')->sort()->values()->all();
    };
    $this->before = collect([...array_column(ClinicRole::cases(), 'value'), 'Front Desk Lite'])->mapWithKeys(fn (string $role) => [$role => ($this->held)($role)]);
});

test('it grants own fees and package selling where approved, revokes the doctor\'s ledger, and touches nothing else', function () {
    $this->migration->up();
    $this->migration->up();

    $changed = fn (string $role) => [
        'added' => array_values(array_diff(($this->held)($role), $this->before[$role])),
        'removed' => array_values(array_diff($this->before[$role], ($this->held)($role))),
    ];

    expect($changed('Clinic Admin'))->toBe(['added' => ['billing.view_own_fees', 'package.sell'], 'removed' => []])
        ->and($changed('Doctor'))->toBe(['added' => ['billing.view_own_fees'], 'removed' => ['billing.view']])
        ->and($changed('Billing Staff'))->toBe(['added' => ['package.sell'], 'removed' => []])
        ->and($changed('Receptionist'))->toBe(['added' => [], 'removed' => []])
        ->and($changed('Front Desk Lite'))->toBe(['added' => [], 'removed' => []])
        // The permission added by hand to the doctor's template is still there.
        ->and(($this->held)('Doctor'))->toContain('reports.view')
        ->and(($this->held)('Receptionist'))->toContain(ClinicPermission::BillingCreate->value)->not->toContain(ClinicPermission::PackageSell->value);
});

test('rolling it back restores the templates exactly', function () {
    $this->migration->up();
    $this->migration->down();

    foreach ($this->before as $role => $permissions) {
        expect(($this->held)($role))->toBe($permissions);
    }
});
