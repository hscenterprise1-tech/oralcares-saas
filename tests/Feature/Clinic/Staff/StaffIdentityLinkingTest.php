<?php

use App\ClinicMembershipStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\SecurityAuditLog;
use App\Models\User;
use App\Services\Staff\StaffIdentityLinker;
use App\UserType;
use Illuminate\Database\QueryException;

/**
 * User → ClinicMembership → ClinicEmployee. A staff record is linked to a
 * login only on unambiguous same-clinic evidence, never across clinics.
 */
beforeEach(function () {
    $this->clinic = Clinic::factory()->create();
    $this->branch = Branch::factory()->create(['clinic_id' => $this->clinic->id]);
    $this->otherClinic = Clinic::factory()->create();
    $this->linker = app(StaffIdentityLinker::class);

    $this->login = function (string $email, ?Clinic $memberOf = null, ClinicMembershipStatus $status = ClinicMembershipStatus::Active): User {
        $user = User::factory()->create(['email' => $email, 'user_type' => UserType::ClinicUser]);
        if ($memberOf) {
            ClinicMembership::factory()->create(['clinic_id' => $memberOf->id, 'user_id' => $user->id, 'status' => $status]);
        }

        return $user;
    };
    $this->staff = fn (string $email, ?Clinic $clinic = null, string $status = 'active') => ClinicEmployee::create([
        'clinic_id' => ($clinic ?? $this->clinic)->id, 'name' => 'Staff '.$email, 'email' => $email, 'status' => $status,
    ]);
});

it('links a staff record to the login with the same email that is a member of the same clinic', function () {
    $user = ($this->login)('asha@clinic.test', $this->clinic);
    $employee = ($this->staff)('asha@clinic.test');
    $employee->branches()->attach($this->branch->id);

    expect($this->linker->link($this->clinic->id))->toBe(1)
        ->and($employee->fresh()->user_id)->toBe($user->id);

    $membership = ClinicMembership::withoutGlobalScopes()->where('user_id', $user->id)->first();
    expect($membership->assignedBranches()->pluck('branches.id')->all())->toBe([$this->branch->id]);

    $linked = SecurityAuditLog::where('event', 'staff.employee.linked')->first();
    expect($linked->context)->toMatchArray(['employee_id' => $employee->id, 'to_user_id' => $user->id])
        ->and(json_encode($linked->context))->not->toContain('asha@clinic.test');
});

it('leaves a staff record with no matching login unlinked and does not report it as a problem', function () {
    $employee = ($this->staff)('nobody@clinic.test');

    expect($this->linker->link($this->clinic->id))->toBe(0)
        ->and($employee->fresh()->user_id)->toBeNull()
        ->and($this->linker->report($this->clinic->id)['unresolved'])->toBe([]);
});

it('never links a login that belongs to another clinic', function () {
    ($this->login)('ravi@clinic.test', $this->otherClinic);
    $employee = ($this->staff)('ravi@clinic.test');

    expect($this->linker->link($this->clinic->id))->toBe(0)
        ->and($employee->fresh()->user_id)->toBeNull()
        ->and($this->linker->report($this->clinic->id)['unresolved'][0]['reason'])->toContain('no membership in this clinic');
});

it('links the same email only in the clinic the login is a member of', function () {
    $user = ($this->login)('same@clinic.test', $this->clinic);
    $here = ($this->staff)('same@clinic.test');
    $there = ($this->staff)('same@clinic.test', $this->otherClinic);

    $this->linker->link($this->clinic->id);
    $this->linker->link($this->otherClinic->id);

    expect($here->fresh()->user_id)->toBe($user->id)
        ->and($there->fresh()->user_id)->toBeNull();
});

it('does not link through an inactive membership', function () {
    ($this->login)('inactive@clinic.test', $this->clinic, ClinicMembershipStatus::Inactive);
    $employee = ($this->staff)('inactive@clinic.test');

    expect($this->linker->link($this->clinic->id))->toBe(0)
        ->and($employee->fresh()->user_id)->toBeNull()
        ->and($this->linker->report($this->clinic->id)['unresolved'][0]['reason'])->toContain('not active');
});

it('does not link an inactive, suspended or resigned staff record', function (string $status) {
    ($this->login)("{$status}@clinic.test", $this->clinic);
    $employee = ($this->staff)("{$status}@clinic.test", status: $status);

    expect($this->linker->link($this->clinic->id))->toBe(0)
        ->and($employee->fresh()->user_id)->toBeNull();
})->with(['inactive', 'suspended', 'resigned']);

it('does not give a login a second staff record in the same clinic', function () {
    $user = ($this->login)('taken@clinic.test', $this->clinic);
    ClinicEmployee::create(['clinic_id' => $this->clinic->id, 'user_id' => $user->id, 'name' => 'Existing', 'email' => 'other@clinic.test', 'status' => 'active']);
    $second = ($this->staff)('taken@clinic.test');

    expect($this->linker->link($this->clinic->id))->toBe(0)
        ->and($second->fresh()->user_id)->toBeNull()
        ->and(fn () => $second->update(['user_id' => $user->id]))->toThrow(QueryException::class);
});

it('is idempotent: a second run links nothing and records nothing more', function () {
    ($this->login)('twice@clinic.test', $this->clinic);
    ($this->staff)('twice@clinic.test');

    $this->linker->link($this->clinic->id);
    $events = SecurityAuditLog::count();

    expect($this->linker->link($this->clinic->id))->toBe(0)
        ->and(SecurityAuditLog::count())->toBe($events);
});

it('reports without changing anything unless asked to link', function () {
    ($this->login)('report@clinic.test', $this->clinic);
    $employee = ($this->staff)('report@clinic.test');

    $this->artisan('clinic:staff-audit', ['--clinic' => [$this->clinic->id]])
        ->expectsOutputToContain('Report only. Nothing changed.')
        ->assertSuccessful();
    expect($employee->fresh()->user_id)->toBeNull();

    $this->artisan('clinic:staff-audit', ['--clinic' => [$this->clinic->id], '--link' => true])
        ->expectsOutputToContain('Linked 1 staff record(s). Roles were not changed.')
        ->assertSuccessful();
    expect($employee->fresh()->user_id)->not->toBeNull();
});

it('can never see two candidate staff records for one login: a clinic holds each email once', function () {
    ($this->staff)('dup@clinic.test');

    expect(fn () => ($this->staff)('dup@clinic.test'))->toThrow(QueryException::class);
});

it('reports every staff record it did not link, with the reason', function () {
    ($this->login)('elsewhere@clinic.test', $this->otherClinic);
    $conflict = ($this->staff)('elsewhere@clinic.test');
    $noLogin = ($this->staff)('nobody@clinic.test');

    $report = $this->linker->report($this->clinic->id);

    expect($report['unresolved'])->toBe([['employee_id' => $conflict->id, 'reason' => 'a login with this email has no membership in this clinic']])
        ->and($report['skipped'])->toBe([['employee_id' => $noLogin->id, 'reason' => 'no login uses this email']]);
});
