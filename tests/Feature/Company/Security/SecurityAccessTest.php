<?php

use App\Models\Lead;
use App\Models\LoginHistory;
use App\Models\Organization;
use App\Models\User;
use App\Services\Security\SecurityReviewService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Security and access.
 *
 * Two things are pinned here. The first is that the review surface itself is
 * read-only and properly gated. The second is the hole it was built to find:
 * `LeadPolicy::delete` was written, registered and never called, so any
 * authenticated company user could delete any deal by id — another tenant's
 * included.
 */
function securityUser(array $permissions = []): User
{
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $user = User::factory()->create(['user_type' => UserType::CompanyUser]);

    if ($permissions !== []) {
        $user->givePermissionTo($permissions);
    }

    return $user->fresh();
}

beforeEach(function () {
    setPermissionsTeamId(0);

    foreach (['view-audit-logs', 'roles.manage', 'view-crm', 'view-leads'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $this->admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $this->reviewer = securityUser(['view-audit-logs']);
});

/* ═══════════════════════ the surface itself ════════════════════════════ */

it('lets the super admin open the security review holding nothing', function () {
    expect($this->admin->getAllPermissions())->toBeEmpty();

    $this->actingAs($this->admin)->get(route('company.security.index'))->assertSuccessful();
});

it('refuses the security review to a user without the audit permission', function () {
    $this->actingAs(securityUser())->get(route('company.security.index'))->assertForbidden();
    $this->actingAs($this->reviewer)->get(route('company.security.index'))->assertOk();
});

it('exposes only GET on the security review', function () {
    $verbs = collect(app('router')->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->uri(), 'company/security'))
        ->flatMap(fn ($route) => $route->methods())
        ->unique()
        ->sort()
        ->values();

    expect($verbs->all())->toBe(['GET', 'HEAD']);
});

it('mutates nothing when the review is opened', function () {
    LoginHistory::create(['user_id' => $this->admin->id, 'status' => 'success', 'login_time' => now()]);

    $before = [
        'users' => User::count(),
        'roles' => Role::count(),
        'permissions' => Permission::count(),
        'logins' => LoginHistory::count(),
    ];

    $this->actingAs($this->admin)->get(route('company.security.index'))->assertOk();

    expect(User::count())->toBe($before['users'])
        ->and(Role::count())->toBe($before['roles'])
        ->and(Permission::count())->toBe($before['permissions'])
        ->and(LoginHistory::count())->toBe($before['logins']);
});

it('never puts a session identifier in the payload', function () {
    /* The session id is the credential; a digest is enough to tell rows apart. */
    DB::table('sessions')->insert([
        'id' => 'raw-session-identifier-value',
        'user_id' => $this->admin->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);

    $sessions = app(SecurityReviewService::class)->sessions();

    expect($sessions['rows'][0]['id'])->not->toBe('raw-session-identifier-value')
        ->and(strlen($sessions['rows'][0]['id']))->toBe(12);
});

/* ═══════════════════════ the route guard audit ═════════════════════════ */

it('reports which Company routes authorize and which do not', function () {
    $audit = app(SecurityReviewService::class)->routeAudit();

    expect($audit['gated_by_middleware'])->toBeGreaterThan(0)
        ->and($audit['gated_in_controller'])->toBeGreaterThan(0)
        ->and($audit['total'])->toBe(
            $audit['gated_by_middleware'] + $audit['gated_in_controller']
            + $audit['intentionally_public'] + $audit['unprotected_count']
        );
});

it('counts a route gated by can middleware as protected', function () {
    $audit = app(SecurityReviewService::class)->routeAudit();

    $uris = collect($audit['unprotected'])->pluck('uri');

    /* These carry `can:` on the route itself. */
    expect($uris)->not->toContain('company/security')
        ->and($uris)->not->toContain('company/reports')
        ->and($uris)->not->toContain('company/subscriptions');
});

it('counts a route that authorizes inside its controller as protected', function () {
    $audit = app(SecurityReviewService::class)->routeAudit();

    $uris = collect($audit['unprotected'])->pluck('uri');

    /* Gated by `Gate::authorize` in the action rather than by middleware. */
    expect($uris)->not->toContain('company/people/attendance')
        ->and($uris)->not->toContain('company/people/leave');
});

/* ═════════════════ the hole the audit was built to find ════════════════ */

it('refuses a company user the right to delete a deal', function () {
    /*
     * `LeadPolicy::delete` restricts this to master admins and was never
     * called: the route carries no middleware and the action authorized
     * nothing, so any company user could delete any deal by id.
     */
    $lead = Lead::factory()->create();
    $user = securityUser(['view-crm', 'view-leads']);

    $this->actingAs($user)
        ->delete(route('company.crm.leads.destroy', $lead))
        ->assertForbidden();

    expect(Lead::whereKey($lead->id)->exists())->toBeTrue();
});

it('lets a master admin delete a deal, as the policy always said', function () {
    $lead = Lead::factory()->create();

    $this->actingAs($this->admin)
        ->delete(route('company.crm.leads.destroy', $lead))
        ->assertRedirect();

    expect(Lead::whereKey($lead->id)->exists())->toBeFalse();
});

/* ══════════════════════ Company / Clinic isolation ═════════════════════ */

it('keeps a clinic user out of every Company surface', function () {
    $clinicUser = User::factory()->create(['user_type' => UserType::ClinicUser]);

    foreach ([
        route('company.security.index'),
        route('company.subscriptions.index'),
        route('company.support.index'),
        route('company.people.payroll.index'),
    ] as $url) {
        $this->actingAs($clinicUser)->get($url)->assertForbidden();
    }
});

it('keeps a clinic user out even when they hold the permission', function () {
    /*
     * `CompanyPortalMiddleware` stops a clinic tenant before authorization is
     * reached, so a permission grant cannot carry them across the boundary.
     */
    $clinicUser = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $clinicUser->givePermissionTo('view-audit-logs');

    $this->actingAs($clinicUser->fresh())
        ->get(route('company.security.index'))
        ->assertForbidden();
});

/* ═══════════════════════ super admin bypass ════════════════════════════ */

it('reports the bypass per user rather than implying no access', function () {
    $users = app(SecurityReviewService::class)->users();

    $admin = collect($users)->firstWhere('id', $this->admin->id);

    expect($admin['is_super_admin'])->toBeTrue()
        ->and($admin['roles']->all())->toBe([])
        /* No roles, and authorized for everything — the page must say both. */
        ->and(Gate::forUser($this->admin)->allows('view-audit-logs'))->toBeTrue();
});

it('shows a role assignment that will not resolve on a Company request', function () {
    /*
     * Spatie is configured with teams on `branch_id`. An assignment made
     * against a branch does not resolve where no branch is set, which is how a
     * user holds a full role and still arrives with no permissions.
     */
    $role = Role::firstOrCreate(['name' => 'Branch Scoped', 'guard_name' => 'web']);
    $role->update(['branch_id' => 7]);

    setPermissionsTeamId(7);
    $this->reviewer->assignRole($role);
    setPermissionsTeamId(0);

    $row = collect(app(SecurityReviewService::class)->users())->firstWhere('id', $this->reviewer->id);

    expect($row['roles'])->toContain('Branch Scoped')
        ->and($row['inactive_assignments'])->toBe(1)
        ->and($this->reviewer->fresh()->getRoleNames()->all())->toBe([]);
});

/* ═══════════════════════ permission reconciliation ═════════════════════ */

it('names permissions that no role holds', function () {
    Permission::findOrCreate('orphaned-for-the-test', 'web');

    $reconciliation = app(SecurityReviewService::class)->permissionReconciliation();

    expect($reconciliation['held_by_no_role'])->toContain('orphaned-for-the-test')
        ->and($reconciliation['total'])->toBeGreaterThan(0);
});

it('names a gate a route asks for that nobody can pass', function () {
    /*
     * A route gated on a permission no role holds is a locked door with no key.
     * Worth naming: it reads as working code and refuses everybody.
     */
    $reconciliation = app(SecurityReviewService::class)->permissionReconciliation();

    expect($reconciliation)->toHaveKey('unreachable_gates')
        ->and($reconciliation['gated_on_by_routes'])->toContain('view-audit-logs');
});

it('creates no new permission for this surface', function () {
    /* It reuses `view-audit-logs`, which already means "may see what happened". */
    $source = file_get_contents(app_path('Http/Controllers/Company/Security/SecurityController.php'));

    expect($source)->toContain("Gate::authorize('view-audit-logs')")
        ->and(Permission::where('name', 'view-security')->exists())->toBeFalse()
        ->and(Permission::where('name', 'manage-security')->exists())->toBeFalse();
});

/* ═══════════════════════════ system vs custom ══════════════════════════ */

it('reports system versus custom as unknown rather than guessing', function () {
    /*
     * `roles` carries no `is_system` column, so nothing in this database
     * distinguishes a role the application depends on from one somebody made.
     * Inferring it from the name would invent the distinction.
     */
    Role::firstOrCreate(['name' => 'Support Manager', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Something Somebody Made', 'guard_name' => 'web']);

    $roles = app(SecurityReviewService::class)->roles();

    expect($roles)->toHaveCount(2)
        ->and(collect($roles)->pluck('is_system')->unique()->all())->toBe([null]);
});

/* ═══════════════════ every Company route enforces something ════════════ */

it('leaves no Company route enforcing nothing', function () {
    /*
     * The whole Marketing module used to sit here: no `can:` middleware and no
     * authorization call in any action, so any signed-in company user could
     * launch a campaign, delete contacts, connect a WhatsApp account and throw
     * the emergency broadcast freeze. Forty-four of those wrote data.
     */
    $audit = app(SecurityReviewService::class)->routeAudit();

    expect($audit['unprotected'])->toBe([])
        ->and($audit['unprotected_mutating'])->toBe(0);
});

it('refuses Marketing to a company user who holds no marketing permission', function () {
    $user = securityUser();

    foreach ([
        '/company/marketing/overview',
        '/company/marketing/contacts',
        '/company/marketing/campaigns',
        '/company/marketing/settings',
    ] as $uri) {
        $this->actingAs($user)->get($uri)->assertForbidden();
    }
});

it('refuses a Marketing write to a company user who holds no marketing permission', function () {
    $user = securityUser();

    $this->actingAs($user)
        ->post('/company/marketing/settings/emergency-freeze', ['enabled' => true])
        ->assertForbidden();

    $this->actingAs($user)
        ->post('/company/marketing/contacts', ['name' => 'X', 'phone' => '+919999999999'])
        ->assertForbidden();
});

it('admits a company user who holds view-marketing', function () {
    $user = securityUser(['view-marketing']);
    /* Marketing reads the actor's organisation, so give them one to render against. */
    $user->update(['organization_id' => Organization::factory()->create()->id]);

    $this->actingAs($user->fresh())->get('/company/marketing/overview')->assertOk();
});

it('creates a Company employee without a shared known password', function () {
    /*
     * Every employee added through the directory used to be created with
     * bcrypt of the literal string "password". `CompanyPortalMiddleware` admits
     * any `company_user`, so a guessable email was the only thing between an
     * outsider and the Company side. The employee sets their own through the
     * invitation flow that already existed.
     */
    $source = file_get_contents(app_path('Http/Controllers/Company/People/EmployeeController.php'));

    expect($source)->not->toContain("bcrypt('password')")
        ->and($source)->toContain('Hash::make(Str::random(');
});
