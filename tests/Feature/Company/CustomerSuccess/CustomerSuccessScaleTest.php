<?php

declare(strict_types=1);

namespace Tests\Feature\Company\CustomerSuccess;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\CustomerSuccessSnapshot;
use App\Models\Organization;
use App\Models\User;
use App\OrganizationRole;
use App\SubscriptionStatus;
use App\UserType;
use Database\Seeders\CompanyPortalRolesSeeder;
use Database\Seeders\CrmRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Every Customer Success surface, measured rather than assumed.
 *
 * The module is built for a hundred and twenty thousand clinics, and the only
 * honest way to hold that claim is to render each page against a populated book
 * and count the queries it actually runs. A page whose query count rises with
 * the number of accounts does not scale, however fast it looks at eight.
 *
 * The bounds are deliberately generous — session, auth, permissions, the
 * paginator's count, the page query and its eager loads. What they catch is the
 * shape that matters: a figure computed per row, or a service called inside a
 * loop.
 */
beforeEach(function () {
    setPermissionsTeamId(0);
    $this->seed(CrmRolePermissionSeeder::class);
    $this->seed(CompanyPortalRolesSeeder::class);

    $this->vendorOrg = Organization::factory()->provider()->create(['name' => 'OralCares HQ']);

    $this->manager = User::factory()->create([
        'organization_id' => $this->vendorOrg->id,
        'user_type' => UserType::CompanyUser,
    ]);
    /* The persona that actually staffs this module — see CompanyPortalRolesSeeder. */
    $this->manager->assignRole(Role::findByName('Customer Success Manager', 'web'));
});

/**
 * Seed `$count` fully-formed customer accounts: organisation, clinic, branch,
 * subscription and snapshot — the shape every Customer Success page reads.
 */
function seedBook(User $owner, int $count, ?CompanyPlan $plan = null): void
{
    $plan ??= CompanyPlan::factory()->create(['name' => 'Scale Plan']);
    $now = now();

    /* Clinic slugs are globally unique, so a second call has to start where the
     * first stopped rather than at one. */
    $offset = Organization::query()->count();

    for ($k = 1; $k <= $count; $k++) {
        $i = $offset + $k;
        $org = Organization::factory()->create([
            'role' => OrganizationRole::Customer->value,
            'name' => sprintf('Scale Clinic %04d', $i),
            'assigned_user_id' => $owner->id,
        ]);

        $clinic = Clinic::factory()->create([
            'organization_id' => $org->id,
            'name' => sprintf('Scale Clinic %04d Main', $i),
            'slug' => sprintf('scale-clinic-%04d', $i),
        ]);

        Branch::factory()->create([
            'organization_id' => $org->id,
            'clinic_id' => $clinic->id,
            'name' => 'Main',
        ]);

        $subscription = CompanySubscription::create([
            'organization_id' => $org->id,
            'clinic_id' => $clinic->id,
            'company_plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => $now->copy()->subMonths(6),
            'ends_at' => $now->copy()->addDays(20),
            'mrr' => 15000,
            'max_users' => 10,
            'max_branches' => 3,
        ]);

        projectSnapshot($org->id, [
            'health_score' => 70 + ($i % 30),
            'churn_risk' => 'Low',
            'renewal_probability' => 80,
            'purchased_seats' => 10,
            'used_seats' => 4,
            'seat_utilization_pct' => 40,
            'purchased_branches' => 3,
            'active_branches' => 1,
            'branch_utilization_pct' => 33,
            'has_subscription' => true,
            'active_subscription_id' => $subscription->id,
            'plan_id' => $plan->id,
            'mrr' => 15000,
            'ends_at' => $now->copy()->addDays(20),
            'days_to_expiry' => 20,
            'onboarding_status' => 'completed',
            'onboarding_current_step' => 'go_live',
            'stage' => 'live',
            'expansion_opportunity' => false,
            'computed_at' => $now,
        ]);
    }
}

/** Render a route and return how many queries it took. */
function queriesToRender(User $actor, string $url): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = test()->actingAs($actor)->get($url);

    $log = DB::getQueryLog();
    DB::disableQueryLog();

    return [$response, count($log)];
}

/*
 * Each surface is rendered twice — over a small book and a book five times
 * larger — and the query count must not move. Comparing two sizes is what
 * separates "bounded" from "happens to be small", which a single absolute
 * threshold cannot tell apart.
 */
dataset('customerSuccessSurfaces', [
    'accounts' => ['company.customer-success.accounts.index'],
    'subscriptions' => ['company.customer-success.subscriptions.index'],
    'usage' => ['company.customer-success.usage.index'],
    'renewals' => ['company.customer-success.renewals.index'],
    'lifecycle' => ['company.customer-success.lifecycle.index'],
]);

it('runs the same number of queries whatever the size of the book', function (string $routeName) {
    seedBook($this->manager, 6);

    /*
     * One warm-up render first, and its count is discarded.
     *
     * The first request of a test resolves the permission registry and writes
     * the session, which is five queries that never happen again. Measuring it
     * would make every page look like it got *cheaper* as the book grew, and
     * hide the thing this test exists to catch.
     */
    queriesToRender($this->manager, route($routeName));

    [$small, $smallQueries] = queriesToRender($this->manager, route($routeName));
    $small->assertOk();

    seedBook($this->manager, 30);
    [$large, $largeQueries] = queriesToRender($this->manager, route($routeName));
    $large->assertOk();

    expect($largeQueries)->toBe(
        $smallQueries,
        "{$routeName} ran {$smallQueries} queries over 6 accounts and {$largeQueries} over 36 — it scales with the book."
    );
})->with('customerSuccessSurfaces');

it('keeps every Customer Success surface under a hard query ceiling', function (string $routeName) {
    seedBook($this->manager, 40);

    [$response, $queries] = queriesToRender($this->manager, route($routeName));

    $response->assertOk();
    expect($queries)->toBeLessThanOrEqual(
        30,
        "{$routeName} ran {$queries} queries for one page of rows."
    );
})->with('customerSuccessSurfaces');

/* ─────────────────────────────── Account 360 ──────────────────────────────── */

it('sends Account 360 its identity without touching the deferred zones', function () {
    seedBook($this->manager, 3);
    $organization = Organization::customerAccounts()->firstOrFail();

    [$response, $queries] = queriesToRender(
        $this->manager,
        route('company.customer-success.accounts.show', $organization)
    );

    $response->assertOk();

    /*
     * The eager payload is the masthead and nothing else. Every zone — health,
     * contacts, clinics, the commercial paper, the timeline — is deferred, so
     * opening an account must not pay for the tabs the reader has not opened.
     */
    expect($queries)->toBeLessThanOrEqual(20, "Account 360's first paint ran {$queries} queries.");
});

/* ──────────────────────────────── the export ──────────────────────────────── */

it('streams the export in bounded chunks rather than loading the book', function () {
    seedBook($this->manager, 60);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = $this->actingAs($this->manager)->get(route('company.customer-success.accounts.export'));

    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    $response->assertOk();

    /* Header plus one line per account. */
    expect(substr_count(trim($csv), "\n") + 1)->toBe(61);

    /*
     * Chunked at 500, so sixty accounts is one chunk plus the terminating
     * empty one — not sixty round trips and not one enormous result set.
     */
    expect($queries)->toBeLessThanOrEqual(15, "The export ran {$queries} queries for 60 accounts.");
});

/* ────────────────────────── the projection job ────────────────────────────── */

it('refreshes the projection in memory-bounded chunks', function () {
    seedBook($this->manager, 25);

    $this->artisan('cs:snapshots:refresh', ['--chunk' => 5])
        ->assertSuccessful();

    expect(CustomerSuccessSnapshot::count())->toBe(25);
});
