<?php

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\Practitioner;
use App\Models\User;
use App\Services\Staff\StaffOrphanFinder;
use App\UserType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Membership, staff record and branch in one staff link always share one
 * clinic, and the database itself refuses anything else, whichever code
 * path writes it.
 */
beforeEach(function () {
    $this->clinicA = Clinic::factory()->create();
    $this->branchA = Branch::factory()->create(['clinic_id' => $this->clinicA->id]);
    $this->clinicB = Clinic::factory()->create();
    $this->branchB = Branch::factory()->create(['clinic_id' => $this->clinicB->id]);

    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $this->membershipA = ClinicMembership::factory()->create(['clinic_id' => $this->clinicA->id, 'user_id' => $user->id]);
    $this->employeeA = ClinicEmployee::create(['clinic_id' => $this->clinicA->id, 'name' => 'Dr. A', 'email' => 'a@clinic.test', 'status' => 'active']);
});

it('stores a same-clinic link with its clinic', function () {
    $this->membershipA->assignedBranches()->attach($this->branchA->id);
    $this->employeeA->branches()->attach($this->branchA->id);

    expect(DB::table('branch_clinic_membership')->value('clinic_id'))->toBe($this->clinicA->id)
        ->and(DB::table('clinic_employee_branches')->value('clinic_id'))->toBe($this->clinicA->id);
});

it('refuses clinic A membership access to a clinic B branch, however it is written', function () {
    expect(fn () => $this->membershipA->assignedBranches()->attach($this->branchB->id))->toThrow(QueryException::class)
        ->and(fn () => DB::table('branch_clinic_membership')->insert(['clinic_membership_id' => $this->membershipA->id, 'branch_id' => $this->branchB->id, 'clinic_id' => $this->clinicA->id]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('branch_clinic_membership')->insert(['clinic_membership_id' => $this->membershipA->id, 'branch_id' => $this->branchB->id, 'clinic_id' => $this->clinicB->id]))->toThrow(QueryException::class)
        ->and(fn () => $this->membershipA->syncAssignedBranches(collect([$this->branchB])))->toThrow(InvalidArgumentException::class);

    expect(DB::table('branch_clinic_membership')->count())->toBe(0);
});

it('refuses a clinic A staff placement at a clinic B branch', function () {
    expect(fn () => $this->employeeA->branches()->attach($this->branchB->id))->toThrow(QueryException::class)
        ->and(fn () => DB::table('clinic_employee_branches')->insert(['clinic_employee_id' => $this->employeeA->id, 'branch_id' => $this->branchB->id, 'clinic_id' => $this->clinicB->id]))->toThrow(QueryException::class);
});

it('refuses a clinic A practitioner schedule or profile tied to clinic B', function () {
    expect(fn () => DB::table('practitioner_schedules')->insert([
        'clinic_id' => $this->clinicA->id, 'branch_id' => $this->branchB->id, 'clinic_employee_id' => $this->employeeA->id,
        'day_of_week' => 1, 'start_time' => '09:00:00', 'end_time' => '12:00:00', 'is_available' => true,
    ]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('doctor_profiles')->insert([
            'clinic_id' => $this->clinicB->id, 'organization_id' => $this->clinicB->organization_id, 'clinic_employee_id' => $this->employeeA->id,
            'experience_years' => 0, 'is_active' => true,
        ]))->toThrow(QueryException::class);

    expect(Practitioner::withoutGlobalScopes()->count())->toBe(0);
});

it('refuses a placement for a staff record that does not exist, and removes placements with their staff record', function () {
    expect(fn () => DB::table('clinic_employee_branches')->insert(['clinic_employee_id' => 999999, 'branch_id' => $this->branchA->id, 'clinic_id' => $this->clinicA->id]))
        ->toThrow(QueryException::class);

    $this->employeeA->branches()->attach($this->branchA->id);
    $this->employeeA->delete();

    expect(DB::table('clinic_employee_branches')->count())->toBe(0);
});

it('removes only rows whose parent is gone, logs each one, and keeps real placements', function () {
    $this->employeeA->branches()->attach($this->branchA->id);

    // Rows like the ones found before the keys existed, planted with the key
    // checks deferred to commit (the test transaction never commits), which
    // is what the legacy tables allowed.
    DB::statement('PRAGMA defer_foreign_keys = ON');
    DB::table('clinic_employee_branches')->insert(['clinic_employee_id' => 999999, 'branch_id' => $this->branchA->id, 'clinic_id' => $this->clinicA->id]);
    DB::table('clinic_employee_invitations')->insert(['clinic_id' => $this->clinicA->id, 'clinic_employee_id' => 999999, 'email' => 'gone@clinic.test', 'token' => str_repeat('a', 64), 'expires_at' => now()]);

    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning')->twice()->withArgs(fn ($message, $context) => count($context['rows']) === 1
        && $context['rows'][0]['clinic_employee_id'] === 999999
        && ! array_key_exists('token', $context['rows'][0]));

    expect(app(StaffOrphanFinder::class)->remove())->toBe(2)
        ->and(DB::table('clinic_employee_branches')->pluck('clinic_employee_id')->all())->toBe([$this->employeeA->id])
        ->and(DB::table('clinic_employee_invitations')->count())->toBe(0)
        ->and(app(StaffOrphanFinder::class)->find())->toBeEmpty();
});

it('lists orphaned rows without changing them', function () {
    $this->artisan('clinic:staff-audit', ['--orphans' => true])
        ->expectsOutputToContain('0 orphaned row(s). Report only. Nothing changed.')
        ->assertSuccessful();
});
