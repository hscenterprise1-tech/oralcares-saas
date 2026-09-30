<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Canonical CRM role → permission mapping.
 *
 * This is the single authoritative statement of which company personas hold
 * CRM rights and which do not. It exists because CRM policies authorize on
 * permissions (Phase 0) rather than on `user_type`, so an unmapped role has no
 * CRM access at all — which is the correct default, but only useful once the
 * intended personas are actually mapped.
 *
 * Two deliberate constraints:
 *
 *  1. Non-CRM personas (Finance, Support, HR, Onboarding, Operations) are
 *     listed with an EMPTY CRM set. They are enumerated rather than omitted so
 *     that "this role intentionally has no CRM access" is explicit and any
 *     future addition is a visible decision.
 *
 *  2. `view-operations` is manager-only. CrmDashboardController treats it as a
 *     management signal that unlocks the team-performance table, so granting it
 *     to a rep would silently promote them.
 *
 * Master admins bypass all of this via the Gate::before hook in
 * AppServiceProvider and therefore need no role.
 *
 * This seeder only syncs ROLES. It never assigns roles to users — see
 * `php artisan crm:assign-role` for that, which is deliberately explicit.
 */
class CrmRolePermissionSeeder extends Seeder
{
    /**
     * Every permission the CRM authorizes against.
     *
     * @var list<string>
     */
    public const CRM_PERMISSIONS = [
        'view-crm',
        'view-leads',
        'view-pipeline',
        'view-demos',
        'view-trials',
        'view-quotations',
        'view-operations',
        'view-contacts',
        'view-tasks',
        'view-plans',
        'view-analytics',
        'create leads',
        'edit own leads',
        'convert leads',
        'manage-employees',
        'manage-organizations',
    ];

    /**
     * Rep-level CRM access: work your own pipeline, no management surfaces.
     *
     * @var list<string>
     */
    public const SALES_REP_PERMISSIONS = [
        'view-crm',
        'view-leads',
        'view-pipeline',
        'view-demos',
        'view-trials',
        'view-quotations',
        'view-contacts',
        'view-tasks',
        'create leads',
        'edit own leads',
        'convert leads',
    ];

    /**
     * Management-level CRM access: everything a rep has, plus operations,
     * analytics, plan catalog and team administration.
     *
     * @var list<string>
     */
    public const CRM_MANAGER_PERMISSIONS = [
        ...self::SALES_REP_PERMISSIONS,
        'view-operations',
        'view-analytics',
        'view-plans',
        'manage-employees',
        'manage-organizations',
    ];

    /**
     * Persona → CRM permission set.
     *
     * An empty array means "explicitly no CRM access".
     *
     * @var array<string, list<string>>
     */
    public const ROLE_MAP = [
        'Super Admin' => self::CRM_PERMISSIONS,
        'CRM Manager' => self::CRM_MANAGER_PERMISSIONS,
        'Sales Manager' => self::CRM_MANAGER_PERMISSIONS,
        'Sales Representative' => self::SALES_REP_PERMISSIONS,
        'Sales Executive' => self::SALES_REP_PERMISSIONS,

        // Non-CRM personas — enumerated so the absence is intentional, not an
        // oversight. These roles keep whatever non-CRM permissions they already
        // hold; only their CRM permissions are stripped.
        'Finance Manager' => [],
        'Finance' => [],
        'Support Manager' => [],
        'Support Executive' => [],
        'Support' => [],
        'HR Manager' => [],
        'HR' => [],
        'Operations Manager' => [],
        'Operations' => [],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::CRM_PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (self::ROLE_MAP as $roleName => $crmPermissions) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

            // Preserve the role's non-CRM permissions; only the CRM slice is
            // rewritten from this map. This keeps the seeder safe to re-run
            // against a live database.
            $retained = $role->permissions
                ->pluck('name')
                ->reject(fn ($name) => in_array($name, self::CRM_PERMISSIONS, true))
                ->all();

            $role->syncPermissions(array_values(array_unique([...$retained, ...$crmPermissions])));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
