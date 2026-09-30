<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Http\Middleware\EnsureOwnerMode;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\SecurityAuditLog;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\OwnerMode\OwnerMode;
use App\UserType;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Owner Mode: the actual clinic owner's password-confirmed, temporary,
 * clinic-bound privileged workspace. Enforced on the server.
 */
beforeEach(function () {
    $this->clinic = Clinic::factory()->create(['slug' => 'owner-mode-dental']);
    $this->branch = Branch::factory()->create(['clinic_id' => $this->clinic->id, 'is_main' => true, 'status' => BranchStatus::Active]);

    // An owner-only action, to prove the server-side guard.
    Route::middleware(['web', 'clinic_portal', 'clinic_context', 'clinic_owner_mode'])
        ->get('/clinic/__owner-only', fn () => response('owner area'));
});

/**
 * @return array{user: User, membership: ClinicMembership}
 */
function ownerModeMember(Clinic $clinic, Branch $branch, bool $owner, ?ClinicRole $role = null): array
{
    $user = User::factory()->create(['user_type' => UserType::ClinicUser, 'password' => bcrypt('correct-horse')]);
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'is_primary_owner' => $owner,
        'has_all_branches_access' => true,
        'status' => ClinicMembershipStatus::Active,
    ]);

    if ($role) {
        setPermissionsTeamId(null);
        Role::firstOrCreate(['name' => $role->value, 'guard_name' => 'web', 'branch_id' => null])
            ->syncPermissions(collect($role->permissions())
                ->map(fn (string $permission) => Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']))
                ->all());
        app(ClinicRoleAssignmentService::class)->syncRoles($user, [$role->value], [$branch]);
    }

    return ['user' => $user, 'membership' => $membership];
}

function actAsOwnerModeMember(array $member, Branch $branch)
{
    return test()->actingAs($member['user'])->withSession([
        ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $member['membership']->id,
        ClinicContext::SESSION_BRANCH_ID => $branch->id,
    ]);
}

function ownerModeEvents(): array
{
    return SecurityAuditLog::where('event', 'like', 'owner_mode.%')->orderBy('id')->pluck('event')->all();
}

it('lets the owner, who is also a doctor, enter Owner Mode with their password', function () {
    $owner = ownerModeMember($this->clinic, $this->branch, owner: true, role: ClinicRole::Doctor);

    actAsOwnerModeMember($owner, $this->branch)->get('/clinic/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('clinic.staff_roles', ['Doctor'])
            ->where('clinic.owner_mode.available', true)
            ->where('clinic.owner_mode.active', false));

    actAsOwnerModeMember($owner, $this->branch)->post('/clinic/owner-mode', ['password' => 'correct-horse'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->get('/clinic/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('clinic.owner_mode.active', true));
    $this->get('/clinic/__owner-only')->assertOk()->assertSee('owner area');

    // Nothing about the account changed.
    expect($owner['membership']->fresh()->is_primary_owner)->toBeTrue()
        ->and($owner['user']->fresh()->getRoleNames()->all())->toBe(['Doctor']);
});

it('refuses a wrong password, stays off, and records the failure', function () {
    $owner = ownerModeMember($this->clinic, $this->branch, owner: true);

    actAsOwnerModeMember($owner, $this->branch)->post('/clinic/owner-mode', ['password' => 'wrong'])
        ->assertSessionHasErrors(['password' => 'That password is not correct.']);

    $this->get('/clinic/__owner-only')->assertForbidden();
    expect(ownerModeEvents())->toBe(['owner_mode.verification_failed'])
        ->and(json_encode(SecurityAuditLog::sole()->context))->not->toContain('wrong');
});

it('never offers or grants Owner Mode to a staff member who is not the owner', function (ClinicRole $role) {
    $member = ownerModeMember($this->clinic, $this->branch, owner: false, role: $role);

    actAsOwnerModeMember($member, $this->branch)->get('/clinic/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('clinic.owner_mode.available', false));

    actAsOwnerModeMember($member, $this->branch)->post('/clinic/owner-mode', ['password' => 'correct-horse'])
        ->assertForbidden();

    $this->get('/clinic/__owner-only')->assertForbidden();
    expect(session(OwnerMode::SESSION_KEY))->toBeNull()
        ->and(ownerModeEvents())->toBe(['owner_mode.refused']);
})->with([
    'clinic admin' => ClinicRole::ClinicAdmin,
    'doctor' => ClinicRole::Doctor,
]);

it('holds Owner Mode to the clinic it was entered in', function () {
    $owner = ownerModeMember($this->clinic, $this->branch, owner: true);
    $otherClinic = Clinic::factory()->create();
    $otherBranch = Branch::factory()->create(['clinic_id' => $otherClinic->id, 'status' => BranchStatus::Active]);
    $staffThere = ClinicMembership::factory()->create([
        'clinic_id' => $otherClinic->id, 'user_id' => $owner['user']->id, 'is_primary_owner' => false,
        'has_all_branches_access' => true, 'status' => ClinicMembershipStatus::Active,
    ]);

    actAsOwnerModeMember($owner, $this->branch)->post('/clinic/owner-mode', ['password' => 'correct-horse'])->assertRedirect();

    // The same session, now working in the other clinic.
    $this->withSession([ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $staffThere->id, ClinicContext::SESSION_BRANCH_ID => $otherBranch->id])
        ->get('/clinic/__owner-only')
        ->assertForbidden();

    expect(session(OwnerMode::SESSION_KEY))->toBeNull()
        ->and(ownerModeEvents())->toBe(['owner_mode.activated', 'owner_mode.exited']);
});

it('cannot be aimed at another clinic through the request', function () {
    $owner = ownerModeMember($this->clinic, $this->branch, owner: true);
    $otherClinic = Clinic::factory()->create();
    $otherBranch = Branch::factory()->create(['clinic_id' => $otherClinic->id, 'status' => BranchStatus::Active]);
    $staffThere = ClinicMembership::factory()->create([
        'clinic_id' => $otherClinic->id, 'user_id' => $owner['user']->id, 'is_primary_owner' => false,
        'has_all_branches_access' => true, 'status' => ClinicMembershipStatus::Active,
    ]);

    // Working in the clinic they do not own, naming the one they do.
    actAsOwnerModeMember(['user' => $owner['user'], 'membership' => $staffThere], $otherBranch)
        ->post('/clinic/owner-mode', [
            'password' => 'correct-horse',
            'clinic_id' => $this->clinic->id,
            'membership_id' => $owner['membership']->id,
        ])
        ->assertForbidden();

    expect(session(OwnerMode::SESSION_KEY))->toBeNull();
});

it('lets the owner leave Owner Mode without signing out', function () {
    $owner = ownerModeMember($this->clinic, $this->branch, owner: true);
    actAsOwnerModeMember($owner, $this->branch)->post('/clinic/owner-mode', ['password' => 'correct-horse']);

    $this->delete('/clinic/owner-mode')->assertRedirect();

    $this->get('/clinic/__owner-only')->assertForbidden();
    $this->get('/clinic/dashboard')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('clinic.owner_mode.active', false));
    $this->assertAuthenticatedAs($owner['user']);
    expect(ownerModeEvents())->toBe(['owner_mode.activated', 'owner_mode.exited']);
});

it('stays on while in use and expires after the idle timeout, needing the password again', function () {
    config(['security.owner_mode.idle_timeout_minutes' => 15]);
    $owner = ownerModeMember($this->clinic, $this->branch, owner: true);
    actAsOwnerModeMember($owner, $this->branch)->post('/clinic/owner-mode', ['password' => 'correct-horse']);

    // Each request inside the window keeps it alive.
    foreach (range(1, 3) as $step) {
        $this->travel(10)->minutes();
        $this->get('/clinic/__owner-only')->assertOk();
    }

    $this->travel(16)->minutes();
    $this->get('/clinic/__owner-only')->assertForbidden()->assertSee(EnsureOwnerMode::REFUSAL);
    $this->get('/clinic/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('clinic.owner_mode.active', false)->where('clinic.owner_mode.available', true));

    expect(ownerModeEvents())->toBe(['owner_mode.activated', 'owner_mode.expired']);

    $this->post('/clinic/owner-mode', ['password' => 'correct-horse'])->assertRedirect();
    $this->get('/clinic/__owner-only')->assertOk();
});

it('records activation with the verification method and a fresh session id', function () {
    $owner = ownerModeMember($this->clinic, $this->branch, owner: true);
    actAsOwnerModeMember($owner, $this->branch)->get('/clinic/dashboard');
    $before = session()->getId();

    $this->post('/clinic/owner-mode', ['password' => 'correct-horse']);

    expect(session()->getId())->not->toBe($before);
    $entry = SecurityAuditLog::where('event', 'owner_mode.activated')->sole();
    expect($entry->actor_user_id)->toBe($owner['user']->id)
        ->and($entry->clinic_id)->toBe($this->clinic->id)
        ->and($entry->context)->toMatchArray(['method' => 'password', 'idle_timeout_minutes' => 15]);
});

it('is not open to a guest', function () {
    $this->post('/clinic/owner-mode', ['password' => 'correct-horse'])->assertRedirect('/clinic/login');
    expect(ownerModeEvents())->toBe([]);
});
