<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\ClinicStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicEmployeeInvitation;
use App\Models\ClinicMembership;
use App\Models\CompanySubscription;
use App\Models\User;
use App\Services\ClinicContext;
use App\UserType;
use Database\Seeders\SystemRoleTemplateSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/**
 * One person, one login, many clinics. An invitation to a clinic either
 * creates the login or adds that clinic to the existing one, after its owner
 * proves the account is theirs. The existing password is never changed.
 */
beforeEach(function () {
    $this->seed(SystemRoleTemplateSeeder::class);

    $this->clinicA = Clinic::factory()->create();
    $this->branchA = Branch::factory()->create(['clinic_id' => $this->clinicA->id, 'status' => BranchStatus::Active]);
    CompanySubscription::factory()->create(['clinic_id' => $this->clinicA->id]);

    $this->clinicB = Clinic::factory()->create();
    $this->branchB = Branch::factory()->create(['clinic_id' => $this->clinicB->id, 'status' => BranchStatus::Active]);

    // Priya already works at clinic B with her own login.
    $this->priya = User::factory()->create(['email' => 'priya@clinics.test', 'user_type' => UserType::ClinicUser, 'password' => Hash::make('priyas-own-password')]);
    $this->membershipB = ClinicMembership::factory()->create(['clinic_id' => $this->clinicB->id, 'user_id' => $this->priya->id, 'status' => ClinicMembershipStatus::Active]);
    $this->priyaPasswordHash = $this->priya->password;

    // Clinic A adds her as a doctor at branch A and invites her.
    $this->staffA = ClinicEmployee::create(['clinic_id' => $this->clinicA->id, 'name' => 'Dr. Priya', 'email' => 'priya@clinics.test', 'status' => 'active']);
    $this->staffA->branches()->attach($this->branchA->id);
    $this->staffA->roles()->attach(Role::where('name', ClinicRole::Doctor->value)->whereNull('branch_id')->value('id'));

    $this->invite = function (?ClinicEmployee $employee = null, array $attributes = []): string {
        $employee ??= $this->staffA;
        $token = Str::random(60);
        ClinicEmployeeInvitation::create(array_merge([
            'clinic_id' => $employee->clinic_id, 'clinic_employee_id' => $employee->id, 'email' => $employee->email,
            'token' => hash('sha256', $token), 'expires_at' => now()->addDays(3),
        ], $attributes));

        return URL::temporarySignedRoute('clinic.invitation.accept', now()->addDays(3), ['token' => $token]);
    };
});

it('adds a new clinic to an existing login after it confirms its own password', function () {
    $url = ($this->invite)();

    $this->get($url)->assertInertia(fn (Assert $page) => $page->component('Clinic/Auth/AcceptInvitation')->where('status', 'existing'));

    $this->from($url)->post($url, ['password' => 'priyas-own-password'])->assertRedirect(route('dashboard'));

    $membershipA = ClinicMembership::withoutGlobalScopes()->where('clinic_id', $this->clinicA->id)->where('user_id', $this->priya->id)->first();

    expect($membershipA)->not->toBeNull()
        ->and(User::where('email', 'priya@clinics.test')->count())->toBe(1)
        ->and($this->priya->fresh()->password)->toBe($this->priyaPasswordHash)
        ->and($this->membershipB->fresh()->status)->toBe(ClinicMembershipStatus::Active)
        ->and($membershipA->assignedBranches()->pluck('branches.id')->all())->toBe([$this->branchA->id])
        ->and($this->staffA->fresh()->user_id)->toBe($this->priya->id)
        ->and(session(ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID))->toBe($membershipA->id);

    setPermissionsTeamId($this->branchA->id);
    expect($this->priya->fresh()->hasRole(ClinicRole::Doctor->value))->toBeTrue();
    setPermissionsTeamId($this->branchB->id);
    expect($this->priya->fresh()->hasRole(ClinicRole::Doctor->value))->toBeFalse();
    setPermissionsTeamId(null);

    $this->assertAuthenticatedAs($this->priya);
});

it('refuses a wrong password and changes nothing', function () {
    $url = ($this->invite)();

    $this->from($url)->post($url, ['password' => 'not-her-password'])->assertSessionHasErrors('password');

    expect(ClinicMembership::withoutGlobalScopes()->where('clinic_id', $this->clinicA->id)->exists())->toBeFalse()
        ->and($this->priya->fresh()->password)->toBe($this->priyaPasswordHash)
        ->and($this->staffA->fresh()->user_id)->toBeNull();
    $this->assertGuest();
});

it('tells an existing member there is nothing to accept and creates no second membership', function () {
    ClinicMembership::factory()->create(['clinic_id' => $this->clinicA->id, 'user_id' => $this->priya->id, 'status' => ClinicMembershipStatus::Active]);
    $url = ($this->invite)();

    $this->get($url)->assertInertia(fn (Assert $page) => $page->where('status', 'already_member'));
    $this->post($url, ['password' => 'priyas-own-password'])->assertInertia(fn (Assert $page) => $page->where('status', 'already_member'));

    expect(ClinicMembership::withoutGlobalScopes()->where('clinic_id', $this->clinicA->id)->where('user_id', $this->priya->id)->count())->toBe(1);
});

it('refuses to add the clinic while someone else is signed in', function () {
    $someoneElse = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $url = ($this->invite)();

    $this->actingAs($someoneElse)->get($url)->assertInertia(fn (Assert $page) => $page->where('status', 'wrong_account'));
    $this->actingAs($someoneElse)->post($url, ['password' => 'priyas-own-password'])->assertInertia(fn (Assert $page) => $page->where('status', 'wrong_account'));

    expect(ClinicMembership::withoutGlobalScopes()->where('clinic_id', $this->clinicA->id)->exists())->toBeFalse();
});

it('shows an unusable link as invalid', function (string $case) {
    $url = match ($case) {
        'expired' => ($this->invite)(attributes: ['expires_at' => now()->subMinute()]),
        'revoked' => ($this->invite)(attributes: ['revoked_at' => now()]),
        'used' => ($this->invite)(attributes: ['accepted_at' => now()]),
        'unknown token' => URL::temporarySignedRoute('clinic.invitation.accept', now()->addDay(), ['token' => Str::random(60)]),
        'tampered' => ($this->invite)().'x',
        'staff record resigned' => tap(($this->invite)(), fn () => $this->staffA->update(['status' => 'resigned'])),
        'clinic suspended' => tap(($this->invite)(), fn () => $this->clinicA->update(['status' => ClinicStatus::Suspended])),
    };

    $this->get($url)->assertInertia(fn (Assert $page) => $page->where('status', 'invalid'));
    $this->post($url, ['password' => 'priyas-own-password'])->assertInertia(fn (Assert $page) => $page->where('status', 'invalid'));

    expect(ClinicMembership::withoutGlobalScopes()->where('clinic_id', $this->clinicA->id)->exists())->toBeFalse();
})->with(['expired', 'revoked', 'used', 'unknown token', 'tampered', 'staff record resigned', 'clinic suspended']);

it('cannot be used twice', function () {
    $url = ($this->invite)();
    $this->post($url, ['password' => 'priyas-own-password']);
    auth()->logout();

    $this->post($url, ['password' => 'priyas-own-password'])->assertInertia(fn (Assert $page) => $page->where('status', 'invalid'));
    expect(ClinicMembership::withoutGlobalScopes()->where('clinic_id', $this->clinicA->id)->count())->toBe(1);
});

it('enforces the clinic seat limit for an existing login too', function () {
    CompanySubscription::where('clinic_id', $this->clinicA->id)->update(['max_doctors' => 0]);
    $url = ($this->invite)();

    $this->from($url)->post($url, ['password' => 'priyas-own-password'])->assertSessionHas('error');

    expect(ClinicMembership::withoutGlobalScopes()->where('clinic_id', $this->clinicA->id)->exists())->toBeFalse();
});

it('only ever grants clinic roles from an invitation', function () {
    $superAdmin = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web', 'branch_id' => null]);
    $this->staffA->roles()->attach($superAdmin->id);
    $url = ($this->invite)();

    $this->post($url, ['password' => 'priyas-own-password'])->assertRedirect(route('dashboard'));

    setPermissionsTeamId($this->branchA->id);
    expect($this->priya->fresh()->getRoleNames()->all())->toBe([ClinicRole::Doctor->value]);
    setPermissionsTeamId(null);
});

it('keeps an invitation inside its own clinic', function () {
    $url = ($this->invite)();
    $this->post($url, ['password' => 'priyas-own-password']);

    expect(ClinicMembership::withoutGlobalScopes()->where('user_id', $this->priya->id)->pluck('clinic_id')->sort()->values()->all())
        ->toBe(collect([$this->clinicA->id, $this->clinicB->id])->sort()->values()->all())
        ->and($this->membershipB->assignedBranches()->count())->toBe(0);
});

it('refuses to join a company account to a clinic', function () {
    $company = User::factory()->companyUser()->create(['email' => 'ops@oralcares.test']);
    $staff = ClinicEmployee::create(['clinic_id' => $this->clinicA->id, 'name' => 'Ops', 'email' => 'ops@oralcares.test', 'status' => 'active']);
    $url = ($this->invite)($staff);

    $this->get($url)->assertInertia(fn (Assert $page) => $page->where('status', 'account_conflict'));
    $this->post($url, ['password' => 'anything'])->assertInertia(fn (Assert $page) => $page->where('status', 'account_conflict'));

    expect(ClinicMembership::withoutGlobalScopes()->where('user_id', $company->id)->exists())->toBeFalse();
});

it('works for a guest with the tenant scope on, as it does on the web', function () {
    $newcomer = ClinicEmployee::create(['clinic_id' => $this->clinicA->id, 'name' => 'New Nurse', 'email' => 'new.nurse@clinics.test', 'status' => 'active']);
    $newcomer->branches()->attach($this->branchA->id);
    $url = ($this->invite)($newcomer);

    app()->instance('enforce_tenant_scope_in_tests', true);

    $this->get($url)->assertInertia(fn (Assert $page) => $page->where('status', 'valid'));
    $this->post($url, ['password' => 'Newcomer-Passw0rd!', 'password_confirmation' => 'Newcomer-Passw0rd!'])->assertRedirect(route('dashboard'));

    app()->forgetInstance('enforce_tenant_scope_in_tests');

    $user = User::where('email', 'new.nurse@clinics.test')->first();
    expect($user)->not->toBeNull()
        ->and(ClinicMembership::withoutGlobalScopes()->where('clinic_id', $this->clinicA->id)->where('user_id', $user->id)->exists())->toBeTrue();
});
