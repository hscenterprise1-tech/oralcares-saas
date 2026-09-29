<?php

/*
 * Design invariants for the company product ground.
 *
 * The CRM and Marketing share one canvas. It previously painted four large
 * blurred orbs — lavender, teal, green and violet — over a blue-tinted base,
 * which contradicted the approved reference direction on two explicit points:
 * the ground must be a near-white neutral and never coloured, and violet, teal
 * and lime are status colours, never decoration.
 *
 * Those are the kind of rules that quietly regrow, so they are asserted here
 * rather than left to a periodic manual audit.
 */

use Illuminate\Support\Facades\File;

/** Every retired decorative orb hue. Reintroducing one is the regression. */
const RETIRED_GROUND_HUES = ['#C9D8FA', '#BFE6EC', '#DCEBCF', '#DBD4F7', '#EFF2FB', '#E9EEFA'];

function designPath(string $relative): string
{
    return base_path('resources/js/pages/Company/design/'.$relative);
}

it('keeps the application ground a neutral near-white', function () {
    $css = File::get(base_path('resources/css/ovs-tokens.generated.css'));

    expect($css)->toMatch('/--ovs-crm-canvas-hsl:\s*\d+\s+\d+%\s+\d+%;/');

    preg_match('/--ovs-crm-canvas-hsl:\s*(\d+)\s+(\d+)%\s+(\d+)%;/', $css, $m);
    [$saturation, $lightness] = [(int) $m[2], (int) $m[3]];

    // A ground reads as neutral when it is barely saturated and very light.
    // The old value was 60% saturation at 96% lightness — unmistakably blue.
    expect($saturation)->toBeLessThanOrEqual(25)
        ->and($lightness)->toBeGreaterThanOrEqual(94);
});

it('does not paint a decorative colour field across the whole viewport', function () {
    $canvas = File::get(designPath('CrmCanvas.tsx'));

    // A fixed, full-bleed, pointer-events-none layer is how the orb field was
    // built. CrmMesh is deliberately absolute and section-scoped instead.
    expect($canvas)->not->toContain('fixed inset-0');
});

it('scopes the gradient mesh to a section rather than the page', function () {
    $canvas = File::get(designPath('CrmCanvas.tsx'));

    expect($canvas)->toContain('export function CrmMesh')
        ->and($canvas)->toContain('absolute -inset-x-8');
});

it('never reintroduces a retired ground hue anywhere in the company product', function () {
    $offenders = [];

    foreach (File::allFiles(base_path('resources/js/pages/Company')) as $file) {
        if (! in_array($file->getExtension(), ['tsx', 'ts'], true)) {
            continue;
        }

        $contents = File::get($file->getPathname());

        foreach (RETIRED_GROUND_HUES as $hue) {
            if (stripos($contents, $hue) !== false) {
                $offenders[] = $file->getRelativePathname().' → '.$hue;
            }
        }
    }

    expect($offenders)->toBe([]);
});

/*
 * One stage-colour source, one priority-colour source.
 *
 * `Deals/Index.tsx` used to declare its own `stageTone()` that squashed twelve
 * stages into five generic tones, so Demo Scheduled and Trial Active rendered
 * identically there while the Overview showed them violet and teal. The same
 * file also shadowed `priorityTone`. A stage must look the same everywhere it
 * appears or the colour stops being information, and this is the kind of rule
 * that quietly regrows — so it is asserted rather than remembered.
 */
it('never declares a local stage or priority colour map', function () {
    $offenders = [];

    /*
     * Scoped to the CRM and the design kit, which is where the rule bites: a
     * deal's stage must look identical on Overview, Deals, Deal Detail and
     * every badge. Finance declares its own `statusTone` for invoice statuses
     * (paid, issued, overdue) — a different domain crmTheme does not model, so
     * that one is a name collision rather than a competing map. Worth renaming
     * one day; not worth failing this test over.
     */
    $roots = [
        base_path('resources/js/pages/Company/Crm'),
        base_path('resources/js/pages/Company/design'),
        base_path('resources/js/pages/Company/CustomerSuccess'),
    ];

    foreach ($roots as $root) {
        foreach (File::allFiles($root) as $file) {
            if (! in_array($file->getExtension(), ['tsx', 'ts'], true)) {
                continue;
            }

            // crmTheme is where these legitimately live.
            if (str_contains($file->getFilename(), 'crmTheme')) {
                continue;
            }

            $contents = File::get($file->getPathname());

            foreach (['stageTone', 'priorityTone', 'statusTone', 'channelTone'] as $helper) {
                if (preg_match('/(function|const)\s+'.$helper.'\s*[(=]/', $contents)) {
                    $offenders[] = $file->getFilename().' declares '.$helper.'()';
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('has no Kanban board left in the CRM', function () {
    $offenders = [];

    foreach (File::allFiles(base_path('resources/js/pages/Company/Crm')) as $file) {
        $contents = File::get($file->getPathname());

        if (str_contains($contents, 'OVSKanbanBoard') || str_contains($contents, 'view=board')) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([])
        // The shared primitive itself stays — Visual Studio still renders it.
        ->and(File::exists(base_path('resources/js/ovs/patterns/kanban.tsx')))->toBeTrue();
});

it('reserves violet, teal and lime for meaning rather than decoration', function () {
    // These live in crmTheme as stage/status/channel tones. The rule is that a
    // page never reaches past the theme to paint a decorative surface with one.
    $offenders = [];

    foreach (File::allFiles(base_path('resources/js/pages/Company')) as $file) {
        if ($file->getExtension() !== 'tsx') {
            continue;
        }

        $contents = File::get($file->getPathname());

        // Large blurred decorative shapes are the pattern that went wrong.
        if (preg_match('/blur-\[\d{2,}px\][^"\']*bg-\[radial-gradient/', $contents)
            || preg_match('/bg-\[radial-gradient[^"\']*\][^"\']*blur-\[\d{2,}px\]/', $contents)) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});

it('builds every segmented tab strip from the one shared control', function () {
    // The Deals view tabs and the filter bar's quick segments were each a
    // hand-built copy of `CrmTabs` — same white container, same black active
    // pill, but drifted in font weight, badge tokens, gap and hover. Put side
    // by side the pages read as different systems. There is one implementation
    // now, and this keeps it that way: the pill-group chrome may only be
    // written inside CrmControls.
    $offenders = [];

    foreach (File::allFiles(base_path('resources/js/pages/Company')) as $file) {
        // Two controls share the pill-group container on purpose and are not
        // tab bars: the campaign builder's stepper, whose items carry done and
        // unreachable states a tab never has, and the pager, whose items are
        // page numbers. The family resemblance is the point; forcing them
        // through CrmTabs would cost them states they need.
        $shapedLikeATabStripButIsNot = ['CrmControls.tsx', 'CrmPagination.tsx', 'Builder.tsx'];

        if ($file->getExtension() !== 'tsx' || in_array($file->getFilename(), $shapedLikeATabStripButIsNot, true)) {
            continue;
        }

        $contents = File::get($file->getPathname());

        // The signature of the container: a white pill wrapping padded pills.
        if (preg_match('/rounded-full bg-white p-1\b/', $contents)
            && preg_match('/bg-ovs-crm-ink text-white/', $contents)) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});
