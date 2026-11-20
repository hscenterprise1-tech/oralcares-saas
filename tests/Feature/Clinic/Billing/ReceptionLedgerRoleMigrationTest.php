<?php

use App\ClinicPermission;
use App\ClinicRole;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * OD-9: the existing Receptionist template loses `billing.view` and nothing
 * else (it keeps billing.create and payment.collect, and any permission the
 * clinic added); other templates and custom roles are untouched; running it
 * twice changes nothing more; rolling back gives the ledger view back.
 */

beforeEach(function () {
    setPermissionsTeamId(null);
    $this->migration = require base_path('database/migrations/2026_11_02_063250_remove_ledger_view_from_reception_role_template.php');
    $permission = fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => ClinicRole::Receptionist->value, 'guard_name' => 'web', 'branch_id' => null])
        ->syncPermissions(collect([...ClinicRole::Receptionist->permissions(), ClinicPermission::BillingView->value, 'reports.view'])->unique()->map($permission)->all());
    Role::firstOrCreate(['name' => ClinicRole::BillingStaff->value, 'guard_name' => 'web', 'branch_id' => null])
        ->syncPermissions(collect(ClinicRole::BillingStaff->permissions())->map($permission)->all());
    Role::firstOrCreate(['name' => 'Desk Plus', 'guard_name' => 'web', 'branch_id' => null])->syncPermissions([$permission('billing.view')]);
    $this->held = function (string $role): array {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return Role::query()->where('name', $role)->whereNull('branch_id')->sole()->permissions()->pluck('name')->sort()->values()->all();
    };
    $this->before = collect([ClinicRole::Receptionist->value, ClinicRole::BillingStaff->value, 'Desk Plus'])->mapWithKeys(fn (string $role) => [$role => ($this->held)($role)]);
});

test('only the receptionist template loses the ledger view; its billing work and custom grants stay', function () {
    $this->migration->up();
    $this->migration->up();

    expect(array_values(array_diff($this->before['Receptionist'], ($this->held)('Receptionist'))))->toBe([ClinicPermission::BillingView->value])
        ->and(($this->held)('Receptionist'))->toContain(ClinicPermission::BillingCreate->value, ClinicPermission::PaymentCollect->value, 'reports.view')
        ->and(($this->held)('Billing Staff'))->toBe($this->before['Billing Staff'])
        ->and(($this->held)('Desk Plus'))->toBe($this->before['Desk Plus']);
});

test('rolling back gives the receptionist template its ledger view back', function () {
    $this->migration->up();
    $this->migration->down();

    expect(($this->held)('Receptionist'))->toBe($this->before['Receptionist']);
});
