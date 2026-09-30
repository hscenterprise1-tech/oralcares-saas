<?php

use App\Models\Activity;
use App\Models\CustomerSuccessSnapshot;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\User;
use App\Observers\CRM\LeadObserver;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    /*
     * `Lead` memoises organisation roles for the life of the process, because
     * whether an organisation is a provider or a customer is what the row *is*
     * rather than a status it moves through, and the provider guard runs on
     * every lead write.
     *
     * That assumption holds in production and not in a test run: every test
     * gets a fresh database behind the same process, so organisation 1 can be a
     * provider in one test and a customer in the next while keeping the id. The
     * cache is dropped between tests so a memoised role can never outlive the
     * row it came from.
     */
    ->beforeEach(fn () => Lead::forgetOrganizationRoles())
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Turn the organisation boundary on for the rest of this test.
 *
 * `OrganizationScope` lifts itself in console so that artisan commands, queue
 * workers, seeders and migrations can act across tenants — and the suite runs
 * in console too, which would make every isolation assertion pass without
 * proving anything. Binding the flag puts the scope back under the same rules a
 * real HTTP request gets.
 *
 * Call it in a test that asserts the boundary; leave it alone everywhere else,
 * because most tests build fixtures across organisations on purpose.
 *
 * Mirrors `enforce_tenant_scope_in_tests`, which `TenantScope` has read since it
 * was written.
 */
function entersOrganizationScope(): void
{
    app()->instance('enforce_organization_scope', true);
}

/**
 * Grant CRM permissions to a user.
 *
 * From Phase 0 onward, CRM policies authorize on real permissions rather than
 * on `user_type` alone, so a bare CompanyUser has no CRM access by design.
 * Tests that exercise CRM endpoints must therefore state the access they
 * assume — this helper makes that explicit in one line.
 *
 * Pass no arguments for the standard sales-rep permission set.
 */
function grantCrmPermissions(User $user, array $permissions = []): User
{
    $permissions = $permissions ?: [
        'view-crm',
        'view-leads',
        'view-pipeline',
        'view-demos',
        'view-trials',
        'view-quotations',
        'view-operations',
        'view-contacts',
        'view-tasks',
        'create leads',
        'edit own leads',
        'convert leads',
        /*
         * Starting, extending and cancelling a prospect's trial is a rep's own
         * job, so the standard set carries it. The point of the separate
         * permission is that a read-only user — finance, support — does not.
         */
        'manage-trials',
        'trials.provision',
        'manage-quotations',
    ];

    // Spatie runs in teams mode here (branch_id is the team key), so a team
    // context must be active before permissions can be attached. Team 0 is the
    // company-wide scope used by the portal seeders.
    setPermissionsTeamId(0);

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user->givePermissionTo($permissions);
    $user->forgetCachedPermissions();

    return $user->fresh();
}

/**
 * Give a user the Marketing access a Marketing endpoint now requires.
 *
 * Every route under `company/marketing` used to enforce nothing — no `can:`
 * middleware and no authorization call in any action — so a bare CompanyUser
 * could launch campaigns, delete contacts and throw the emergency broadcast
 * freeze. The group is gated on `view-marketing` now, which means a Marketing
 * test has to state the access it assumes, exactly as CRM tests already do
 * through `grantCrmPermissions()`.
 */
function grantMarketingPermissions(User $user, array $permissions = []): User
{
    $permissions = $permissions ?: [
        'view-marketing',
        /* Settings and the killswitch narrow further onto these two. */
        'view-marketing-settings',
        'manage-marketing-settings',
        'emergency-marketing-killswitch',
    ];

    setPermissionsTeamId(0);

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user->givePermissionTo($permissions);
    $user->forgetCachedPermissions();

    return $user->fresh();
}

/**
 * Grant CRM permissions to a role, so any user assigned that role inherits them.
 */
function grantCrmPermissionsToRole(Role $role, array $permissions = []): Role
{
    // Rep-level set. 'view-operations' is intentionally excluded: CrmDashboard
    // treats it as a manager signal, so granting it to a rep role would flip
    // manager-only surfaces on.
    $permissions = $permissions ?: [
        'view-crm',
        'view-leads',
        'view-pipeline',
        'view-demos',
        'view-trials',
        'view-quotations',
        'view-contacts',
        'view-tasks',
        'view-organizations',
        'create leads',
        'edit own leads',
        'convert leads',
        'manage-trials',
        'trials.provision',
        'manage-quotations',
    ];

    setPermissionsTeamId(0);

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $role->givePermissionTo($permissions);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $role->fresh();
}

/**
 * Grant management-level CRM permissions to a role.
 *
 * The manager permissions are what CrmScope reads to decide between
 * organization-wide and assigned-only data, so a role built with the rep-level
 * helper above is deliberately *not* a manager.
 */
function grantCrmManagerPermissionsToRole(Role $role): Role
{
    return grantCrmPermissionsToRole($role, [
        'view-crm',
        'view-leads',
        'view-pipeline',
        'view-demos',
        'view-trials',
        'view-quotations',
        'view-contacts',
        'view-tasks',
        'view-organizations',
        'create leads',
        'edit own leads',
        'convert leads',
        'view-operations',
        'view-analytics',
        'view-plans',
        'manage-employees',
    ]);
}

/**
 * Create a company user that holds CRM permissions.
 */
function crmUser(array $attributes = [], array $permissions = []): User
{
    $user = User::factory()->create(array_merge(
        ['user_type' => UserType::CompanyUser],
        $attributes
    ));

    return grantCrmPermissions($user, $permissions);
}

/**
 * Set a customer account's Customer Success projection.
 *
 * Every customer account now has a projection row from the moment it exists —
 * `OrganizationObserver` writes an empty one on creation, because the list
 * query INNER JOINs that table and a missing row would make the account
 * invisible. So a test cannot `create()` a second row for the same account; it
 * fills in the one that is already there.
 */
function projectSnapshot(int $organizationId, array $attributes = []): CustomerSuccessSnapshot
{
    return CustomerSuccessSnapshot::updateOrCreate(
        ['organization_id' => $organizationId],
        $attributes
    );
}

/**
 * Evidence that somebody was contacted.
 *
 * `contacted` is no longer a stage a deal can be dropped into — it asks for a
 * logged call, message, email, meeting or note first. Most tests do not care
 * how the evidence got there, only that it exists, so this puts it there.
 */
function logContact(Lead $lead, string $type = 'call'): Activity
{
    return $lead->activities()->create([
        'type' => $type,
        'description' => 'Spoke to the practice about their current software.',
        'user_id' => $lead->assigned_user_id,
    ]);
}

/**
 * A demo somebody could actually attend.
 *
 * `demo_scheduled` asks for the three facts that decide whether the meeting
 * happens: when, who presents, and how to get in.
 */
function scheduleJoinableDemo(Lead $lead, ?User $presenter = null): Demo
{
    $presenter ??= User::factory()->create(['user_type' => UserType::CompanyUser]);

    return Demo::factory()->create([
        'lead_id' => $lead->id,
        'presenter_id' => $presenter->id,
        'scheduled_at' => now()->addDay(),
        'meeting_link' => 'https://meet.google.com/abc-defg-hij',
    ]);
}

/**
 * A lead nobody owns.
 *
 * Nothing creates one any more — `LeadObserver::created()` gives every lead an
 * owner the moment it exists. These still occur as legacy rows, and the views
 * and alerts that surface them are what `LeadAssignmentService::backfill()`
 * exists to empty, so the fixture suppresses the assignment rather than
 * pretending the feature is gone.
 */
function unownedLead(array $attributes = []): Lead
{
    LeadObserver::$suppressAutoAssignment = true;

    try {
        return Lead::factory()->create($attributes);
    } finally {
        LeadObserver::$suppressAutoAssignment = false;
    }
}
