<?php

use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\ClinicEmployee;
use App\Models\ClinicEmployeeInvitation;
use App\Models\ClinicMembership;
use App\Models\CompanySubscription;
use App\Services\ResourceLimitService;
use App\Services\Staff\InvitationAcceptance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Support\ReceptionFixture;

/**
 * Login seats: only a login that can actually sign in holds one — an active
 * membership whose staff record is not inactive, suspended or resigned. An
 * invitation, a blocked login and a payroll-only profile hold none. Anything
 * that starts holding a seat (an accepted invitation, a reactivation, a move
 * into a doctor role) is checked against the plan first.
 *
 * The plan here is Dental Plan 1's shape: 2 doctor and 3 staff logins, the
 * owner one of the 3 staff.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->subscription = CompanySubscription::factory()->create([
        'clinic_id' => $this->f->clinic->id, 'max_users' => 5, 'max_doctors' => 2, 'max_staff' => 3, 'max_branches' => 1,
    ]);
    $this->limits = app(ResourceLimitService::class);
    // Role changes are the owner's Owner Mode action.
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession(withOwnerMode($this->f->session));
    // A login with a staff record, holding one clinic role at the main branch.
    $this->member = function (ClinicRole $role, string $name, string $status = 'active'): ClinicEmployee {
        [$user] = $this->f->memberWithRole($role);

        return ClinicEmployee::create(['clinic_id' => $this->f->clinic->id, 'user_id' => $user->id, 'name' => $name, 'email' => Str::slug($name).'@clinic.test', 'status' => $status]);
    };
    $this->seats = fn () => [
        'doctors' => $this->limits->getCurrentDoctorCount($this->f->clinic),
        'staff' => $this->limits->getCurrentStaffCount($this->f->clinic),
        'total' => $this->limits->getCurrentUserCount($this->f->clinic),
    ];
});

// ---------------------------------------------------------------- Counting

test('active doctor and staff logins are counted by seat, and the owner holds a staff seat', function () {
    ($this->member)(ClinicRole::Doctor, 'Dr Asha');
    ($this->member)(ClinicRole::Receptionist, 'Desk One');

    expect(($this->seats)())->toBe(['doctors' => 1, 'staff' => 2, 'total' => 3]);
});

test('an invitation not yet accepted holds no seat', function () {
    $employee = ClinicEmployee::create(['clinic_id' => $this->f->clinic->id, 'name' => 'Invited Person', 'email' => 'invited@clinic.test', 'status' => 'invited']);
    ClinicEmployeeInvitation::create([
        'clinic_id' => $this->f->clinic->id, 'clinic_employee_id' => $employee->id, 'email' => 'invited@clinic.test',
        'token' => hash('sha256', Str::random(60)), 'expires_at' => now()->addDays(3), 'created_by' => $this->f->owner->id,
    ]);

    expect(($this->seats)())->toBe(['doctors' => 0, 'staff' => 1, 'total' => 1]);
});

test('a suspended membership or a login-blocked staff record holds no seat', function () {
    ($this->member)(ClinicRole::Doctor, 'Dr Suspended', 'suspended');
    ($this->member)(ClinicRole::Receptionist, 'Desk Resigned', 'resigned');
    $paused = ($this->member)(ClinicRole::Receptionist, 'Desk Paused');
    ClinicMembership::query()->withoutGlobalScopes()->where('clinic_id', $this->f->clinic->id)->where('user_id', $paused->user_id)
        ->update(['status' => ClinicMembershipStatus::Suspended]);

    expect(($this->seats)())->toBe(['doctors' => 0, 'staff' => 1, 'total' => 1]);
});

test('another clinic\'s logins never count here', function () {
    $elsewhere = ReceptionFixture::make('elsewhere');
    [$theirDoctor] = $elsewhere->memberWithRole(ClinicRole::Doctor);
    ClinicEmployee::create(['clinic_id' => $elsewhere->clinic->id, 'user_id' => $theirDoctor->id, 'name' => 'Their Doctor', 'status' => 'active']);

    expect(($this->seats)())->toBe(['doctors' => 0, 'staff' => 1, 'total' => 1]);
});

// ---------------------------------------------------------------- Deactivation and reactivation

test('deactivating a login frees its seat, and reactivating it takes the seat back while one is free', function () {
    $desk = ($this->member)(ClinicRole::Receptionist, 'Desk One');

    ($this->asOwner)()->patch(route('admin.employees.status', $desk), ['status' => 'suspended'])->assertSessionHasNoErrors();
    expect(($this->seats)()['staff'])->toBe(1);

    ($this->asOwner)()->patch(route('admin.employees.status', $desk), ['status' => 'active'])->assertSessionHasNoErrors();
    expect(($this->seats)()['staff'])->toBe(2)
        ->and($desk->fresh()->status->value)->toBe('active');
});

test('reactivating a login is refused when its seat type is full, and the person stays inactive', function () {
    ($this->member)(ClinicRole::Receptionist, 'Desk One');
    ($this->member)(ClinicRole::Receptionist, 'Desk Two');
    $returning = ($this->member)(ClinicRole::BillingStaff, 'Billing Returning', 'inactive');

    ($this->asOwner)()->patch(route('admin.employees.status', $returning), ['status' => 'active'])
        ->assertSessionHasErrors(['status' => 'Your plan allows up to 3 staff members. Contact your account manager to add more seats.']);

    expect($returning->fresh()->status->value)->toBe('inactive')
        ->and(($this->seats)()['staff'])->toBe(3);
});

test('reactivating through the full staff form is held to the same limit', function () {
    ($this->member)(ClinicRole::Doctor, 'Dr One');
    ($this->member)(ClinicRole::Doctor, 'Dr Two');
    $returning = ($this->member)(ClinicRole::Doctor, 'Dr Returning', 'suspended');

    ($this->asOwner)()->put(route('admin.employees.update', $returning), ['name' => 'Dr Returning', 'status' => 'active'])
        ->assertSessionHasErrors(['status' => 'Your plan allows up to 2 doctors. Contact your account manager to add more seats.']);

    expect($returning->fresh()->status->value)->toBe('suspended')
        ->and(($this->seats)()['doctors'])->toBe(2);
});

test('moving a staff login into a doctor role needs a free doctor seat', function () {
    ($this->member)(ClinicRole::Doctor, 'Dr One');
    ($this->member)(ClinicRole::Doctor, 'Dr Two');
    $desk = ($this->member)(ClinicRole::Receptionist, 'Desk One');
    $doctorRole = Role::query()->where('name', ClinicRole::Doctor->value)->firstOrFail();

    ($this->asOwner)()->put(route('admin.employees.update', $desk), ['name' => 'Desk One', 'status' => 'active', 'roles' => [$doctorRole->id]])
        ->assertSessionHasErrors(['roles' => 'Your plan allows up to 2 doctors. Contact your account manager to add more seats.']);

    expect(($this->seats)())->toBe(['doctors' => 2, 'staff' => 2, 'total' => 4]);
});

test('a clinic with no active subscription can still reactivate a login, as before seats were checked', function () {
    $this->subscription->update(['status' => 'expired']);
    $returning = ($this->member)(ClinicRole::Receptionist, 'Desk Returning', 'inactive');

    ($this->asOwner)()->patch(route('admin.employees.status', $returning), ['status' => 'active'])->assertSessionHasNoErrors();

    expect($returning->fresh()->status->value)->toBe('active');
});

// ---------------------------------------------------------------- Profiles without a login

test('a payroll-only profile is created and reactivated with every seat taken, because it has no login', function () {
    ($this->member)(ClinicRole::Receptionist, 'Desk One');
    ($this->member)(ClinicRole::Receptionist, 'Desk Two');

    ($this->asOwner)()->post(route('admin.employees.store'), ['name' => 'Ward Helper', 'status' => 'active', 'staff_type' => 'receptionist'])->assertSessionHasNoErrors();
    $helper = ClinicEmployee::query()->withoutGlobalScopes()->where('name', 'Ward Helper')->sole();
    ($this->asOwner)()->patch(route('admin.employees.status', $helper), ['status' => 'inactive'])->assertSessionHasNoErrors();
    ($this->asOwner)()->patch(route('admin.employees.status', $helper), ['status' => 'active'])->assertSessionHasNoErrors();

    expect($helper->fresh()->user_id)->toBeNull()
        ->and($helper->fresh()->status->value)->toBe('active')
        ->and(($this->seats)()['staff'])->toBe(3);
});

// ---------------------------------------------------------------- Invitations

test('an invitation accepted after the last seat was taken is refused inside the locked check, and no login is made', function () {
    ($this->member)(ClinicRole::Receptionist, 'Desk One');
    ($this->member)(ClinicRole::Receptionist, 'Desk Two');
    $employee = ClinicEmployee::create(['clinic_id' => $this->f->clinic->id, 'name' => 'Late Joiner', 'email' => 'late@clinic.test', 'status' => 'invited']);
    $invitation = ClinicEmployeeInvitation::create([
        'clinic_id' => $this->f->clinic->id, 'clinic_employee_id' => $employee->id, 'email' => 'late@clinic.test',
        'token' => hash('sha256', Str::random(60)), 'expires_at' => now()->addDays(3), 'created_by' => $this->f->owner->id,
    ]);

    // Straight to the acceptance itself, past the page's early check: the case where another acceptance took the seat in between.
    expect(fn () => app(InvitationAcceptance::class)->acceptAsNewUser($invitation->load('clinic', 'employee'), 'Password123!'))
        ->toThrow(DomainException::class, "This clinic has reached its plan's login limit. You haven't been added yet — contact the clinic admin who invited you.");

    expect($invitation->fresh()->accepted_at)->toBeNull()
        ->and(ClinicMembership::query()->withoutGlobalScopes()->where('clinic_id', $this->f->clinic->id)->count())->toBe(3);
});
