<?php

use App\Models\User;
use App\Services\Security\SecurityReviewService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(0);
});

/* ═══════════ 1. the legacy payroll register is kept, not cleaned up ════════ */

it('keeps the legacy payroll register as history and never writes to it', function () {
    /*
     * `company_payrolls` predates payroll periods, compensation and approval.
     * The table is retained: its rows are the record of what was actually paid,
     * and deleting them is not a navigation decision.
     *
     * What is gone is the route. `GET /company/finance/payroll` was the last
     * reader of that table and had already lost its menu entry for reading dead
     * data — which left a live, gated, bookmarkable URL that nothing linked to.
     * Payroll is `company.people.payroll.*`, which writes and reads
     * `company_payroll_periods` and `company_payroll_entries`.
     */
    expect(DB::getSchemaBuilder()->hasTable('company_payrolls'))->toBeTrue()
        ->and(DB::getSchemaBuilder()->hasTable('company_payroll_periods'))->toBeTrue();

    $financePayrollRoutes = collect(Route::getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->uri(), 'company/finance/payroll'))
        ->map(fn ($route) => $route->uri())
        ->values();

    expect($financePayrollRoutes->all())->toBe([]);
});

it('offers one payroll in the navigation, and it is the one that is actually run', function () {
    /*
     * There were two entries on one permission, and naming them apart —
     * "Payroll register" in Finance, "Payroll" in People — only told a reader
     * that two existed. The register reads `company_payroll`, the eighteen
     * legacy rows People's own payroll code describes as never touched; the
     * live system is periods and entries. So Finance's entry is gone and People
     * owns payroll.
     *
     * The route and the page are gone too, now. Removing only the menu entry
     * left the URL reachable by hand, which is the same "second door onto dead
     * data" one step quieter — the assertion above pins that it is closed.
     */
    $config = file_get_contents(resource_path('js/ovs/layouts/sidebar-configs.ts'));

    expect($config)->toContain("href: '/company/people/payroll'")
        ->and($config)->not->toContain("href: '/company/finance/payroll'");
});

/* ═══════════════════ 2. Visual Studio is retired ═══════════════════════════ */

it('routes nothing at the retired visual studio', function () {
    /*
     * A static component gallery from the old OVS, reachable from three places
     * and doing nothing the product needs. Retired rather than repaired.
     */
    $exists = collect(Route::getRoutes())
        ->contains(fn ($route) => str_contains((string) $route->uri(), 'visual-studio'));

    expect($exists)->toBeFalse();

    expect(Route::has('company.visual-studio'))->toBeFalse();
});

it('offers no way in from any company navigation', function () {
    /* The sidebar, the command palette and the executive header all listed it. */
    foreach ([
        'js/ovs/layouts/sidebar-configs.ts',
        'js/components/enterprise-shell/CommandPalette.tsx',
        'js/components/executive-shell/ExecutiveHeader.tsx',
    ] as $file) {
        expect(file_get_contents(resource_path($file)))->not->toContain('visual-studio');
    }
});

it('leaves no visual studio code behind', function () {
    expect(is_dir(resource_path('js/pages/Company/VisualStudio')))->toBeFalse()
        ->and(file_exists(app_path('Http/Controllers/Company/VisualStudioController.php')))->toBeFalse();
});

it('stops seeding the permission that gated only visual studio', function () {
    /*
     * `view-api` named that surface and nothing else, so it is no longer granted.
     * Every other seeded permission is left alone.
     */
    $seeder = file_get_contents(database_path('seeders/CompanyPortalRolesSeeder.php'));

    expect($seeder)->not->toContain('view-api')
        ->and($seeder)->toContain('view-integrations')
        ->and($seeder)->toContain('view-platform');
});

it('reports no unprotected route after the removal', function () {
    /* The route analyser must not be left holding a dangling reference. */
    $audit = app(SecurityReviewService::class)->routeAudit();

    expect($audit['unprotected'])->toBe([])
        ->and($audit['unprotected_mutating'])->toBe(0);

    $source = file_get_contents(app_path('Services/Security/SecurityReviewService.php'));

    expect($source)->not->toContain('visual-studio');
});

/* ═══════════ 3. a company account is not closed from the profile ══════════ */

function profileUser(UserType $type): User
{
    return User::factory()->create([
        'user_type' => $type,
        'password' => Hash::make('correct-horse-battery'),
    ]);
}

it('refuses to let a company user delete their own account', function () {
    /*
     * A company account carries assignments, approvals and an audit trail other
     * people's records point at. Closing one is an administrative act.
     */
    foreach ([UserType::CompanyUser, UserType::MasterAdmin] as $type) {
        $user = profileUser($type);

        $this->actingAs($user)
            ->delete('/profile', ['password' => 'correct-horse-battery'])
            ->assertForbidden();

        expect(User::whereKey($user->id)->exists())->toBeTrue();
    }
});

it('refuses before the password is checked', function () {
    /* So nothing about the account is confirmed or denied on the way to the refusal. */
    $user = profileUser(UserType::CompanyUser);

    $this->actingAs($user)
        ->delete('/profile', ['password' => 'not-the-password'])
        ->assertForbidden()
        ->assertSessionHasNoErrors();
});

it('does not offer the section to a company user', function () {
    $this->actingAs(profileUser(UserType::CompanyUser))
        ->get('/profile')
        ->assertOk()
        ->assertDontSee('Delete Account');
});

it('leaves the rest of the company profile working', function () {
    $user = profileUser(UserType::CompanyUser);

    $this->actingAs($user)
        ->patch('/profile', ['name' => 'Renamed Person', 'email' => $user->email])
        ->assertRedirect();

    expect($user->fresh()->name)->toBe('Renamed Person');
});

/* ═══════════════ the clinic portal keeps the behaviour it had ══════════════ */

it('still lets a clinic user close their own account', function () {
    /*
     * `/profile` is shared. Only who may delete changed; the route, the form and
     * the clinic flow are as they were.
     */
    $user = profileUser(UserType::ClinicUser);

    $this->actingAs($user)->get('/profile')->assertOk()->assertSee('Delete Account');

    $this->actingAs($user)
        ->delete('/profile', ['password' => 'correct-horse-battery'])
        ->assertRedirect('/');

    expect(User::whereKey($user->id)->exists())->toBeFalse();
});
