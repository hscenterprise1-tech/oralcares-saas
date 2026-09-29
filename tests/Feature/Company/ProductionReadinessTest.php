<?php

use App\Models\Clinic;
use App\Models\User;
use App\Shared\Search\Models\SearchDocument;
use App\Shared\Search\Services\SearchEngineService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(0);
});

/**
 * The final Company/HQ gate.
 *
 * Every assertion here is a defect this audit found: a search index that scoped
 * nothing, search results that led to 404s, a password-recovery link that could
 * not recover a password, and public forms advertising authentication the
 * product does not have.
 */
function readinessUser(UserType $type, ?int $organizationId = null): User
{
    return User::factory()->create([
        'user_type' => $type,
        'organization_id' => $organizationId,
    ]);
}

function searchDocument(array $attributes = []): SearchDocument
{
    return SearchDocument::create(array_merge([
        'module' => 'crm',
        'model_type' => 'App\Models\Lead',
        'model_id' => fake()->unique()->numberBetween(1, 100000),
        'clinic_id' => null,
        'title' => 'Head office record',
        'subtitle' => 'HQ',
        'url' => '/company/crm/deals',
        'searchable_text' => 'head office record',
    ], $attributes));
}

/* ═════════════ isolation: the search index scoped nothing ══════════════════ */

it('keeps a clinic tenant out of head office records in global search', function () {
    /*
     * The repository filtered on `$user->clinic_id`, and `users` has no such
     * column — so the condition was never true and nothing was ever scoped.
     * `/api/search` carries only `auth`, not the portal middleware, so any
     * signed-in clinic user could read HQ's leads, accounts, commercial
     * documents and employee records through the command palette.
     */
    $clinic = Clinic::factory()->create();
    $tenant = readinessUser(UserType::ClinicUser, $clinic->organization_id);

    searchDocument(['title' => 'Head office lead', 'searchable_text' => 'head office lead']);
    searchDocument(['title' => 'Their own record', 'searchable_text' => 'their own record', 'clinic_id' => $clinic->id]);

    /* Creating a clinic indexes it, so read back only what this test wrote. */
    $titles = collect(app(SearchEngineService::class)->search($tenant, 'record')->items())
        ->pluck('title')
        ->sort()
        ->values();

    expect($titles->all())->toBe(['Their own record']);
});

it('shows head office the whole index', function () {
    /* HQ oversees every tenant, which is what every other Company surface does. */
    $clinic = Clinic::factory()->create();

    searchDocument(['title' => 'Head office lead', 'searchable_text' => 'head office record']);
    searchDocument(['title' => 'A tenant record', 'searchable_text' => 'a tenant record', 'clinic_id' => $clinic->id]);

    foreach ([UserType::MasterAdmin, UserType::CompanyUser] as $type) {
        $titles = collect(app(SearchEngineService::class)->search(readinessUser($type), 'record')->items())
            ->pluck('title')
            ->sort()
            ->values();

        expect($titles->all())->toBe(['A tenant record', 'Head office lead']);
    }
});

it('gives a tenant with no organisation nothing rather than the orphan rows', function () {
    /*
     * Matching on a null organisation would hand this user every clinic row
     * that also has none — an unrelated set that happens to share a missing
     * value.
     */
    Clinic::factory()->create(['organization_id' => null]);
    searchDocument(['clinic_id' => Clinic::query()->value('id')]);

    $results = app(SearchEngineService::class)->search(readinessUser(UserType::ClinicUser), 'record');

    expect($results->total())->toBe(0);
});

/* ══════════════ search results that led nowhere ════════════════════════════ */

it('sends every indexed result to a route that exists', function () {
    /*
     * `/company/finance/invoices/{id}` and `/company/crm/tasks/{id}` have no
     * routes — Finance and CRM expose an index and no single-record page — so
     * every billing and task result was a 404. Both indexes filter on a search
     * term, so a result opens its list narrowed to the record.
     */
    $source = file_get_contents(app_path('Shared/Search/Services/SearchIndexerService.php'));

    preg_match_all("#'url' => '(/company/[^']*)'#", $source, $matches);

    $paths = collect($matches[1])
        /*
         * Each literal is a prefix the indexer concatenates onto: a trailing
         * slash means an id follows, a trailing `?search=` a search term.
         */
        ->map(fn (string $url) => rtrim(explode('?', $url)[0], '/'))
        ->map(fn (string $url) => ltrim($url, '/'))
        ->unique();

    expect($paths)->not->toBeEmpty();

    foreach ($paths as $path) {
        $matched = collect(Route::getRoutes())->first(function ($route) use ($path) {
            if (! in_array('GET', $route->methods(), true)) {
                return false;
            }

            return (bool) preg_match('#^'.preg_replace('#\{[^}]+\}#', '[^/]+', $route->uri()).'$#', $path);
        });

        expect($matched)->not->toBeNull("indexed search result points at /{$path}, which has no GET route");
    }
});

/* ══════════════ password recovery could not recover a password ═════════════ */

it('serves the company password recovery form on the route that accepts it', function () {
    /*
     * The Company login page linked to `/company/forgot-password`, a GET-only
     * route whose form posted to a POST route nobody had registered — so a
     * locked-out user's every attempt was a 405.
     */
    $this->get('/forgot-password')->assertOk();

    $this->post('/forgot-password', ['email' => 'nobody@example.com'])
        ->assertRedirect();
});

it('links the company login page at the recovery route that works', function () {
    $source = file_get_contents(resource_path('js/pages/Auth/CompanyLogin.tsx'));

    expect($source)->toContain('href="/forgot-password"')
        ->and($source)->not->toContain('company/forgot-password');
});

it('posts the recovery and reset forms to routes that accept them', function () {
    foreach (['ForgotPassword' => '/forgot-password', 'ResetPassword' => '/reset-password'] as $page => $target) {
        $source = file_get_contents(resource_path("js/pages/Auth/{$page}.tsx"));

        expect($source)->toContain("post('{$target}')")
            ->and($source)->not->toContain("post('/company/");
    }
});

it('leaves no company auth page whose form has nowhere to post', function () {
    /*
     * Ten routes rendered forms posting to POST routes nobody had registered.
     * Four advertised authentication this product does not have — magic links,
     * one-time codes, PINs, two-factor — and two an invitation flow that does
     * not exist.
     */
    foreach ([
        'company/magic-link',
        'company/otp-verify',
        'company/pin-verify',
        'company/two-factor-challenge',
        'company/invite-user',
        'company/accept-invitation',
        'company/lock-screen',
        'company/forgot-password',
        'company/reset-password',
        'company/verify-email',
    ] as $uri) {
        $exists = collect(Route::getRoutes())->contains(fn ($route) => $route->uri() === $uri);

        expect($exists)->toBeFalse("{$uri} is registered again and its form still posts nowhere");
    }
});

it('stops calling the removed auth routes deliberately public', function () {
    /* Otherwise they could come back unnoticed and be counted as intentional. */
    $source = file_get_contents(app_path('Services/Security/SecurityReviewService.php'));

    preg_match("/PUBLIC_PATTERN = '([^']+)'/", $source, $matches);

    expect($matches[1] ?? '')->not->toContain('magic-link')
        ->and($matches[1] ?? '')->not->toContain('two-factor-challenge')
        ->and($matches[1] ?? '')->not->toContain('accept-invitation');
});

/* ══════════════════════ navigation matches the router ══════════════════════ */

it('points every sidebar entry at a registered GET route', function () {
    $source = file_get_contents(resource_path('js/ovs/layouts/sidebar-configs.ts'));

    preg_match_all("/href: '(\/[^']*)'/", $source, $matches);

    $hrefs = collect($matches[1])->unique()->reject(fn ($href) => $href === '/');

    expect($hrefs)->not->toBeEmpty();

    foreach ($hrefs as $href) {
        $path = ltrim(parse_url($href, PHP_URL_PATH), '/');

        $matched = collect(Route::getRoutes())->first(function ($route) use ($path) {
            if (! in_array('GET', $route->methods(), true)) {
                return false;
            }

            return (bool) preg_match('#^'.preg_replace('#\{[^}]+\}#', '[^/]+', $route->uri()).'$#', $path);
        });

        expect($matched)->not->toBeNull("the sidebar links to /{$path}, which has no GET route");
    }
});

it('never names the same destination twice under one label', function () {
    /*
     * Two entries were both called "Notifications" and gated on the same
     * permission: one is what the company has been sending, the other is the
     * reader's own inbox.
     */
    $source = file_get_contents(resource_path('js/ovs/layouts/sidebar-configs.ts'));

    /*
     * Two entries may share a word when their sections separate them —
     * "Overview" under CRM and under Marketing ask for different permissions
     * and read as different places. The ambiguity worth failing on is the same
     * label on the same permission: nothing then tells the two apart.
     */
    preg_match_all("/label: '([^']+)',\s+href: '([^']+)',[^\n]*permission: '([^']+)'/", $source, $matches, PREG_SET_ORDER);

    $duplicated = collect($matches)
        ->groupBy(fn ($match) => $match[1].'|'.$match[3])
        ->filter(fn ($group) => collect($group)->pluck(2)->unique()->count() > 1)
        ->keys();

    expect($duplicated->all())->toBe([]);
});

it('asks the menu for the permission the route asks for', function () {
    /*
     * Employees was listed under `manage-employees` while its route asks for
     * `view-employees`, so a user entitled to read the directory was not shown
     * the way to it.
     */
    foreach (['view-employees', 'view-notifications'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $user = readinessUser(UserType::CompanyUser);
    $user->givePermissionTo('view-employees');

    $this->actingAs($user->fresh())->get('/company/people/employees')->assertOk();

    $source = file_get_contents(resource_path('js/ovs/layouts/sidebar-configs.ts'));

    expect($source)->toMatch("#href: '/company/people/employees',\s+icon: \w+,\s+permission: 'view-employees'#");
});

/* ═══════════════════ redirects that should no longer be linked ═════════════ */

it('links no page at a route that only redirects elsewhere', function () {
    /*
     * Accounts linked to `/company/crm/renewals` and Renewals to
     * `/company/crm/organizations`; both are compatibility redirects to the
     * Customer Success pages the buttons meant.
     */
    foreach ([
        'js/pages/Company/CustomerSuccess/Accounts/Index.tsx',
        'js/pages/Company/CustomerSuccess/Renewals/Index.tsx',
    ] as $page) {
        $source = file_get_contents(resource_path($page));

        expect($source)->not->toContain('href="/company/crm/renewals"')
            ->and($source)->not->toContain('href="/company/crm/organizations"');
    }
});

/* ══════════════════ signed-in users sent to the wrong portal ═══════════════ */

it('sends a signed-in company user back to their own portal', function () {
    /*
     * The `guest` middleware redirects an authenticated visitor to the
     * `dashboard` route, which in this application is the clinic one — so a
     * signed-in Company user who opened any auth page was sent into the clinic
     * portal and refused by its middleware with "Clinic portal access required".
     */
    foreach ([UserType::MasterAdmin, UserType::CompanyUser] as $type) {
        $this->actingAs(readinessUser($type))
            ->get('/forgot-password')
            ->assertRedirect(route('company.dashboard'));
    }
});

it('still serves the recovery form to the locked-out visitor it exists for', function () {
    $this->get('/forgot-password')->assertOk();
});

/* ══════════════════ pages whose scripts never loaded ═══════════════════════ */

it('renders the pushed script stack the notification pages depend on', function () {
    /*
     * `notifications/index` and `notifications/preferences` both push their
     * Alpine component with `@push('scripts')`, and no layout rendered
     * `@stack('scripts')` — so `notificationCenter()` was never defined, and the
     * page the bell on every Company screen leads to rendered as an empty box
     * with no list, no empty state and no working control.
     */
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($layout)->toContain("@stack('scripts')");

    foreach (['index', 'preferences'] as $page) {
        $view = file_get_contents(resource_path("views/notifications/{$page}.blade.php"));

        expect($view)->toContain("@push('scripts')");
    }
});

it('points the shared logo at a file that exists', function () {
    /* The component asked for `oral-cares-logo.png`; the file is `oralcares-logo.png`. */
    $component = file_get_contents(resource_path('views/components/application-logo.blade.php'));

    preg_match_all("#asset\('([^']+)'\)#", $component, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach (array_unique($matches[1]) as $asset) {
        expect(file_exists(public_path($asset)))->toBeTrue("the logo points at {$asset}, which is not in public/");
    }
});
