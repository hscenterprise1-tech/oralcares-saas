<?php

/*
 * The company sidebar is hand-typed data: a list of hrefs in
 * resources/js/ovs/layouts/sidebar-configs.ts with nothing tying them to the
 * routes they name. "Social Posts" pointed at /company/marketing/posts — a
 * path that was never registered — and 404'd for as long as it sat in the
 * menu, because nothing checked.
 *
 * This walks every href in that file through the real router. Resolving to a
 * wildcard segment counts as broken too: /company/marketing/automations/executions
 * is swallowed by automations/{id} and fails inside the controller rather than
 * reaching a list of executions.
 */

use App\PermissionKey;
use App\Support\CrmPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;

/**
 * Sidebar destinations with no page behind them.
 *
 * Empty, and meant to stay that way. The five that used to be listed here —
 * marketing automation executions, API keys, webhooks, settings and the team
 * directory — were menu entries for things nobody had built, each gated on a
 * permission this application does not define, so none was ever visible. They
 * were removed rather than waived.
 *
 * @var list<string>
 */
const UNBUILT_SIDEBAR_DESTINATIONS = [];

function sidebarHrefs(): array
{
    $source = File::get(base_path('resources/js/ovs/layouts/sidebar-configs.ts'));

    preg_match_all('/href:\s*\'([^\']+)\'/', $source, $matches);

    return array_values(array_unique($matches[1]));
}

it('points every sidebar item at a route that exists', function () {
    $broken = [];

    foreach (sidebarHrefs() as $href) {
        if (! str_starts_with($href, '/') || in_array($href, UNBUILT_SIDEBAR_DESTINATIONS, true)) {
            continue;
        }

        try {
            $route = Route::getRoutes()->match(Request::create($href, 'GET'));
        } catch (Throwable) {
            $broken[] = $href.' → no route';

            continue;
        }

        if (str_contains($route->uri(), '{')) {
            $broken[] = $href.' → swallowed by '.$route->uri();
        }
    }

    expect($broken)->toBe([]);
});

it('sends Social Posts to the Instagram content queue', function () {
    $source = File::get(base_path('resources/js/ovs/layouts/sidebar-configs.ts'));

    expect($source)->toContain("id: 'mktg-posts'")
        ->and($source)->not->toContain('/company/marketing/posts')
        ->and(sidebarHrefs())->toContain('/company/marketing/instagram');
});

/*
 * The command palette is the same hand-typed data as the sidebar, with the same
 * failure mode: an href nothing checks. It carries more destinations than the
 * sidebar by design — the working surfaces that are reached from a deal more
 * often than they are navigated to — so it needs its own guard rather than
 * being folded into the one above.
 */
function paletteDestinations(): array
{
    $source = File::get(base_path('resources/js/pages/Company/design/CrmCommandPalette.tsx'));

    preg_match_all("/\{\s*id:\s*'([^']+)'.*?label:\s*'([^']+)'.*?href:\s*'([^']+)'.*?\}/s", $source, $matches, PREG_SET_ORDER);

    return array_map(fn (array $m) => ['id' => $m[1], 'label' => $m[2], 'href' => $m[3]], $matches);
}

it('points every command palette entry at a route that exists', function () {
    $broken = [];

    foreach (paletteDestinations() as $entry) {
        try {
            $route = Route::getRoutes()->match(Request::create($entry['href'], 'GET'));
        } catch (Throwable) {
            $broken[] = $entry['href'].' → no route';

            continue;
        }

        if (str_contains($route->uri(), '{')) {
            $broken[] = $entry['href'].' → swallowed by '.$route->uri();
        }
    }

    expect($broken)->toBe([]);
});

it('never offers a redirect as a command palette destination', function () {
    /*
     * "Leads" pointed at /company/crm/leads, which redirects to the Deals
     * workspace the palette already named. Two entries, one destination, and
     * the reader had no way to tell which was which.
     */
    $redirects = [];

    foreach (paletteDestinations() as $entry) {
        $route = Route::getRoutes()->match(Request::create($entry['href'], 'GET'));
        $action = $route->getActionName();

        if (str_contains($action, 'RedirectController')) {
            $redirects[] = $entry['label'].' → '.$entry['href'];
        }
    }

    expect($redirects)->toBe([]);
});

it('names a destination the same way in the sidebar and the palette', function () {
    /*
     * The sidebar said "Commercials" and the palette said "Quotations" for the
     * same module, which taught two names for one thing.
     */
    $sidebar = File::get(base_path('resources/js/ovs/layouts/sidebar-configs.ts'));
    preg_match_all("/label:\s*'([^']+)',\s*href:\s*'([^']+)'/", $sidebar, $m, PREG_SET_ORDER);

    $sidebarLabels = [];
    foreach ($m as $row) {
        $sidebarLabels[$row[2]] = $row[1];
    }

    $disagreements = [];
    foreach (paletteDestinations() as $entry) {
        $expected = $sidebarLabels[$entry['href']] ?? null;

        if ($expected !== null && $expected !== $entry['label']) {
            $disagreements[] = "{$entry['href']}: sidebar '{$expected}' vs palette '{$entry['label']}'";
        }
    }

    expect($disagreements)->toBe([]);
});

it('gates every command palette destination on a permission', function () {
    /*
     * Without this the palette offered every destination to everybody and let
     * the server refuse — a 403 the reader was invited into.
     */
    $source = File::get(base_path('resources/js/pages/Company/design/CrmCommandPalette.tsx'));

    preg_match_all("/\{\s*id:\s*'([^']+)'.*?\}/s", $source, $matches, PREG_SET_ORDER);

    $ungated = [];
    foreach (paletteDestinations() as $entry) {
        $block = null;
        foreach (explode("\n", $source) as $line) {
            if (str_contains($line, "id: '{$entry['id']}'")) {
                $block = $line;
                break;
            }
        }

        if ($block !== null && ! str_contains($block, 'permission:')) {
            $ungated[] = $entry['id'];
        }
    }

    expect($ungated)->toBe([]);
});

/*
 * The same failure one level down: hrefs hand-typed inside CRM pages.
 *
 * Loss triage pointed all three of its "open this deal" links at
 * /company/crm/deals/{id}, which is not a route — Deals is an index and a
 * single deal lives under /company/crm/leads/{lead}. Every way off that page
 * into the deal it was talking about 404'd, and nothing checked.
 *
 * Interpolations become `1`, which is right for an id segment. The few hrefs
 * whose interpolation is an action name rather than an id cannot be probed that
 * way and are listed here instead.
 *
 * @var list<string>
 */
const DYNAMIC_ACTION_HREFS = [
    '/company/crm/demos/${demo.id}/${action}',
];

it('points every hand-typed CRM page link at a route that exists', function () {
    $broken = [];
    $verbs = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('js/pages/Company/Crm'))
    );

    foreach ($files as $file) {
        if (! preg_match('/\.tsx?$/', $file->getFilename())) {
            continue;
        }

        $source = File::get($file->getPathname());
        preg_match_all('#["\'`](/company/[A-Za-z0-9/_\-$\{\}\.]*)["\'`]#', $source, $matches);

        foreach (array_unique($matches[1]) as $href) {
            if (in_array($href, DYNAMIC_ACTION_HREFS, true)) {
                continue;
            }

            $probe = preg_replace('/\$\{[^}]+\}/', '1', $href);

            if (str_contains($probe, '$')) {
                continue;
            }

            $resolved = false;

            foreach ($verbs as $verb) {
                try {
                    Route::getRoutes()->match(Request::create($probe, $verb));
                    $resolved = true;
                    break;
                } catch (Throwable) {
                    // Try the next verb.
                }
            }

            if (! $resolved) {
                $broken[] = basename($file->getPathname()).' → '.$href;
            }
        }
    }

    expect($broken)->toBe([]);
});

/*
 * A menu gated on a permission nobody can hold is a hidden menu.
 *
 * The ADMIN section asked for `view-people`, which this application does not
 * define — no permission row, no seeder entry, no role. Every user holding a
 * real role lost Employees, Sales teams, Packages, Notifications, Profile and
 * Visual Studio in one go. It went unnoticed because no user currently holds a
 * role at all, and the guard falls through to "show everything" when the
 * permission list is empty.
 */
it('gates every navigation entry on a permission the application defines', function () {
    $source = File::get(base_path('resources/js/ovs/layouts/sidebar-configs.ts'));

    preg_match_all("/permission: '([^']+)'/", $source, $matches);

    $known = collect(array_keys(CrmPermissions::ALIASES))
        ->merge(Permission::pluck('name'))
        /* The enum that names permission keys in code. */
        ->merge(collect(PermissionKey::cases())->pluck('value'))
        /*
         * Every seeder that grants a permission, not just one of them.
         *
         * `employees.view` and `roles.manage` are real and held by real roles,
         * but they are seeded by CompanyRBACSeeder — so scanning only
         * CompanyPortalRolesSeeder reported them as undefined. The permission
         * table itself is empty here under RefreshDatabase, which is why this
         * reads the seeders rather than the database.
         */
        ->merge(collect(File::files(base_path('database/seeders')))
            ->flatMap(function ($file) {
                preg_match_all("/'([a-z][a-z0-9 .\-_]+)'/", File::get($file->getPathname()), $m);

                return $m[1];
            }))
        ->unique();

    $undefined = collect($matches[1])->unique()
        ->reject(fn (string $permission) => $known->contains($permission))
        ->values()
        ->all();

    expect($undefined)->toBe([]);
});
