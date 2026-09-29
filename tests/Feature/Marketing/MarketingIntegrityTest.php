<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/**
 * The checks that keep Marketing locked.
 *
 * Each one exists because the thing it forbids actually happened here, silently,
 * and was found by accident rather than by anything failing.
 */
it('renders no page component that does not exist on disk', function () {
    /*
     * Both execution pages were rendered by a controller and had never existed
     * as files, so the routes threw for anyone who reached them. Nothing caught
     * it because a missing Inertia component is a runtime error, not a build
     * one — the server happily names a page that is not there.
     */
    $missing = [];

    foreach (File::allFiles(app_path('Http/Controllers/Company/Marketing')) as $file) {
        $contents = File::get($file->getPathname());

        preg_match_all("/Inertia::render\('([^']+)'/", $contents, $matches);

        foreach ($matches[1] as $component) {
            $path = resource_path('js/pages/'.$component.'.tsx');

            if (! File::exists($path)) {
                $missing[] = $component.' (rendered by '.$file->getFilename().')';
            }
        }
    }

    expect($missing)->toBe([]);
});

it('reads server relations by the name the server actually sends', function () {
    /*
     * Eloquent serialises a relation in snake_case. The Conversations inbox read
     * `whatsappAccount`, `crmLead` and `assignedUser` — and declared those same
     * camelCase names in its own local interface, so it typechecked perfectly
     * and every one of them was undefined. The sender number, the assignee's
     * name and the "already a lead" marker never rendered.
     *
     * A local type that agrees with the component instead of the server proves
     * nothing, so this checks the values rather than the types.
     */
    $offenders = [];

    $camelRelations = ['whatsappAccount', 'crmLead', 'assignedUser', 'marketingContact', 'whatsappTemplate'];

    foreach (File::allFiles(resource_path('js/pages/Company/Marketing')) as $file) {
        if ($file->getExtension() !== 'tsx') {
            continue;
        }

        $contents = File::get($file->getPathname());

        foreach ($camelRelations as $relation) {
            // `.relationName` — a property read, not a string or a route name.
            if (preg_match('/\.'.$relation.'\b/', $contents)) {
                $offenders[] = $file->getRelativePathname().' reads .'.$relation;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('points every Marketing sidebar entry at a route that exists', function () {
    /*
     * The Execution Logs entry pointed at `/company/marketing/automations/executions`,
     * which matched no route — so it fell through to `automations/{id}` with the
     * id "executions" and returned a **500**, not a 404. A dead nav link that
     * throws is worse than one that 404s, because it looks like the feature is
     * broken rather than missing.
     */
    $config = File::get(resource_path('js/ovs/layouts/sidebar-configs.ts'));

    preg_match_all("#href: '(/company/marketing[^']*)'#", $config, $matches);

    expect($matches[1])->not->toBeEmpty();

    $unroutable = [];

    foreach (array_unique($matches[1]) as $href) {
        $request = Request::create($href, 'GET');

        try {
            Route::getRoutes()->match($request);
        } catch (Throwable) {
            $unroutable[] = $href;
        }
    }

    expect($unroutable)->toBe([]);
});

it('calls the WhatsApp line by one name everywhere', function () {
    /*
     * The sidebar said "Social Posts" where the page, its shell title and the
     * module rail all said "Instagram". A nav label that disagrees with the page
     * it opens reads as having landed on the wrong screen.
     */
    $config = File::get(resource_path('js/ovs/layouts/sidebar-configs.ts'));
    $shell = File::get(resource_path('js/pages/Company/Marketing/components/MarketingShell.tsx'));

    preg_match("#label: '([^']+)',\s+href: '/company/marketing/instagram'#", $config, $registry);

    expect($registry[1] ?? null)->toBe('Instagram');

    /*
     * The rail can no longer disagree with the dropdown, because there is no
     * second list to disagree with: MarketingShell projects COMPANY_MODULES
     * rather than declaring its own destinations. That is what this asserts now
     * — the shell hardcodes no Marketing href at all.
     */
    preg_match_all("#'(/company/marketing[^']*)'#", $shell, $hardcoded);

    expect($shell)->toContain('moduleRail')
        ->and($hardcoded[1])->toBe([]);
});
