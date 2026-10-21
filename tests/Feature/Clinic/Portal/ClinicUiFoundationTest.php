<?php

use Illuminate\Support\Facades\File;

/**
 * The Clinic Portal UI foundation's rules, checked at the source: one
 * canonical shell for every signed-in Clinic page, and no old design system
 * or retired shell imported anywhere in Clinic code.
 */

/** @return list<string> */
function clinicFrontendFiles(): array
{
    return collect([resource_path('js/clinic'), resource_path('js/pages/ClinicPortal'), resource_path('js/pages/Clinic')])
        ->flatMap(fn (string $dir) => File::allFiles($dir))
        ->filter(fn ($file) => in_array($file->getExtension(), ['ts', 'tsx'], true))
        ->map(fn ($file) => $file->getPathname())
        ->values()
        ->all();
}

/** @return list<string> page components the server renders for Clinic users */
function renderedClinicPages(): array
{
    return collect(File::allFiles(app_path()))
        ->filter(fn ($file) => $file->getExtension() === 'php')
        ->flatMap(function ($file) {
            preg_match_all("/(?:Inertia::render|->render)\(\s*'((?:Clinic|ClinicPortal)\/[^']+)'/", File::get($file->getPathname()), $matches);

            return $matches[1];
        })
        ->unique()->sort()->values()->all();
}

it('imports no OVS/OEDL, no Company crmTheme and no retired Clinic shell from Clinic frontend code', function () {
    $offenders = collect(clinicFrontendFiles())
        ->filter(fn (string $path) => preg_match("/from\s+'(@\/ovs[^']*|[^']*oedl[^']*|[^']*crmTheme[^']*|[^']*components\/ClinicShell|[^']*components\/nav)'/i", File::get($path)))
        ->map(fn (string $path) => str_replace(base_path().DIRECTORY_SEPARATOR, '', $path))
        ->values()->all();

    expect($offenders)->toBe([])
        ->and(File::exists(resource_path('js/pages/Clinic/components/ClinicShell.tsx')))->toBeFalse();
});

it('renders every signed-in Clinic page inside the one canonical portal shell, its own or the placeholder\'s', function () {
    $pages = renderedClinicPages();
    // The approved pre-auth pages (sign in, password, invitation) have their own frozen shell.
    $signedIn = array_values(array_filter($pages, fn (string $page) => ! str_starts_with($page, 'Clinic/Auth/')));
    // A page whose frontend was removed in the reset renders `ClinicPortal/Unavailable` (see app.tsx).
    $rendered = collect($signedIn)
        ->map(fn (string $page) => File::exists(resource_path("js/pages/{$page}.tsx")) ? $page : 'ClinicPortal/Unavailable')
        ->unique()->values()->all();

    $withoutShell = array_values(array_filter($rendered, function (string $page) {
        $source = File::get(resource_path("js/pages/{$page}.tsx"));

        return ! preg_match('/<(ClinicPortalShell|PortalShell)\b/', $source);
    }));

    expect($signedIn)->toContain('ClinicPortal/Appointments/Index', 'ClinicPortal/Patients/Index', 'ClinicPortal/Error')
        ->and($rendered)->toContain('ClinicPortal/Appointments/Index', 'ClinicPortal/Unavailable')
        ->and($withoutShell)->toBe([]);
});

it('navigates only from the sidebar, drawn from the server\'s list; the top bar carries no navigation', function () {
    $sidebar = File::get(resource_path('js/clinic/shell/Sidebar.tsx'));
    $topBar = File::get(resource_path('js/clinic/shell/TopBar.tsx'));

    expect($sidebar)->toContain('clinic.navigation')->toContain('<nav')
        ->and($topBar)->not->toContain('<nav')
        ->and($topBar)->not->toContain('clinic.navigation');
});
