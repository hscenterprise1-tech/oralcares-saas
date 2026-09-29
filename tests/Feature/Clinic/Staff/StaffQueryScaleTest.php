<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\Practitioner;
use App\Models\PractitionerSchedule;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\Reception\DoctorRoster;
use App\UserType;
use Carbon\CarbonImmutable;
use Database\Seeders\SystemRoleTemplateSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Staff screens must cost the same number of queries for a clinic with 3
 * staff as for one with 30: no per-employee role, branch, profile or
 * schedule lookups.
 */
beforeEach(function () {
    $this->seed(SystemRoleTemplateSeeder::class);
    $this->clinic = Clinic::factory()->create();
    $this->branch = Branch::factory()->create(['clinic_id' => $this->clinic->id, 'is_main' => true, 'status' => BranchStatus::Active]);

    $owner = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->primaryOwner()->create(['clinic_id' => $this->clinic->id, 'user_id' => $owner->id, 'status' => ClinicMembershipStatus::Active]);
    $this->owner = $owner;
    $this->session = [ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $this->branch->id];

    /** Staff with a login, a clinic role, a branch, a practitioner profile and a schedule. */
    $this->addStaff = function (int $count): void {
        foreach (range(1, $count) as $i) {
            $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
            ClinicMembership::factory()->create(['clinic_id' => $this->clinic->id, 'user_id' => $user->id, 'status' => ClinicMembershipStatus::Active])
                ->assignedBranches()->attach($this->branch->id);
            $employee = ClinicEmployee::create(['clinic_id' => $this->clinic->id, 'user_id' => $user->id, 'name' => "Dr. {$user->id}", 'email' => "doc{$user->id}@clinic.test", 'status' => 'active']);
            $employee->branches()->attach($this->branch->id);
            app(ClinicRoleAssignmentService::class)->syncRoles($user, [ClinicRole::Doctor->value], [$this->branch]);
            Practitioner::create(['clinic_employee_id' => $employee->id, 'specialty' => 'Dentist']);
            PractitionerSchedule::create(['clinic_employee_id' => $employee->id, 'branch_id' => $this->branch->id, 'day_of_week' => 1, 'start_time' => '09:00:00', 'end_time' => '13:00:00']);
        }
    };

    $this->countQueries = function (callable $work): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $work();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
});

it('lists staff with the same number of queries however many there are', function () {
    ($this->addStaff)(3);
    // One request first, so one-off costs (permission cache) are not counted.
    $this->actingAs($this->owner)->withSession($this->session)->get(route('admin.employees.index'));
    $few = ($this->countQueries)(fn () => $this->actingAs($this->owner)->withSession($this->session)->get(route('admin.employees.index'))->assertOk());

    ($this->addStaff)(27);
    $many = ($this->countQueries)(fn () => $this->actingAs($this->owner)->withSession($this->session)->get(route('admin.employees.index'))->assertOk());

    expect($many)->toBe($few);
});

it('builds the reception doctor roster with the same number of queries however many doctors there are', function () {
    $monday = CarbonImmutable::parse('2026-09-28 10:00');

    ($this->addStaff)(3);
    $few = ($this->countQueries)(fn () => app(DoctorRoster::class)->forBranch($this->clinic, $this->branch, $monday));

    ($this->addStaff)(27);
    $many = ($this->countQueries)(fn () => app(DoctorRoster::class)->forBranch($this->clinic, $this->branch, $monday));

    expect($many)->toBe($few);
});
