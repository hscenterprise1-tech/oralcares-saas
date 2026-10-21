<?php

use App\ClinicPermission;
use Symfony\Component\Finder\Finder;

/**
 * The Clinic frontend's foundation rules, checked against the source so a
 * regression fails the suite rather than a clinic in domain mode.
 */

/** @return array<string, string> path => source, every Clinic frontend file */
function clinicFrontendSources(): array
{
    $files = Finder::create()->files()->name(['*.ts', '*.tsx'])
        ->in([resource_path('js/pages/Clinic'), resource_path('js/pages/ClinicPortal'), resource_path('js/clinic')]);

    return collect(iterator_to_array($files))->mapWithKeys(fn (SplFileInfo $file) => [str_replace('\\', '/', $file->getRealPath()) => file_get_contents($file->getRealPath())])->all();
}

it('builds every Clinic link from the route table, never a /clinic path', function () {
    $offenders = collect(clinicFrontendSources())
        ->filter(fn (string $source) => preg_match('#[\'"`]/clinic(/|[\'"`])#', $source))
        ->keys()->map(fn (string $path) => str($path)->after('resources/js/')->toString())->values()->all();

    expect($offenders)->toBe([]);
});

it('types exactly the permissions the server grants', function () {
    $types = file_get_contents(resource_path('js/clinic/types.ts'));
    preg_match('/export type ClinicPermission =([^;]+);/', $types, $match);
    preg_match_all("/'([a-z._]+)'/", $match[1] ?? '', $values);

    expect($values[1])->toBe(ClinicPermission::values());
});

it('keeps the Clinic frontend on its own kit: no OVS, no Company design kit, no OVS tokens', function () {
    $offenders = collect(clinicFrontendSources())
        ->filter(fn (string $source) => preg_match("#from\s+['\"]@/(ovs|pages/Company)(/|['\"])#", $source) || preg_match('/\bovs-[A-Za-z]/', $source))
        ->keys()->map(fn (string $path) => str($path)->after('resources/js/')->toString())->values()->all();

    expect($offenders)->toBe([]);
});

it('imports only from the clinic kit, the Clinic pages and shared utilities', function () {
    $allowed = ['@/clinic', '@/pages/Clinic/', '@/pages/ClinicPortal/', '@/lib/utils'];
    $outside = collect(clinicFrontendSources())
        ->flatMap(fn (string $source) => preg_match_all("#from\s+['\"](@/[^'\"]+)['\"]#", $source, $m) ? $m[1] : [])
        ->reject(fn (string $import) => collect($allowed)->contains(fn (string $root) => $import === rtrim($root, '/') || str_starts_with($import, $root)))
        ->unique()->values()->all();

    expect($outside)->toBe([]);
});
