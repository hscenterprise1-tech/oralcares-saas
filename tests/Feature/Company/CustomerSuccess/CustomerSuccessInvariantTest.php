<?php

declare(strict_types=1);

use App\CustomerChurnRisk;
use App\Models\CustomerSuccessSnapshot;
use App\Models\Organization;
use App\Models\User;
use App\Services\CustomerSuccess\OverviewService;
use App\UserType;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/**
 * The rules Customer Success keeps getting rebuilt out of.
 *
 * Each of these pins something that was actually broken and fixed, not
 * something that might one day go wrong. They are cheap, they read the source
 * rather than the rendering, and they fail loudly the next time a rebuild
 * reaches for a palette shade or forgets a guard.
 */

/* ────────────────────────────── design language ───────────────────────────── */

/**
 * Every Customer Success surface paints through the token layer.
 *
 * The module was rebuilt with 369 raw Tailwind palette classes across Account
 * 360, Usage and Subscriptions — including forty indigo ones, which is not even
 * this product's brand colour: `crm-brand` is teal (#12A0B5). CRM and Marketing
 * use zero. The tokens are what make one edit restyle the product, and a page
 * that picks shades directly is a page that stops following it.
 *
 * Scoped to Customer Success and Lifecycle deliberately. `Crm/Leads/components/
 * LeadLostModal.tsx` and three files in `design/` carry a handful of raw classes
 * that predate this and sit behind the CRM/Marketing freeze; widening this test
 * to them would be asserting a cleanup nobody has authorised.
 */
it('paints every Customer Success surface through the token layer', function () {
    $roots = [
        base_path('resources/js/pages/Company/CustomerSuccess'),
        base_path('resources/js/pages/Company/Lifecycle'),
    ];

    $palette = 'red|green|blue|indigo|amber|yellow|emerald|purple|violet|rose|slate|gray|zinc|orange|teal|sky|cyan|pink|lime|fuchsia';
    $pattern = '/\b(?:bg|text|border|ring|from|to|via)-(?:'.$palette.')-\d{2,3}\b/';

    $offenders = [];

    foreach ($roots as $root) {
        foreach (File::allFiles($root) as $file) {
            if (! in_array($file->getExtension(), ['tsx', 'ts'], true)) {
                continue;
            }

            if (preg_match_all($pattern, File::get($file->getPathname()), $matches)) {
                $offenders[] = $file->getFilename().': '.implode(', ', array_unique($matches[0]));
            }
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * Health, churn and stage are banded in one place.
 *
 * Accounts and Account 360 had each grown their own `healthTone` and
 * `churnTone`, and a third `stageTone` with a different set of stages — so one
 * unchanged score could read "good" on the list and "warn" on the detail page.
 * The canonical versions live in `design/crmTheme.ts`, which is where every
 * other tone map in this product lives.
 */
it('declares no local Customer Success tone map', function () {
    $offenders = [];

    foreach ([
        base_path('resources/js/pages/Company/CustomerSuccess'),
        base_path('resources/js/pages/Company/Lifecycle'),
    ] as $root) {
        foreach (File::allFiles($root) as $file) {
            if (! in_array($file->getExtension(), ['tsx', 'ts'], true)) {
                continue;
            }

            $contents = File::get($file->getPathname());

            foreach (['healthTone', 'churnTone', 'stageTone', 'lifecycleStageTone', 'utilizationTone'] as $helper) {
                if (preg_match('/(?:function|const)\s+'.$helper.'\s*[(=]/', $contents)) {
                    $offenders[] = $file->getFilename().' declares '.$helper.'()';
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * Every `ovs-crm-*` class names a token that exists.
 *
 * Tailwind emits nothing for a colour it does not know, and says nothing about
 * it either — so `bg-ovs-crm-bg-subtle`, `bg-ovs-crm-card` and
 * `hover:bg-ovs-crm-hover` sat in this module painting absolutely nothing while
 * looking entirely deliberate in the source. Twelve panels rendered flat white
 * where a tint was intended.
 */
it('names only tokens that exist on every Customer Success surface', function () {
    $generated = File::get(base_path('resources/js/ovs/tokens.generated.js'));

    /* The generated file is a plain object literal; the keys are the token names. */
    preg_match_all('/"([a-zA-Z0-9_-]+)"\s*:/', $generated, $keyMatches);
    $known = array_flip($keyMatches[1]);

    $offenders = [];

    foreach ([
        base_path('resources/js/pages/Company/CustomerSuccess'),
        base_path('resources/js/pages/Company/Lifecycle'),
    ] as $root) {
        foreach (File::allFiles($root) as $file) {
            if (! in_array($file->getExtension(), ['tsx', 'ts'], true)) {
                continue;
            }

            preg_match_all(
                '/\b(?:bg|text|border|ring|from|via|to|fill|stroke|divide|outline|accent|shadow|decoration|placeholder)-ovs-crm-([a-z0-9-]+)/',
                File::get($file->getPathname()),
                $matches
            );

            foreach (array_unique($matches[1]) as $suffix) {
                /*
                 * `crm-<suffix>` is how the token nests: a class
                 * `bg-ovs-crm-surface-inset` is `ovs.crm.surface.inset`, whose
                 * last segment is the key the generated file carries.
                 */
                $segments = explode('-', $suffix);
                $leaf = end($segments);

                if (! isset($known[$leaf]) && ! isset($known[$suffix])) {
                    $offenders[] = $file->getFilename().': ovs-crm-'.$suffix;
                }
            }
        }
    }

    expect(array_values(array_unique($offenders)))->toBe([]);
});

/**
 * The module has a front door.
 *
 * Customer Success opened on the account list sorted by name. At a hundred and
 * twenty thousand accounts that is not a starting point: nobody browses a book
 * that size, and no list answers "is this month in trouble".
 */
it('opens on an overview rather than a list of every account', function () {
    $admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);

    $this->actingAs($admin)
        ->get(route('company.customer-success.overview'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/CustomerSuccess/Overview/Index')
            ->has('kpis')
            ->has('attention')
        );
});

/**
 * The overview never repeats a list.
 *
 * Every line under "needs attention" is a count and a link into the worklist
 * that holds those rows. Two surfaces rendering the same rows is how their
 * counts start disagreeing — which this module has already done once, between
 * its headline figures and the lists beneath them.
 */
it('sends counts and destinations rather than rows', function () {
    $admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);

    $organization = Organization::factory()->customer()->create(['assigned_user_id' => null]);

    projectSnapshot($organization->id, [
        'health_score' => 30,
        'churn_risk' => CustomerChurnRisk::High->value,
        'has_subscription' => true,
        'mrr' => 9000,
        'days_to_expiry' => -5,
        'onboarding_status' => 'completed',
        'stage' => 'lapsed',
        'computed_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('company.customer-success.overview'))
        ->assertOk()
        ->assertInertia(function ($page) {
            $page->where('kpis.at_risk', 1)
                ->where('kpis.overdue', 1)
                ->where('kpis.mrr', 9000)
                ->where('kpis.mrr_at_risk', 9000);

            $attention = collect($page->toArray()['props']['attention']);

            /* Counts and hrefs, never the rows themselves. */
            expect($attention)->not->toBeEmpty();

            foreach ($attention as $item) {
                expect($item)->toHaveKeys(['count', 'href', 'label', 'severity'])
                    ->and($item['count'])->toBeGreaterThan(0);
            }

            return $page;
        });
});

/* ───────────────────────────────── freshness ──────────────────────────────── */

/**
 * The projection is refreshed on a schedule, not only on write.
 *
 * `customer_success_snapshots` holds `days_to_expiry`, `stage` and
 * `last_activity_at` — every one of which moves on its own while nobody touches
 * the account. The write paths refresh it, and the command to rebuild it
 * existed, but nothing ran that command: a contract thirty days from expiry
 * would still have said thirty days a month later, and "Renewing in 30 days",
 * "Lapsed" and "Over capacity" are all read straight off these columns.
 */
it('refreshes the customer success projection on a schedule', function () {
    $events = collect(app(Schedule::class)->events())
        ->map(fn ($event) => $event->command ?? '')
        ->filter(fn (string $command) => str_contains($command, 'cs:snapshots:refresh'));

    expect($events)->not->toBeEmpty('cs:snapshots:refresh is never scheduled, so the projection can only go stale.');
});

/* ─────────────────────────────── authorization ────────────────────────────── */

/**
 * A legacy URL keeps the permission its destination asks for.
 *
 * The Customer Success consolidation moved Subscriptions, Usage and Lifecycle
 * under the module's own prefix and left redirects behind — with no middleware,
 * so a user the target would refuse got a 301 instead of a 403. `routes/web.php`
 * already carries a comment about this exact mistake a hundred lines further
 * down, written the last time it happened.
 */
it('keeps authorization on every legacy Customer Success redirect', function () {
    $expected = [
        'company.subscriptions.index' => 'can:view-subscriptions',
        'company.subscriptions.show' => 'can:view-subscriptions',
        'company.usage.index' => 'can:view-usage',
        'company.lifecycle.index' => 'can:view-provisioning',
        'company.lifecycle.show' => 'can:view-provisioning',
    ];

    $unguarded = [];

    foreach ($expected as $name => $ability) {
        $route = app('router')->getRoutes()->getByName($name);

        expect($route)->not->toBeNull("Route {$name} no longer exists.");

        if (! in_array($ability, $route->gatherMiddleware(), true)) {
            $unguarded[] = "{$name} is missing {$ability}";
        }
    }

    expect($unguarded)->toBe([]);
});

/**
 * The overview says what shape the book is in, not only how much is wrong.
 *
 * "Four at risk" says how many are bad. It does not say whether this is a
 * healthy book with a bad tail or a mediocre one throughout, and those are
 * different afternoons. Both distributions are one grouped scan of the
 * projection, which is why they can be asked at all at this size.
 */
it('sends the shape of the book, banded', function () {
    $admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);

    foreach ([[95, 'live'], [64, 'grace'], [20, 'lapsed']] as [$score, $stage]) {
        projectSnapshot(Organization::factory()->customer()->create()->id, [
            'health_score' => $score,
            'churn_risk' => CustomerChurnRisk::Low->value,
            'has_subscription' => true,
            'stage' => $stage,
            'computed_at' => now(),
        ]);
    }

    $this->actingAs($admin)
        ->get(route('company.customer-success.overview'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(['detail'], fn ($page) => $page
                ->where('distribution.health.0.count', 1)
                ->where('distribution.health.1.count', 1)
                ->where('distribution.health.2.count', 1)
                /* Ordered by where an account sits in its life, not by count. */
                ->where('distribution.stages.0.key', 'grace')
                ->where('distribution.stages.1.key', 'live')
                ->where('distribution.stages.2.key', 'lapsed')
            )
        );
});

/**
 * Every count on the overview opens the rows behind it.
 *
 * The stage ladder is a set of counts, and a count nobody can open is a dead
 * end: clicking "9 lapsed" landed on all accounts with the filter silently
 * dropped, because the account list only ever understood `view`.
 */
it('opens the accounts behind a stage on the overview ladder', function () {
    $admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);

    $lapsed = Organization::factory()->customer()->create(['name' => 'Lapsed Clinic']);
    $live = Organization::factory()->customer()->create(['name' => 'Live Clinic']);

    foreach ([[$lapsed, 'lapsed'], [$live, 'live']] as [$organization, $stage]) {
        projectSnapshot($organization->id, [
            'health_score' => 70,
            'churn_risk' => CustomerChurnRisk::Low->value,
            'has_subscription' => true,
            'stage' => $stage,
            'computed_at' => now(),
        ]);
    }

    $this->actingAs($admin)
        ->get(route('company.customer-success.accounts.index', ['stage' => 'lapsed']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('accounts', 1)
            ->where('accounts.0.name', 'Lapsed Clinic')
            /* Echoed back, or the page cannot show the reader what it applied. */
            ->where('filters.stage', 'lapsed')
        );
});

/**
 * The renewal horizon groups in SQL, month by month.
 *
 * The point of the shape is that a heavy month is visible before it arrives.
 * This also pins the grouping expression itself, which is the one piece of this
 * service whose spelling differs between MySQL and SQLite.
 */
it('groups the renewal horizon by month', function () {
    $admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);

    foreach ([[15, 4000], [20, 6000], [70, 9000]] as [$days, $mrr]) {
        projectSnapshot(Organization::factory()->customer()->create()->id, [
            'health_score' => 80,
            'churn_risk' => CustomerChurnRisk::Low->value,
            'has_subscription' => true,
            'mrr' => $mrr,
            'ends_at' => now()->addDays($days),
            'days_to_expiry' => $days,
            'stage' => 'live',
            'computed_at' => now(),
        ]);
    }

    $expected = now()->addDays(15)->format('Y-m');

    $this->actingAs($admin)
        ->get(route('company.customer-success.overview'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(['detail'], fn ($page) => $page
                ->where('renewalHorizon.0.month', $expected)
                /* Two in the same month arrive as one row carrying both. */
                ->where('renewalHorizon.0.accounts', now()->addDays(20)->format('Y-m') === $expected ? 2 : 1)
            )
        );
});

/**
 * The overview names customers, once, in the place meant for it.
 *
 * Everything else on the page is an aggregate, and nobody acts on "four at
 * risk" — they act on "Apex Dental, nine days past its renewal date, renew
 * it". So this queue carries the account, the reason it is on the list, and
 * the single next step, and that step lands on the tab that performs it.
 */
it('names the accounts to act on and where the action happens', function () {
    $admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);

    $lapsed = Organization::factory()->customer()->create(['name' => 'Nine Days Late']);
    $soon = Organization::factory()->customer()->create(['name' => 'Renewing Soon']);

    projectSnapshot($lapsed->id, [
        'health_score' => 60,
        'churn_risk' => CustomerChurnRisk::Low->value,
        'has_subscription' => true,
        'mrr' => 4000,
        'days_to_expiry' => -9,
        'stage' => 'lapsed',
        'computed_at' => now(),
    ]);

    projectSnapshot($soon->id, [
        'health_score' => 80,
        'churn_risk' => CustomerChurnRisk::Low->value,
        'has_subscription' => true,
        'mrr' => 90000,
        'days_to_expiry' => 12,
        'stage' => 'live',
        'computed_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('company.customer-success.overview'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(['queue'], fn ($page) => $page
                /*
                 * Past the date outranks a larger contract that is merely due:
                 * value breaks ties inside a band, it does not cross one.
                 */
                ->where('workQueue.0.name', 'Nine Days Late')
                ->where('workQueue.0.reason', '9 days past the renewal date')
                ->where('workQueue.0.action', 'Renew')
                ->where('workQueue.1.name', 'Renewing Soon')
                ->where('workQueue.1.action', 'Confirm renewal')
            )
        );
});

/**
 * Every action on the queue lands somewhere that exists.
 *
 * A button that opens a tab nobody built is the exact defect this module was
 * rebuilt to remove — "Manage contract" pointed at a `contract` tab three weeks
 * before there was one.
 */
it('sends every queue action to a tab the account page renders', function () {
    $admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);

    $organization = Organization::factory()->customer()->create(['assigned_user_id' => null]);

    projectSnapshot($organization->id, [
        'health_score' => 90,
        'churn_risk' => CustomerChurnRisk::Low->value,
        'has_subscription' => false,
        'stage' => 'not_provisioned',
        'computed_at' => now(),
    ]);

    $queue = app(OverviewService::class)->workQueue($admin);

    expect($queue)->not->toBeEmpty();

    foreach ($queue as $row) {
        $this->actingAs($admin)->get($row['href'])->assertOk();
    }
});

/**
 * Customer Success never raises a CRM deal.
 *
 * Once a deal is won the account belongs here: another year is a renewal, more
 * seats an entitlement change, a bigger plan an upgrade. None is a sale, and
 * none should reopen the pipeline built for winning strangers.
 *
 * Two buttons survived the rebuild on the Contract tab, and the action behind
 * them invented the money it wrote — `240000.00` for an expansion, `50000.00`
 * for a renewal, hard-coded, straight into a deal's expected ARR.
 */
it('offers no route that turns a customer back into a CRM deal', function () {
    $names = collect(Route::getRoutes())
        ->map(fn ($route) => (string) $route->getName())
        ->filter(fn (string $name) => str_starts_with($name, 'company.customer-success.'));

    expect($names->filter(fn (string $name) => str_contains($name, 'create-deal'))->values()->all())->toBe([]);
});

/**
 * No Customer Success surface reaches for a raw palette shade.
 *
 * The existing check covered `bg`, `text`, `border`, `ring` and the gradient
 * stops, which is how `shadow-indigo-600/20` sat on the Contract tab untouched
 * in a product whose brand is teal.
 */
it('paints shadows through the token layer too', function () {
    $palette = 'red|green|blue|indigo|amber|yellow|emerald|purple|violet|rose|slate|gray|zinc|orange|teal|sky|cyan|pink|lime|fuchsia';

    $offenders = [];

    foreach ([
        base_path('resources/js/pages/Company/CustomerSuccess'),
        base_path('resources/js/pages/Company/Lifecycle'),
    ] as $root) {
        foreach (File::allFiles($root) as $file) {
            if (! in_array($file->getExtension(), ['tsx', 'ts'], true)) {
                continue;
            }

            if (preg_match_all('/\b(?:shadow|outline|divide|fill|stroke|decoration|placeholder|accent)-(?:'.$palette.')-\d{2,3}\b/', File::get($file->getPathname()), $matches)) {
                $offenders[] = $file->getFilename().': '.implode(', ', array_unique($matches[0]));
            }
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * The projection has a row for exactly the live customer accounts.
 *
 * This is the contract that lets the list INNER JOIN the snapshot and ask for
 * its accounts `withTrashed()`, and those two together are the difference
 * between a page that answers in a millisecond and one that sorts a hundred and
 * twenty thousand rows to show twenty-five. Measured on a book that size:
 *
 *     LEFT JOIN, deleted_at IS NULL   2,892 ms
 *     INNER JOIN, withTrashed()           1.5 ms
 *
 * Both halves are load-bearing and both fail silently: a missing row hides a
 * real customer, a leftover row shows a deleted one. Hence a test rather than a
 * comment.
 */
it('projects every live customer account and nothing else', function () {
    $customer = Organization::factory()->customer()->create();

    expect(CustomerSuccessSnapshot::where('organization_id', $customer->id)->exists())->toBeTrue();

    /* A provider is not a customer account and never enters the projection. */
    $provider = Organization::factory()->provider()->create();
    expect(CustomerSuccessSnapshot::where('organization_id', $provider->id)->exists())->toBeFalse();

    $customer->delete();
    expect(CustomerSuccessSnapshot::where('organization_id', $customer->id)->exists())->toBeFalse();

    $customer->restore();
    expect(CustomerSuccessSnapshot::where('organization_id', $customer->id)->exists())->toBeTrue();
});

/**
 * A deleted customer leaves the book.
 *
 * The list no longer says `deleted_at IS NULL` — it relies on the trashed
 * account having no projection row. If that ever stopped being true this is
 * where it would show: a deleted customer still sitting in somebody's list.
 */
it('drops a deleted account out of the accounts list', function () {
    $admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);

    $staying = Organization::factory()->customer()->create(['name' => 'Still Here Clinic']);
    $going = Organization::factory()->customer()->create(['name' => 'Closed Down Clinic']);

    $this->actingAs($admin)
        ->get(route('company.customer-success.accounts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('accounts', 2));

    $going->delete();

    $this->actingAs($admin)
        ->get(route('company.customer-success.accounts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('accounts', 1)
            ->where('accounts.0.name', $staying->name)
        );
});

/**
 * Nothing in the list orders by an expression.
 *
 * An index can be read in order; `COALESCE(css.health_score, …)` cannot, and
 * every sort this page offers used to be wrapped in one "just in case" an
 * account had no snapshot. That case no longer exists, and the wrappers cost
 * three seconds a page when it did not.
 */
it('sorts the accounts list on bare indexed columns', function () {
    $source = File::get(base_path('app/Http/Controllers/Company/CustomerSuccess/AccountController.php'));

    $sortSection = Str::between($source, '$sortColumn = match ($sort) {', '};');

    expect($sortSection)->not->toContain('COALESCE')
        ->and($sortSection)->not->toContain('CASE WHEN')
        ->and($sortSection)->not->toContain('orderByRaw');
});
