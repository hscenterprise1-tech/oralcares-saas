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
     * The approved billing separation (OPD-01 / OPD-02) on the existing
     * global clinic role templates (`branch_id` null): exactly these grants
     * and this one revocation, nothing else. Unlike a full template sync, any
     * other permission a template holds is left as it is, and custom roles
     * (any other name) are not touched.
     *
     * - Clinic Admin: + billing.view_own_fees, + package.sell
     * - Doctor: + billing.view_own_fees, − billing.view
     * - Billing Staff: + package.sell
     * - Receptionist: unchanged (keeps billing.create for the check-in fee; no package.sell)
     *
     * Safe to run again: a grant already held and a revocation already made are no-ops.
     *
     * @var array<string, array{grant: list<ClinicPermission>, revoke: list<ClinicPermission>}>
     */
    private const CHANGES = [
        'Clinic Admin' => ['grant' => [ClinicPermission::BillingViewOwnFees, ClinicPermission::PackageSell], 'revoke' => []],
        'Doctor' => ['grant' => [ClinicPermission::BillingViewOwnFees], 'revoke' => [ClinicPermission::BillingView]],
        'Billing Staff' => ['grant' => [ClinicPermission::PackageSell], 'revoke' => []],
    ];

    public function up(): void
    {
        $this->apply(fn (array $change) => [$change['grant'], $change['revoke']]);
    }

    /** The exact opposite: what was granted is taken back, what was revoked is given back. */
    public function down(): void
    {
        $this->apply(fn (array $change) => [$change['revoke'], $change['grant']]);
    }

    /**
     * @param  callable(array{grant: list<ClinicPermission>, revoke: list<ClinicPermission>}): array{0: list<ClinicPermission>, 1: list<ClinicPermission>}  $direction
     */
    private function apply(callable $direction): void
    {
        setPermissionsTeamId(null);

        foreach (self::CHANGES as $name => $change) {
            // Only a clinic role template defined in code, and only its global row.
            if (ClinicRole::tryFrom($name) === null) {
                continue;
            }
            $role = Role::query()->where('name', $name)->where('guard_name', 'web')->whereNull('branch_id')->first();
            if (! $role) {
                continue; // Created later by `SystemRoleTemplateSeeder` from the code template.
            }

            [$grant, $revoke] = $direction($change);
            foreach ($grant as $permission) {
                $role->givePermissionTo(Permission::firstOrCreate(['name' => $permission->value, 'guard_name' => 'web']));
            }
            foreach ($revoke as $permission) {
                if ($role->hasPermissionTo($permission->value)) {
                    $role->revokePermissionTo($permission->value);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
