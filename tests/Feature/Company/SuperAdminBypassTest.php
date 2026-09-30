<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Organization;
use App\Models\User;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * The main admin's access is architectural, not granted.
 *
 * `Gate::before` lets a master admin through every ability, so every Company
 * route already served them. The navigation did not know that. It tried to
 * recognise a super admin by role *name* — `Super Admin`, `master-admin` —
 * names no role in this application carries, and a master admin holds no roles
 * at all. Its only other escape hatch was "an empty permission list means show
 * everything", which stopped firing once the shared list began resolving
 * canonical CRM names.
 *
 * The result: a master admin was shown the CRM and nothing else. Marketing,
 * Finance, Payroll and Notifications — every module whose permission sits
 * outside `CrmPermissions::ALIASES` — disappeared from a menu whose routes
 * would have served them.
 *
 * These tests pin both halves: the server authorizes by bypass, the client is
 * told about the bypass, and nothing is handed to the super admin to make the
 * screen work.
 */

/** The permission list the frontend actually receives. */
function sharedPermissionsFor(User $user): array
{
    $method = new ReflectionMethod(HandleInertiaRequests::class, 'resolvedPermissions');
    $method->setAccessible(true);

    return $method->invoke(app(HandleInertiaRequests::class), $user->fresh())->all();
}

/** Every `permission:` named in the Company navigation config. */
function navigationPermissions(): array
{
    $source = File::get(resource_path('js/ovs/layouts/sidebar-configs.ts'));

    preg_match_all("#permission: '([^']+)'#", $source, $matches);

    return array_values(array_unique($matches[1]));
}

beforeEach(function () {
    setPermissionsTeamId(0);

    $this->organization = Organization::factory()->create();

    $this->superAdmin = User::factory()->create(['user_type' => UserType::MasterAdmin]);

    $this->normalUser = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->organization->id,
    ]);
});

/* ──────────────────── the bypass is real, and it is empty ───────────────── */

it('holds no roles and no permissions at all', function () {
    /*
     * The whole point. If this ever starts failing because somebody granted the
     * super admin permissions to fix a screen, the architecture has been
     * abandoned and the bypass is no longer the source of truth.
     */
    expect($this->superAdmin->roles()->count())->toBe(0)
        ->and($this->superAdmin->getAllPermissions())->toBeEmpty()
        ->and($this->superAdmin->getDirectPermissions())->toBeEmpty();
});

it('is not handed view-marketing to make Marketing appear', function () {
    Permission::findOrCreate('view-marketing', 'web');

    expect($this->superAdmin->fresh()->getAllPermissions()->pluck('name'))
        ->not->toContain('view-marketing')
        ->and(sharedPermissionsFor($this->superAdmin))
        ->not->toContain('view-marketing');

    /* And is authorized for it regardless — which is the correct mechanism. */
    expect(Gate::forUser($this->superAdmin)->allows('view-marketing'))->toBeTrue();
});

it('cannot rely on role names, because team scoping hides them', function () {
    /*
     * Why the old `roles.includes('Super Admin')` check never fired.
     *
     * Spatie is configured with teams on `branch_id`, and the Super Admin role
     * in this application is assigned against a branch. A Company-portal
     * request sets no branch — `SetCurrentBranch` only does so inside a clinic
     * context — so the role lookup filters on a team the request does not have
     * and comes back empty. The role exists, the assignment exists, and
     * `getRoleNames()` still returns nothing.
     *
     * That is why the bypass must key off `user_type`, which no team scoping
     * can hide.
     */
    $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
    $role->update(['branch_id' => 1]);

    setPermissionsTeamId(1);
    $this->superAdmin->assignRole($role);

    /* Back on a Company request, where no branch is set. */
    setPermissionsTeamId(0);
    $this->superAdmin->unsetRelation('roles');

    expect($this->superAdmin->fresh()->getRoleNames()->all())->toBe([]);

    /* The bypass survives it, because it never asked about roles. */
    expect(Gate::forUser($this->superAdmin->fresh())->allows('view-marketing'))->toBeTrue();
});

/* ─────────────────────── the client is told about it ────────────────────── */

it('tells the frontend the user is a super admin', function () {
    $this->actingAs($this->superAdmin)
        ->get(route('company.people.payroll.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.is_super_admin', true)
            ->where('auth.user.permissions', fn ($permissions) => ! in_array('view-marketing', (array) $permissions, true))
        );
});

it('does not flag a normal user as a super admin', function () {
    $this->normalUser->givePermissionTo(Permission::findOrCreate('view-payroll', 'web'));

    $this->actingAs($this->normalUser)
        ->get(route('company.people.payroll.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.user.is_super_admin', false));
});

/* ──────────────────────── every module, every route ─────────────────────── */

it('is authorized for every permission the Company navigation asks for', function () {
    $permissions = navigationPermissions();

    expect($permissions)->not->toBeEmpty();

    $refused = collect($permissions)
        ->reject(fn (string $permission) => Gate::forUser($this->superAdmin)->allows($permission))
        ->values()
        ->all();

    /*
     * Marketing's `view-marketing` is in this list, and so is every Finance,
     * Payroll and Admin entry. None of them is granted to this user.
     */
    expect($refused)->toBe([]);
});

it('is authorized for Marketing specifically, holding nothing', function () {
    $marketing = collect(navigationPermissions())
        ->filter(fn (string $permission) => str_contains($permission, 'marketing'))
        ->values();

    expect($marketing)->toContain('view-marketing');

    foreach ($marketing as $permission) {
        expect(Gate::forUser($this->superAdmin)->allows($permission))->toBeTrue();
    }
});

it('opens gated Company routes without holding their permissions', function () {
    $routes = [
        'company.marketing.overview' => 'view-marketing',
        'company.people.payroll.index' => 'view-payroll',
        'company.people.incentives.index' => 'view-incentives',
        /*
         * `company.finance.payroll.index` was here as a second `view-payroll`
         * route. It is gone — the legacy payroll register had already lost its
         * menu entry, leaving a gated route nothing linked to — and the People
         * route above covers the same bypass.
         */
        'company.finance.expenses.index' => 'view-expenses',
    ];

    foreach ($routes as $name => $permission) {
        expect($this->superAdmin->fresh()->getAllPermissions()->pluck('name'))->not->toContain($permission);

        $this->actingAs($this->superAdmin)
            ->get(route($name))
            ->assertSuccessful();
    }
});

/* ───────────────────── normal users still obey the gates ────────────────── */

it('still refuses a normal user the routes they hold no permission for', function () {
    foreach (['view-payroll', 'view-marketing', 'view-expenses'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    /* Given one permission only, so the list is not empty and the rest still bite. */
    $this->normalUser->givePermissionTo('view-payroll');

    $this->actingAs($this->normalUser)->get(route('company.people.payroll.index'))->assertOk();
    $this->actingAs($this->normalUser)->get(route('company.finance.expenses.index'))->assertForbidden();
});

it('does not widen a normal user by resolving their shared permissions', function () {
    foreach (['view-payroll', 'view-marketing'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $this->normalUser->givePermissionTo('view-payroll');

    $shared = sharedPermissionsFor($this->normalUser);

    expect($shared)->toContain('view-payroll')
        ->and($shared)->not->toContain('view-marketing');
});

it('refuses a clinic user the Company portal regardless of the bypass', function () {
    /*
     * The bypass is for master admins. A clinic tenant is stopped by
     * CompanyPortalMiddleware before authorization is even reached.
     */
    $clinicUser = User::factory()->create(['user_type' => UserType::ClinicUser]);

    $this->actingAs($clinicUser)
        ->get(route('company.people.payroll.index'))
        ->assertForbidden();
});

/* ──────────────── the frontend guard reads the server's flag ────────────── */

it('resolves navigation visibility from the shared flag, not from role names', function () {
    $guard = File::get(resource_path('js/hooks/useNavAuthorization.ts'));
    $hook = File::get(resource_path('js/hooks/usePermissions.ts'));

    /* One guard, reading the server's answer. */
    expect($guard)->toContain('isSuperAdmin')
        ->and($hook)->toContain('auth?.user?.is_super_admin === true');

    /*
     * And none of the Company navigation surfaces may reintroduce a role-name
     * check — that is the exact bug this replaced.
     */
    $surfaces = [
        'js/ovs/layouts/sidebar.tsx',
        'js/pages/Company/design/CrmModuleNav.tsx',
        'js/pages/Company/design/CrmCommandPalette.tsx',
        'js/pages/Company/design/CrmShell.tsx',
        'js/components/PermissionGuard.tsx',
        'js/components/app-shell/Sidebar.tsx',
    ];

    foreach ($surfaces as $surface) {
        $source = File::get(resource_path($surface));

        expect($source)->not->toContain("roles.includes('Super Admin')")
            ->and($source)->not->toContain("roles.includes('master-admin')");
    }
});
