<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\SecurityAuditLog;
use App\Models\User;
use App\Services\ClinicContext;
use App\UserType;
use Illuminate\Support\Facades\DB;

/**
 * Branch access for a login lives on the membership (`branch_clinic_membership`).
 * It must follow the branches an admin sets on the staff record, never reach
 * another clinic's branch, and fail closed when there is none.
 */
beforeEach(function () {
    $this->clinic = Clinic::factory()->create();
    $this->main = Branch::factory()->create(['clinic_id' => $this->clinic->id, 'name' => 'Main', 'is_main' => true, 'status' => BranchStatus::Active]);
    $this->east = Branch::factory()->create(['clinic_id' => $this->clinic->id, 'name' => 'East', 'status' => BranchStatus::Active]);
    $this->foreignBranch = Branch::factory()->create(['clinic_id' => Clinic::factory()->create()->id, 'status' => BranchStatus::Active]);

    $this->owner = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $this->ownerMembership = ClinicMembership::factory()->primaryOwner()->create([
        'clinic_id' => $this->clinic->id, 'user_id' => $this->owner->id, 'status' => ClinicMembershipStatus::Active,
    ]);

    $this->staffUser = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $this->staffMembership = ClinicMembership::factory()->create([
        'clinic_id' => $this->clinic->id, 'user_id' => $this->staffUser->id,
        'has_all_branches_access' => false, 'status' => ClinicMembershipStatus::Active,
    ]);
    $this->employee = ClinicEmployee::create([
        'clinic_id' => $this->clinic->id, 'user_id' => $this->staffUser->id,
        'name' => 'Priya Staff', 'email' => 'priya@example.test', 'status' => 'active',
    ]);

    $this->asOwner = fn () => $this->actingAs($this->owner)->withSession([
        ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->ownerMembership->id,
        ClinicContext::SESSION_BRANCH_ID => $this->main->id,
    ]);
    $this->updateEmployee = fn (array $branches) => ($this->asOwner)()->put(route('admin.employees.update', $this->employee->id), [
        'name' => 'Priya Staff', 'email' => 'priya@example.test', 'status' => 'active', 'branches' => $branches,
    ]);
});

it('gives a member with no branch access no branch, no branch figures, and refuses a switch into one', function () {
    $session = [ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->staffMembership->id];

    $this->actingAs($this->staffUser)->withSession($session)->get(route('clinic.dashboard'))->assertForbidden();

    $this->actingAs($this->staffUser)->withSession($session)
        ->post(route('clinic.branches.switch'), ['branch_id' => $this->main->id])
        ->assertForbidden();
});

it('moves a member\'s branch access when an admin changes their staff branches', function () {
    ($this->updateEmployee)([$this->main->id, $this->east->id])->assertRedirect(route('admin.employees.index'));
    expect($this->staffMembership->assignedBranches()->pluck('branches.id')->sort()->values()->all())
        ->toBe(collect([$this->main->id, $this->east->id])->sort()->values()->all());

    ($this->updateEmployee)([$this->east->id]);
    expect($this->staffMembership->assignedBranches()->pluck('branches.id')->all())->toBe([$this->east->id]);

    $session = [ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->staffMembership->id];
    $this->actingAs($this->staffUser)->withSession($session)
        ->post(route('clinic.branches.switch'), ['branch_id' => $this->main->id])
        ->assertForbidden();

    $revoke = SecurityAuditLog::where('event', 'access.branches_changed')->latest('id')->first();
    expect($revoke->context['revoked'])->toBe([$this->main->id])
        ->and($revoke->actor_user_id)->toBe($this->owner->id);
});

it('refuses to put a member on another clinic\'s branch', function () {
    ($this->updateEmployee)([$this->main->id, $this->foreignBranch->id])->assertSessionHasErrors('branches.1');

    expect($this->staffMembership->assignedBranches()->count())->toBe(0)
        ->and($this->employee->branches()->count())->toBe(0);
});

it('leaves an all-branches member\'s access alone when their staff branches change', function () {
    $this->staffMembership->update(['has_all_branches_access' => true]);

    ($this->updateEmployee)([$this->east->id]);

    expect($this->staffMembership->assignedBranches()->count())->toBe(0);

    $this->actingAs($this->staffUser)
        ->withSession([ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->staffMembership->id, ClinicContext::SESSION_BRANCH_ID => $this->east->id])
        ->post(route('clinic.branches.switch'), ['branch_id' => $this->main->id])
        ->assertSessionHas(ClinicContext::SESSION_BRANCH_ID, $this->main->id);
});

it('backfills missing access only from the member\'s own staff branches in the same clinic', function () {
    DB::table('clinic_employee_branches')->insert([
        ['clinic_employee_id' => $this->employee->id, 'branch_id' => $this->east->id],
        ['clinic_employee_id' => $this->employee->id, 'branch_id' => $this->foreignBranch->id],
        ['clinic_employee_id' => 999999, 'branch_id' => $this->main->id],
    ]);

    $noEvidenceUser = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $noEvidence = ClinicMembership::factory()->create([
        'clinic_id' => $this->clinic->id, 'user_id' => $noEvidenceUser->id, 'has_all_branches_access' => false,
    ]);

    (require database_path('migrations/2026_09_29_102048_backfill_membership_branch_access_from_employee_branches.php'))->up();

    expect($this->staffMembership->assignedBranches()->pluck('branches.id')->all())->toBe([$this->east->id])
        ->and($noEvidence->assignedBranches()->count())->toBe(0)
        ->and($this->ownerMembership->assignedBranches()->count())->toBe(0);
});
