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
     * The approved ledger restriction for the front desk (OD-9) on the
     * existing global Receptionist template (`branch_id` null): it loses
     * `billing.view` and nothing else. It keeps `billing.create` and
     * `payment.collect`, so the check-in fee and a consultation's checkout
     * still work. Other templates and custom roles are not touched.
     *
     * Safe to run again: a revocation already made is a no-op.
     */
    public function up(): void
    {
        $this->receptionist(function (Role $role) {
            // No `billing.view` row at all (a fresh install) means nothing to revoke.
            $exists = Permission::query()->where('name', ClinicPermission::BillingView->value)->where('guard_name', 'web')->exists();
            if ($exists && $role->hasPermissionTo(ClinicPermission::BillingView->value)) {
                $role->revokePermissionTo(ClinicPermission::BillingView->value);
            }
        });
    }

    /** Gives the template its ledger view back. */
    public function down(): void
    {
        $this->receptionist(fn (Role $role) => $role->givePermissionTo(Permission::firstOrCreate(['name' => ClinicPermission::BillingView->value, 'guard_name' => 'web'])));
    }

    /** @param  callable(Role): void  $change */
    private function receptionist(callable $change): void
    {
        setPermissionsTeamId(null);
        $role = Role::query()->where('name', ClinicRole::Receptionist->value)->where('guard_name', 'web')->whereNull('branch_id')->first();
        if ($role) {
            $change($role);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
