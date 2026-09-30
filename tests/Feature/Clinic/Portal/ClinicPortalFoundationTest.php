<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicPermission;
use App\ClinicRole;
use App\EmploymentStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\CompanyFeature;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Shared\Notification\Models\EnterpriseNotification;
use App\UserType;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The Clinic Portal foundation: the navigation, access and branch context
 * the server shares with every portal page, the bounded branch search, the
 * portal's own error pages, and the clinic scoping of notifications.
 */
beforeEach(function () {
    $this->clinic = Clinic::factory()->create(['slug' => 'portal-dental']);
    $this->branch = Branch::factory()->create(['clinic_id' => $this->clinic->id, 'is_main' => true, 'status' => BranchStatus::Active, 'name' => 'Andheri']);
});

/**
 * @return array{user: User, membership: ClinicMembership, session: array<string, int>}
 */
function portalMember(bool $owner = false, bool $allBranches = true, ?ClinicRole $role = null): array
{
    $test = test();
    $user = User::factory()->create(['user_type' => UserType::ClinicUser, 'password' => bcrypt('portal-password')]);
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $test->clinic->id,
        'user_id' => $user->id,
        'is_primary_owner' => $owner,
        'has_all_branches_access' => $allBranches,
        'status' => ClinicMembershipStatus::Active,
    ]);

    if ($role) {
        setPermissionsTeamId(null);
        Role::firstOrCreate(['name' => $role->value, 'guard_name' => 'web', 'branch_id' => null])
            ->syncPermissions(collect($role->permissions())
                ->map(fn (string $permission) => Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']))
                ->all());
        app(ClinicRoleAssignmentService::class)->syncRoles($user, [$role->value], [$test->branch]);
    }

    return [
        'user' => $user,
        'membership' => $membership,
        'session' => [
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id,
            ClinicContext::SESSION_BRANCH_ID => $test->branch->id,
        ],
    ];
}

function portalAs(array $member)
{
    return test()->actingAs($member['user'])->withSession($member['session']);
}

/**
 * @return list<string>
 */
function portalNavKeys(AssertableInertia $page): array
{
    return array_column($page->toArray()['props']['clinic']['navigation'], 'key');
}

/*
 * Navigation and access
 */

it('gives the owner every destination that exists, and none that does not', function () {
    portalAs(portalMember(owner: true))->get('/clinic/dashboard')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) {
            $page->component('ClinicPortal/Home')
                ->where('clinic.permissions', ClinicPermission::values())
                ->where('clinic.roles', ['Owner']);

            expect(portalNavKeys($page))->toBe(['dashboard', 'reception', 'appointments', 'patients', 'visits', 'staff', 'branches', 'departments', 'rooms', 'settings']);
        });
});

it('never offers Billing, Queue, Clinical, Inventory or Reports before their routes exist', function () {
    portalAs(portalMember(owner: true))->get('/clinic/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => expect(portalNavKeys($page))
            ->not->toContain('billing')
            ->not->toContain('queue')
            ->not->toContain('clinical')
            ->not->toContain('inventory')
            ->not->toContain('reports'));
});

it('shows a receptionist only what the receptionist role grants at this branch', function () {
    portalAs(portalMember(role: ClinicRole::Receptionist))->get('/clinic/dashboard')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) {
            $page->where('clinic.roles', ['Receptionist'])->where('clinic.is_owner', false);

            expect(portalNavKeys($page))->toBe(['dashboard', 'reception', 'appointments', 'patients', 'visits'])
                ->and($page->toArray()['props']['clinic']['permissions'])->not->toContain('staff.manage');
        });
});

it('shows a member with no role only the dashboard', function () {
    portalAs(portalMember())->get('/clinic/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => expect(portalNavKeys($page))->toBe(['dashboard'])
            ->and($page->toArray()['props']['clinic']['permissions'])->toBe([]));
});

it('hides a module the clinic plan does not include, even from the owner', function () {
    $plan = CompanyPlan::factory()->create();
    $patients = CompanyFeature::where('key', 'patients')->first() ?? CompanyFeature::factory()->create(['key' => 'patients']);
    $plan->features()->sync([$patients->id]);
    CompanySubscription::factory()->create(['clinic_id' => $this->clinic->id, 'company_plan_id' => $plan->id]);

    portalAs(portalMember(owner: true))->get('/clinic/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => expect(portalNavKeys($page))
            ->toContain('patients')
            ->not->toContain('appointments'));
});

it('links only to pages the user can actually open', function () {
    $member = portalMember(owner: true);
    $navigation = [];
    portalAs($member)->get('/clinic/dashboard')
        ->assertInertia(function (AssertableInertia $page) use (&$navigation) {
            $navigation = $page->toArray()['props']['clinic']['navigation'];
        });

    foreach ($navigation as $destination) {
        portalAs($member)->get($destination['href'])->assertOk();
    }
});

/*
 * Branch context at scale
 */

it('shares at most 50 branches with the page, with the real count', function () {
    Branch::factory()->count(59)->create(['clinic_id' => $this->clinic->id, 'status' => BranchStatus::Active]);

    portalAs(portalMember(owner: true))->get('/clinic/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('clinic.accessible_branches', HandleInertiaRequests::SHARED_BRANCH_LIMIT)
            ->where('clinic.accessible_branch_count', 60));
});

it('searches and pages only the branches the member may open', function () {
    Branch::factory()->count(30)->sequence(fn ($sequence) => ['name' => 'Pune '.str_pad((string) $sequence->index, 2, '0', STR_PAD_LEFT)])
        ->create(['clinic_id' => $this->clinic->id, 'status' => BranchStatus::Active]);
    Branch::factory()->create(['clinic_id' => $this->clinic->id, 'status' => BranchStatus::Inactive, 'name' => 'Pune Closed']);
    Branch::factory()->create(['clinic_id' => Clinic::factory()->create()->id, 'status' => BranchStatus::Active, 'name' => 'Pune Elsewhere']);
    $member = portalMember(owner: true);

    $first = portalAs($member)->getJson('/clinic/branches?search=Pune')->assertOk();
    expect($first->json('data'))->toHaveCount(25)
        ->and($first->json('next_page'))->toBe(2)
        ->and($first->json('active_branch_id'))->toBe($this->branch->id);

    $second = portalAs($member)->getJson('/clinic/branches?search=Pune&page=2')->assertOk();
    $names = collect($first->json('data'))->concat($second->json('data'))->pluck('name');
    expect($names)->toHaveCount(30)
        ->not->toContain('Pune Closed')
        ->not->toContain('Pune Elsewhere')
        ->and($second->json('next_page'))->toBeNull();
});

it('lists only assigned branches to a member without all-branch access', function () {
    $assigned = Branch::factory()->create(['clinic_id' => $this->clinic->id, 'status' => BranchStatus::Active, 'name' => 'Assigned']);
    Branch::factory()->create(['clinic_id' => $this->clinic->id, 'status' => BranchStatus::Active, 'name' => 'Not assigned']);
    $member = portalMember(allBranches: false);
    $member['membership']->assignedBranches()->attach([$this->branch->id, $assigned->id]);

    $names = portalAs($member)->getJson('/clinic/branches')->assertOk()->json('data.*.name');

    expect($names)->toEqualCanonicalizing(['Andheri', 'Assigned']);
});

it('refuses a switch to a branch the member may not open, on the portal error page', function () {
    $other = Branch::factory()->create(['clinic_id' => $this->clinic->id, 'status' => BranchStatus::Active]);
    $member = portalMember(allBranches: false);
    $member['membership']->assignedBranches()->attach($this->branch->id);

    portalAs($member)->post('/clinic/branches/switch', ['branch_id' => $other->id], ['X-Inertia' => 'true'])
        ->assertForbidden()
        ->assertJsonPath('component', 'ClinicPortal/Error')
        ->assertJsonPath('props.status', 403);

    expect(session(ClinicContext::SESSION_BRANCH_ID))->toBe($this->branch->id);
});

it('gives a login from another organisation its branches here, with the organisation boundary enforced', function () {
    entersOrganizationScope();
    $second = Branch::factory()->create(['clinic_id' => $this->clinic->id, 'organization_id' => $this->clinic->organization_id, 'status' => BranchStatus::Active]);
    $member = portalMember(owner: true);
    // The login's own organisation is another clinic's, as for a member of two.
    $member['user']->update(['organization_id' => Clinic::factory()->create()->organization_id]);

    portalAs($member)->get('/clinic/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('clinic.accessible_branch_count', 2)->where('clinic.active_branch.id', $this->branch->id));

    portalAs($member)->post('/clinic/branches/switch', ['branch_id' => $second->id])->assertRedirect();
    expect(session(ClinicContext::SESSION_BRANCH_ID))->toBe($second->id);
});

it('lets a member assigned to some branches switch between them from another organisation', function () {
    entersOrganizationScope();
    $second = Branch::factory()->create(['clinic_id' => $this->clinic->id, 'organization_id' => $this->clinic->organization_id, 'status' => BranchStatus::Active]);
    $member = portalMember(allBranches: false);
    $member['membership']->assignedBranches()->attach([$this->branch->id, $second->id]);
    $member['user']->update(['organization_id' => Clinic::factory()->create()->organization_id]);

    portalAs($member)->post('/clinic/branches/switch', ['branch_id' => $second->id])->assertRedirect();

    expect(session(ClinicContext::SESSION_BRANCH_ID))->toBe($second->id);
});

it('does not open the branch search to a guest', function () {
    $this->getJson('/clinic/branches')->assertUnauthorized();
});

/*
 * Error pages
 */

it('renders a missing record as the portal 404 page', function () {
    portalAs(portalMember(owner: true))->get('/clinic/patients/999999')
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('ClinicPortal/Error')->where('status', 404)->has('clinic'));
});

it('renders a permission refusal as the portal 403 page with its own sentence', function () {
    portalAs(portalMember())->get('/clinic/admin/settings')
        ->assertForbidden()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('ClinicPortal/Error')
            ->where('status', 403)
            ->where('message', 'You do not have permission to do this.')
            ->where('reason', null));
});

it('says a plan refusal is about the plan', function () {
    $plan = CompanyPlan::factory()->create();
    $patients = CompanyFeature::where('key', 'patients')->first() ?? CompanyFeature::factory()->create(['key' => 'patients']);
    $plan->features()->sync([$patients->id]);
    CompanySubscription::factory()->create(['clinic_id' => $this->clinic->id, 'company_plan_id' => $plan->id]);

    portalAs(portalMember(owner: true))->get('/clinic/appointments')
        ->assertForbidden()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('ClinicPortal/Error')->where('reason', 'plan_unavailable'));
});

it('never shows a server error\'s message or trace', function () {
    config(['app.debug' => false]);
    Route::middleware('web')->get('/clinic/__portal-test-failure', fn () => throw new RuntimeException('secret database detail'));

    portalAs(portalMember(owner: true))->get('/clinic/__portal-test-failure')
        ->assertStatus(500)
        ->assertInertia(fn (AssertableInertia $page) => $page->component('ClinicPortal/Error')->where('status', 500)->where('message', null))
        ->assertDontSee('secret database detail');
});

it('renders too many requests as the portal 429 page', function () {
    Route::middleware('web')->get('/clinic/__portal-test-throttle', fn () => abort(429));

    portalAs(portalMember(owner: true))->get('/clinic/__portal-test-throttle')
        ->assertStatus(429)
        ->assertInertia(fn (AssertableInertia $page) => $page->component('ClinicPortal/Error')->where('status', 429));
});

it('sends an expired form back to sign in, saying why', function () {
    Route::middleware('web')->post('/clinic/__portal-test-expired', fn () => throw new TokenMismatchException);

    $this->post('/clinic/__portal-test-expired?clinic=portal-dental')
        ->assertRedirect('/clinic/login?clinic=portal-dental')
        ->assertSessionHas('message', 'Your session expired. Please sign in again.');
});

it('leaves JSON errors and the Company side as they were', function () {
    portalAs(portalMember())->getJson('/clinic/admin/settings')
        ->assertForbidden()
        ->assertJsonMissingPath('component');

    $this->get('/company/__no-such-page')->assertNotFound()->assertDontSee('ClinicPortal/Error');
});

/*
 * Sign-in (the approved login page itself is unchanged)
 */

it('still renders the approved sign-in page, with links that keep the clinic', function () {
    $this->get('/clinic/login?clinic=portal-dental')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Clinic/Auth/ClinicLogin')
            ->where('portalLinks.forgotPassword', '/clinic/password/forgot?clinic=portal-dental'));
});

it('refuses sign-in to a login whose staff record has ended, with the existing message', function (EmploymentStatus $status) {
    $member = portalMember();
    ClinicEmployee::create(['clinic_id' => $this->clinic->id, 'user_id' => $member['user']->id, 'name' => $member['user']->name, 'email' => $member['user']->email, 'status' => $status]);

    $this->post('/clinic/login?clinic=portal-dental', ['email' => $member['user']->email, 'password' => 'portal-password'])
        ->assertSessionHasErrors(['email' => 'Your account does not have access to this clinic.']);

    $this->assertGuest();
})->with([EmploymentStatus::Inactive, EmploymentStatus::Suspended, EmploymentStatus::Resigned]);

/*
 * Notifications
 */

it('shows only the notifications of the clinic the session works in, whatever clinic_id is sent', function () {
    $member = portalMember();
    $elsewhere = Clinic::factory()->create();
    $mine = EnterpriseNotification::factory()->create(['notifiable_type' => $member['user']->getMorphClass(), 'notifiable_id' => $member['user']->id, 'clinic_id' => $this->clinic->id]);
    EnterpriseNotification::factory()->create(['notifiable_type' => $member['user']->getMorphClass(), 'notifiable_id' => $member['user']->id, 'clinic_id' => $elsewhere->id]);

    portalAs($member)->getJson('/api/notifications?clinic_id='.$elsewhere->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id);

    portalAs($member)->getJson('/api/notifications/unread-count?clinic_id='.$elsewhere->id)->assertJsonPath('count', 1);
});

it('caps a notification page at 50', function () {
    $member = portalMember();

    portalAs($member)->getJson('/api/notifications?per_page=100000')->assertOk()->assertJsonPath('per_page', 50);
});
