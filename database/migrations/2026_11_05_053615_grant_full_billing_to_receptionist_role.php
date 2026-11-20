<?php

use App\ClinicPermission;
use App\ClinicRole;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * The desk runs billing in full, as billing staff do (owner's decision,
     * 2026-11-05): `billing.view` (the Billing work center and its ledger)
     * and `discount.apply` on the global Receptionist role template, as
     * `ClinicPermission::defaultRolePermissions` now holds them. Refunds
     * stay the owner's. Nothing else is touched, nor are custom roles. Safe
     * to run again.
     */
    private const PERMISSIONS = [ClinicPermission::BillingView, ClinicPermission::DiscountApply];

    public function up(): void
    {
        $this->apply(fn (Role $role, Permission $permission) => $role->hasPermissionTo($permission) || $role->givePermissionTo($permission));
    }

    public function down(): void
    {
        $this->apply(fn (Role $role, Permission $permission) => $role->hasPermissionTo($permission) && $role->revokePermissionTo($permission));
    }

    /**
     * @param  callable(Role, Permission): mixed  $change
     */
    private function apply(callable $change): void
    {
        setPermissionsTeamId(null);
        $role = Role::query()->where('name', ClinicRole::Receptionist->value)->where('guard_name', 'web')->whereNull('branch_id')->first();
        if (! $role) {
            return;
        }
        foreach (self::PERMISSIONS as $permission) {
            $change($role, Permission::firstOrCreate(['name' => $permission->value, 'guard_name' => 'web']));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
