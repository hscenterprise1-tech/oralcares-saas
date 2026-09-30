<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Organization;
use App\Models\User;
use App\Support\CrmPermissions;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * The menu and the gate have to give the same answer.
 *
 * Backend authorization resolves legacy permission names through
 * `CrmPermissions::ALIASES` — a role holding `crm.view` passes a gate asking for
 * `view-crm`. The sidebar and the command palette test the shared permission
 * list with a plain `includes()`, which knew nothing about that map, so a role
 * granted only legacy names was shown an empty CRM menu while being allowed
 * through every route behind it.
 */
function resolvedPermissionsFor(User $user): array
{
    $method = new ReflectionMethod(HandleInertiaRequests::class, 'resolvedPermissions');
    $method->setAccessible(true);

    return $method->invoke(app(HandleInertiaRequests::class), $user->fresh())->all();
}

beforeEach(function () {
    setPermissionsTeamId(0);
    $this->organization = Organization::factory()->create();
});

it('shows a legacy-alias role the CRM destinations its routes already allow', function () {
    foreach (['crm.view', 'leads.view'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $role = Role::firstOrCreate(['name' => 'Legacy Ops', 'guard_name' => 'web']);
    $role->givePermissionTo(['crm.view', 'leads.view']);

    $user = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->organization->id,
    ]);
    $user->assignRole($role);

    $resolved = resolvedPermissionsFor($user);

    /*
     * The raw grants are legacy-only, so a plain includes() found nothing the
     * navigation asks for.
     */
    expect($user->fresh()->getAllPermissions()->pluck('name')->all())
        ->not->toContain('view-crm');

    expect($resolved)->toContain('view-crm')
        ->and($resolved)->toContain('view-leads')
        /* And the grants themselves are still there. */
        ->and($resolved)->toContain('crm.view');
});

it('never resolves a permission the gate would refuse', function () {
    Permission::findOrCreate('crm.view', 'web');

    $role = Role::firstOrCreate(['name' => 'Read Only CRM', 'guard_name' => 'web']);
    $role->givePermissionTo('crm.view');

    $user = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->organization->id,
    ]);
    $user->assignRole($role);

    /*
     * Resolution widens nothing: a name appears only when CrmPermissions::check
     * — the same call the gates make — says yes.
     */
    foreach (resolvedPermissionsFor($user) as $permission) {
        if (! array_key_exists($permission, CrmPermissions::ALIASES)) {
            continue;
        }

        expect(CrmPermissions::check($user->fresh(), $permission))
            ->toBeTrue("resolved {$permission} but the gate would refuse it");
    }
});

/* ───────────────────────────── sidebar vs route authorization ──────────── */

it('gates the Packages menu entry on what its route actually requires', function () {
    /*
     * The entry said `view-crm`, the route asks for `view-plans` through
     * `crm.packages.view`. Sales Representative holds the first and not the
     * second, so it saw Packages in the menu and a 403 on the page.
     */
    expect(navItem('admin-packages'))
        ->toMatchArray(['href' => '/company/admin/packages', 'permission' => 'view-plans']);
});

it('agrees with the route on who may open Packages', function () {
    $rep = grantCrmPermissions(
        User::factory()->companyUser()->create(['organization_id' => $this->organization->id]),
        ['view-crm', 'view-leads'],
    );

    /* Not granted view-plans, so the menu must hide it and the route must refuse. */
    expect(resolvedPermissionsFor($rep))->not->toContain('view-plans');

    $this->actingAs($rep)
        ->get(route('company.admin.packages.index'))
        ->assertForbidden();
});

/* ─────────────────────────────── create actions ────────────────────────── */

it('offers no create action that lands on a list', function () {
    /*
     * "Create X" that opens a list of X is not a create action. Only a lead and
     * a clinic have a create page you can put in a menu; demos, trials and
     * quotations are raised against a deal, and a task's form is a modal on the
     * tasks index with no address of its own.
     */
    $sources = [
        'resources/js/pages/Company/design/CrmShell.tsx',
        'resources/js/components/enterprise-shell/CommandPalette.tsx',
        'resources/js/components/enterprise-shell/QuickCreate.tsx',
        'resources/js/components/executive-shell/ExecutiveHeader.tsx',
    ];

    $offenders = [];

    foreach ($sources as $path) {
        $source = File::get(base_path($path));

        preg_match_all("/label: '((?:Create |New )?[A-Za-z ]+)',[^\\n]*href: '([^']+)'/", $source, $matches, PREG_SET_ORDER);

        foreach ($matches as [$_, $label, $href]) {
            if (! preg_match('/^(Create |New )?(Lead|Task|Demo|Trial|Quotation|Clinic|Employee|Campaign)$/', $label)) {
                continue;
            }

            if (! str_ends_with($href, '/create')) {
                $offenders[] = basename($path).": {$label} → {$href}";
            }
        }
    }

    expect($offenders)->toBe([]);
});

/* ───────────────────────────────── organizations ───────────────────────── */

it('sends the Accounts palette entry to Customer Success, not the CRM redirect', function () {
    $source = File::get(base_path('resources/js/components/enterprise-shell/CommandPalette.tsx'));

    expect($source)->not->toContain('/company/crm/organizations')
        ->and($source)->toContain('/company/customer-success/accounts');
});

/* ─────────────────────── one canonical company navigation ──────────────── */

/**
 * COMPANY_MODULES — the five pillars — parsed out of the registry.
 *
 * Read from the source rather than mirrored here on purpose: a copy of the
 * expected navigation kept inside the test is a second place to update, which
 * is the exact failure the registry exists to remove.
 *
 * Sliced on the pillar's own `id:` line rather than matched as one balanced
 * block. The previous regex closed each module on `\n  },` followed by the next
 * `{`, which the comment banners now standing between pillars break — and a
 * parser that silently returns fewer modules than exist is worse than one that
 * fails, because every assertion below would still pass over the subset it
 * happened to find.
 *
 * @return array<string, list<array{id: string, label: string, href: string, permission: string|null, railable: bool, group: string|null}>>
 */
function companyModules(): array
{
    $source = File::get(base_path('resources/js/ovs/layouts/sidebar-configs.ts'));

    preg_match('/export const COMPANY_MODULES[^\n]*\n(.*?)\n\];/s', $source, $registry);
    $body = $registry[1] ?? '';

    /* A pillar declares its id at four spaces; an item declares its own inline. */
    preg_match_all("/^ {4}id: '([a-z-]+)',$/m", $body, $pillars, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

    $parsed = [];

    foreach ($pillars as $index => $pillar) {
        $start = $pillar[0][1];
        $end = $pillars[$index + 1][0][1] ?? strlen($body);
        $slice = substr($body, $start, $end - $start);

        /*
         * The `items:` array only. A pillar's `create:` entries once had the
         * same shape, so parsing the whole body counted "New Lead" as a sixth
         * CRM destination — a create action is not a place you navigate to.
         * Create actions now live in COMPANY_WORKSPACES, but the narrowing
         * stays: it costs nothing and it is the assertion that caught it.
         */
        preg_match('/\n {4}items: \[\n(.*)/s', $slice, $itemsBlock);

        preg_match_all(
            "/\{ id: '([^']+)',\s*label: '([^']+)',\s*href: '([^']+)'(.*?)\},/",
            $itemsBlock[1] ?? '',
            $items,
            PREG_SET_ORDER
        );

        $parsed[$pillar[1][0]] = array_map(fn ($item) => [
            'id' => $item[1],
            'label' => $item[2],
            'href' => $item[3],
            'permission' => preg_match("/permission: '([^']+)'/", $item[4], $p) ? $p[1] : null,
            'railable' => ! str_contains($item[4], 'rail: false') && str_contains($item[4], 'hue:'),
            'group' => preg_match("/group: '([^']+)'/", $item[4], $g) ? $g[1] : null,
        ], $items);
    }

    return $parsed;
}

/**
 * COMPANY_WORKSPACES — the floating rails — parsed out of the registry.
 *
 * A workspace names item ids; the destinations themselves are declared once in
 * the pillars above. That split is what this suite now has to check on both
 * sides: that a rail names nothing undeclared, and that what it names is
 * actually railable.
 *
 * @return array<string, list<string>>
 */
function companyWorkspaces(): array
{
    $source = File::get(base_path('resources/js/ovs/layouts/sidebar-configs.ts'));

    preg_match('/export const COMPANY_WORKSPACES[^\n]*\n(.*?)\n\];/s', $source, $registry);
    $body = $registry[1] ?? '';

    preg_match_all("/^ {4}id: '([a-z-]+)',$/m", $body, $workspaces, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

    $parsed = [];

    foreach ($workspaces as $index => $workspace) {
        $start = $workspace[0][1];
        $end = $workspaces[$index + 1][0][1] ?? strlen($body);
        $slice = substr($body, $start, $end - $start);

        preg_match('/\n {4}rail: \[(.*?)\],/s', $slice, $railBlock);
        preg_match_all("/'([^']+)'/", $railBlock[1] ?? '', $ids);

        $parsed[$workspace[1][0]] = $ids[1];
    }

    return $parsed;
}

/** One declared nav item, by id. */
function navItem(string $id): array
{
    foreach (companyModules() as $items) {
        foreach ($items as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }
    }

    throw new RuntimeException("No navigation item declared with id \"{$id}\".");
}

it('declares exactly the five agreed pillars, in order', function () {
    /*
     * Seven modules became five pillars. The consolidation that matters is
     * Revenue: Subscriptions sat under Customer Success, Invoices and Payments
     * under Finance, and Packages under Administration — three parts of one
     * story in three menus.
     */
    expect(array_keys(companyModules()))
        ->toBe(['operations', 'revenue', 'acquisition', 'people', 'governance']);
});

it('declares exactly the five CRM destinations', function () {
    /*
     * Overview, Deals, Trials, Commercials, My Work — and nothing else.
     *
     * Pipeline is the stage rail inside Deals, leads are deals, activities are
     * the deal's Conversation and History, demos and tasks are worked from My
     * Work and the deal, loss triage is the Deals "Lost" view, and Sales teams
     * is configuration under Platform Governance. Each of those was once a menu
     * entry; this is the guard against them coming back one at a time.
     *
     * Read from the `crm` workspace rather than from a pillar: the CRM shares
     * the Acquisition pillar with Marketing now, and it is the workspace — what
     * CrmShell rails and what CRM_DESTINATIONS exports — that must stay five.
     */
    expect(companyWorkspaces()['crm'])
        ->toBe(['crm-overview', 'deals', 'trials', 'commercials', 'my-work']);
});

it('keeps Marketing a labelled group inside Acquisition rather than a flat wall', function () {
    /*
     * Fifteen destinations in one dropdown is a wall. The `group` field is what
     * tells a rep which half they are reading, and the CRM's five must stay
     * ungrouped and first so the pillar still opens on the pipeline.
     */
    $acquisition = companyModules()['acquisition'];

    $ungrouped = array_values(array_filter($acquisition, fn ($item) => $item['group'] === null));
    $marketing = array_values(array_filter($acquisition, fn ($item) => $item['group'] === 'Marketing'));

    expect(array_column($ungrouped, 'label'))
        ->toBe(['CRM Overview', 'Deals', 'Trials', 'Commercials', 'My Work'])
        ->and(count($marketing))->toBeGreaterThanOrEqual(9);

    /* Ungrouped first: a group heading may never appear above an ungrouped item. */
    $firstGrouped = array_search('Marketing', array_column($acquisition, 'group'), true);

    expect(array_slice(array_column($acquisition, 'group'), 0, $firstGrouped))
        ->each->toBeNull();
});

it('puts every destination in exactly one module', function () {
    /*
     * One concept, one door. Subscriptions, Usage and Lifecycle were a module
     * of their own over the accounts Customer Success already listed; Payroll
     * had an entry in Finance over a legacy table and another in People over
     * the live one; Renewals appeared in the Customer Success menu and again
     * in the Account Lifecycle rail.
     */
    $hrefs = [];

    foreach (companyModules() as $module => $items) {
        foreach ($items as $item) {
            $hrefs[$item['href']][] = "{$module}/{$item['id']}";
        }
    }

    $duplicated = array_filter($hrefs, fn ($places) => count($places) > 1);

    expect($duplicated)->toBe([]);
});

it('keeps payroll and personal utilities out of the wrong modules', function () {
    $hrefs = collect(companyModules())->flatMap(fn ($items) => array_column($items, 'href'))->all();

    /* The live payroll is People's; Finance's register read the legacy table. */
    expect($hrefs)->toContain('/company/people/payroll')
        ->and($hrefs)->not->toContain('/company/finance/payroll')
        /* Profile and the notification inbox belong to the reader, not a module. */
        ->and($hrefs)->not->toContain('/profile')
        ->and($hrefs)->not->toContain('/notifications');
});

it('keeps the two kinds of notification apart', function () {
    /*
     * `/company/notifications-ops` is what the platform sent — delivery health,
     * an operational surface. `/notifications` is mail addressed to the reader.
     * They were once two menu entries a group apart under the same word, which
     * told nobody which was which; they are now in different places entirely
     * and must not be collapsed into one.
     */
    $hrefs = collect(companyModules())->flatMap(fn ($items) => array_column($items, 'href'))->all();

    expect($hrefs)->toContain('/company/notifications-ops')
        ->and(navItem('ops-notifications')['permission'])->toBe('view-notifications');

    /* The personal inbox is on the avatar, and is never permission-gated. */
    $shell = File::get(base_path('resources/js/pages/Company/design/CrmShell.tsx'));

    expect($shell)->toMatch("#ACCOUNT_LINKS.*'/notifications'#s")
        ->and($shell)->toMatch("#ACCOUNT_LINKS.*'/profile'#s");
});

it('never shows a navigation entry to a user the route would refuse', function () {
    /*
     * The guard used to return true for every permission whenever the user's
     * permission list was empty — a convenience for a database where nobody had
     * a role yet. It inverted the rule it was bolted onto: a user with no grants
     * was offered the entire product and refused by each route in turn.
     *
     * Read from the source, because the rule lives in TypeScript and this suite
     * is the only thing that watches it.
     */
    $hook = File::get(base_path('resources/js/hooks/useNavAuthorization.ts'));

    expect($hook)->not->toMatch('#permissions\.length\s*===?\s*0#')
        /* The two rules that remain: an ungated entry, and the server's own bypass. */
        ->and($hook)->toContain('if (!permission)')
        ->and($hook)->toContain('if (isSuperAdmin)');
});

it('gates the Dashboard entry on the permission its route enforces', function () {
    /*
     * It carried none, so once the empty-list fallback went it would have been
     * the only entry a role-less user saw — and a 403 the moment they used it.
     *
     * Dashboard is no longer a standalone pill beside the pillar menu: it was
     * the one destination with no stated home, and it now opens Operations &
     * Monitoring as that pillar's first entry. So the gate is asserted where it
     * now lives — on the item itself — rather than on the pill that used to
     * render it.
     */
    $nav = File::get(base_path('resources/js/pages/Company/design/CrmModuleNav.tsx'));

    expect(navItem('ops-dashboard'))
        ->toMatchArray([
            'href' => '/company/dashboard',
            'permission' => 'view executive dashboard',
        ])
        /* And it is Operations' first entry, not buried in it. */
        ->and(companyModules()['operations'][0]['id'])->toBe('ops-dashboard')
        /* One Dashboard: the standalone pill is gone from the header. */
        ->and($nav)->not->toContain('EXECUTIVE_TOP_LEVEL_DASHBOARD');

    /* And the route really does ask for it. */
    $middleware = collect(Route::getRoutes())
        ->first(fn ($route) => $route->uri() === 'company/dashboard')
        ->gatherMiddleware();

    expect($middleware)->toContain('can:view executive dashboard');
});

it('rails only destinations the pillars actually declare', function () {
    /*
     * The rail answers "what do I reach for constantly here", the dropdown
     * "what exists". A rail may be shorter; it may never carry something no
     * pillar declares — the Account Lifecycle rail used to list Renewals, and
     * Integrations' listed "Marketing".
     *
     * Since the reorganisation a rail names ids rather than restating
     * destinations, so this is the assertion that keeps the two halves joined:
     * every id a workspace rails must exist, and must be railable.
     */
    $declared = [];

    foreach (companyModules() as $items) {
        foreach ($items as $item) {
            $declared[$item['id']] = $item;
        }
    }

    foreach (companyWorkspaces() as $workspace => $railIds) {
        expect($railIds)->not->toBeEmpty("{$workspace} has no rail destinations");

        foreach ($railIds as $id) {
            /*
             * `toHaveKey()`'s second argument is an expected value, not a
             * failure message — passing one there asserts the item equals the
             * sentence, which is how this first ran red for the wrong reason.
             */
            expect(array_key_exists($id, $declared))
                ->toBeTrue("{$workspace} rails \"{$id}\", which no pillar declares");
            expect($declared[$id]['railable'])
                ->toBeTrue("{$workspace} rails \"{$id}\", which is marked `rail: false` or carries no hue");
        }

        /* A rail of more than eight stops being scannable at a glance. */
        expect(count($railIds))->toBeLessThanOrEqual(8, "{$workspace}'s rail is too long to scan");
    }
});

it('drives the rail and the palette from the canonical list rather than copies', function () {
    $shell = File::get(base_path('resources/js/pages/Company/design/CrmShell.tsx'));
    $palette = File::get(base_path('resources/js/pages/Company/design/CrmCommandPalette.tsx'));

    /* Both import it, and neither hand-writes a CRM href of its own. */
    foreach (['CrmShell' => $shell, 'CrmCommandPalette' => $palette] as $name => $source) {
        expect($source)->toContain('CRM_DESTINATIONS');

        preg_match_all("#'(/company/crm[^']*)'#", $source, $hardcoded);

        expect($hardcoded[1])
            ->toBe([], "{$name} hardcodes a CRM destination instead of reading CRM_DESTINATIONS");
    }
});

it('offers no CRM destination that only redirects', function () {
    /*
     * A redirect in the menu presents one destination under two names. These
     * five URLs are all real pages; the retired ones (pipeline, leads, tasks,
     * demos, operations) must not appear.
     */
    $retired = [
        '/company/crm/pipeline', '/company/crm/leads', '/company/crm/tasks',
        '/company/crm/demos', '/company/crm/operations',
        '/company/crm/organizations', '/company/crm/subscriptions',
        '/company/crm/renewals', '/company/crm/onboarding',
    ];

    foreach (companyModules() as $items) {
        foreach ($items as $item) {
            expect($retired)->not->toContain($item['href']);
        }
    }
});
