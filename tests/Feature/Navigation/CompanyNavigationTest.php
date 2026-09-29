<?php

use App\Models\CompanyEmployee;
use App\Models\Organization;
use App\Models\User;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * The navigation may only offer destinations that exist.
 *
 * `sidebar-configs.ts` is TypeScript, so nothing in the PHP application imports
 * it and no compiler checks its hrefs against the router. A destination can be
 * renamed on the server and go on being offered by the menu indefinitely — the
 * reader finds out by clicking it.
 *
 * This suite is the join. It reads the registry as text and checks it against
 * the live route collection, which is the only place the two can be compared.
 *
 * @return list<string>
 */
function navigationHrefs(): array
{
    $source = File::get(base_path('resources/js/ovs/layouts/sidebar-configs.ts'));

    /*
     * Everything from COMPANY_MODULES through COMPANY_WORKSPACES — the pillars
     * and the create actions. ENTERPRISE_NAV_GROUPS below it is the clinic
     * portal's own config and is not in scope here.
     */
    $start = strpos($source, 'export const COMPANY_MODULES');
    $end = strpos($source, 'export const ITEM_INDEX') ?: strpos($source, '// ─────');

    preg_match_all("/href: '([^']+)'/", substr($source, $start, ($end ?: strlen($source)) - $start), $matches);

    return collect($matches[1])
        /* A query string is a filter on a page, not a different page. */
        ->map(fn (string $href) => explode('?', $href)[0])
        ->unique()
        ->values()
        ->all();
}

/** Every GET path the router will actually serve. */
function registeredGetPaths(): array
{
    return collect(Route::getRoutes())
        ->filter(fn ($route) => in_array('GET', $route->methods(), true))
        ->map(fn ($route) => '/'.ltrim((string) $route->uri(), '/'))
        ->unique()
        ->values()
        ->all();
}

/* ═════════════════ 1. every declared href resolves to a route ════════════ */

it('offers no destination the router cannot serve', function () {
    $registered = registeredGetPaths();

    $missing = collect(navigationHrefs())
        ->reject(fn (string $href) => in_array($href, $registered, true))
        ->values()
        ->all();

    expect($missing)->toBe(
        [],
        'The navigation offers destinations with no GET route: '.implode(', ', $missing)
    );
});

it('declares a non-trivial number of destinations', function () {
    /*
     * The assertion above passes vacuously if the parser stops matching — a
     * regex that finds nothing agrees with every route collection. This is the
     * guard on the guard: the five pillars carry well over thirty destinations
     * between them, so a count in single figures means the parse broke, not
     * that the product shrank.
     */
    expect(count(navigationHrefs()))->toBeGreaterThan(30);
});

it('offers no destination that only redirects to another one', function () {
    /*
     * A redirect in the menu presents one destination under two names, and the
     * reader cannot tell which of the two they are looking at. The registry's
     * own rule: every href is a real page.
     */
    $redirecting = collect(Route::getRoutes())
        ->filter(fn ($route) => in_array('GET', $route->methods(), true))
        ->filter(fn ($route) => str_contains((string) $route->getActionName(), 'RedirectController'))
        ->map(fn ($route) => '/'.ltrim((string) $route->uri(), '/'))
        ->values()
        ->all();

    $offered = array_values(array_intersect(navigationHrefs(), $redirecting));

    expect($offered)->toBe([], 'The navigation points at redirects: '.implode(', ', $offered));
});

/* ═════════════════ 2. the dead Finance payroll route is gone ═════════════ */

it('no longer routes anything at the legacy Finance payroll register', function () {
    /*
     * It was the last reader of `company_payrolls` — eighteen rows that predate
     * periods, compensation and approval — and its menu entry had already been
     * removed for that reason. What was left was a gated, working, bookmarkable
     * URL that nothing in the product linked to.
     */
    $paths = collect(Route::getRoutes())
        ->map(fn ($route) => (string) $route->uri())
        ->filter(fn (string $uri) => str_starts_with($uri, 'company/finance/payroll'))
        ->values()
        ->all();

    expect($paths)->toBe([])
        ->and(Route::has('company.finance.payroll.index'))->toBeFalse();
});

it('no longer ships the Finance payroll page, and nothing links to it', function () {
    expect(File::exists(resource_path('js/pages/Company/Finance/PayrollWorkspace.tsx')))->toBeFalse();

    /*
     * `resources/js` only. The route's removal is explained in a comment in
     * `routes/web.php` and in several test docblocks, and a comment naming a
     * retired path is documentation rather than a link.
     */
    $linking = collect(File::allFiles(resource_path('js')))
        ->filter(fn ($file) => in_array($file->getExtension(), ['ts', 'tsx'], true))
        ->filter(function ($file) {
            $source = File::get($file->getPathname());

            return preg_match("#href[=:]\s*[\"'`]/company/finance/payroll#", $source) === 1
                || str_contains($source, "'Company/Finance/PayrollWorkspace'");
        })
        ->map(fn ($file) => str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()))
        ->values()
        ->all();

    expect($linking)->toBe([], 'Still linking to the retired Finance payroll page: '.implode(', ', $linking));
});

/* ═══════ 3. personal Attendance and Leave are reachable without HR ═══════ */

/**
 * A company user with an employee record and no HR grants at all.
 *
 * This is the persona the fix is for: a salesperson looking up their own leave
 * balance, who will never hold `view-attendance` or `view-leave` because those
 * are the company-wide read grants a People manager holds.
 */
function employeeWithoutHrPermissions(Organization $organization): User
{
    setPermissionsTeamId(0);

    foreach (['view-crm', 'view-leads'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $role = Role::firstOrCreate(['name' => 'Sales Rep (nav test)', 'guard_name' => 'web']);
    $role->syncPermissions(['view-crm', 'view-leads']);

    $user = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $organization->id,
    ]);
    $user->assignRole($role);

    CompanyEmployee::factory()->create(['user_id' => $user->id]);

    return $user->fresh();
}

it('does not gate Attendance or Leave behind a company-wide HR permission', function () {
    /*
     * The defect: the routes carry no `can:` guard and no `Gate::authorize` —
     * both controllers narrow rows through `PeopleScope::restrict()` instead,
     * so an ordinary employee sees their own day and a manager sees their
     * team's. The server was already right.
     *
     * The menu was not. Declaring `view-attendance` on the nav item made
     * `useNavAuthorization` hide the entry from every employee who lacks the
     * company-wide grant — that is, from exactly the people the page exists to
     * serve, while the route would have served them. A menu stricter than the
     * server it fronts is the same class of bug as one looser than it.
     */
    $source = File::get(base_path('resources/js/ovs/layouts/sidebar-configs.ts'));

    foreach (['people-attendance' => 'view-attendance', 'people-leave' => 'view-leave'] as $id => $permission) {
        preg_match("/\{ id: '{$id}'.*?\},/s", $source, $declaration);

        expect($declaration[0] ?? '')
            ->not->toBeEmpty("No navigation item declared with id \"{$id}\".")
            ->not->toContain("permission: '{$permission}'", "\"{$id}\" is still gated on {$permission}.")
            ->not->toContain('permission:', "\"{$id}\" carries a permission; row scoping is the guard here.");
    }
});

it('serves Attendance and Leave to an employee holding no HR permissions', function () {
    $user = employeeWithoutHrPermissions(Organization::factory()->create());

    expect($user->getAllPermissions()->pluck('name'))
        ->not->toContain('view-attendance')
        ->not->toContain('view-leave');

    $this->actingAs($user)->get(route('company.people.attendance.index'))->assertSuccessful();
    $this->actingAs($user)->get(route('company.people.leave.index'))->assertSuccessful();
});

it('still gates the HR surfaces that are not personal', function () {
    /*
     * Ungating Attendance and Leave must not read as ungating People. Payroll
     * and Incentives are somebody else's money and stay behind their own
     * permissions, in the menu and on the route.
     */
    $user = employeeWithoutHrPermissions(Organization::factory()->create());

    $this->actingAs($user)->get(route('company.people.payroll.index'))->assertForbidden();
    $this->actingAs($user)->get(route('company.people.incentives.index'))->assertForbidden();

    $source = File::get(base_path('resources/js/ovs/layouts/sidebar-configs.ts'));

    foreach (['people-payroll' => 'view-payroll', 'people-incentives' => 'view-incentives'] as $id => $permission) {
        preg_match("/\{ id: '{$id}'.*?\},/s", $source, $declaration);

        expect($declaration[0] ?? '')->toContain("permission: '{$permission}'");
    }
});
