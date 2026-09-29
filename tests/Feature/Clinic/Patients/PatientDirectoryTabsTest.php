<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\Patient;
use App\Models\Practitioner;
use App\Models\User;
use App\Services\ClinicContext;
use App\UserType;

beforeEach(function () {
    $this->clinic = Clinic::factory()->create(['name' => 'Directory Tabs Clinic', 'slug' => 'directory-tabs']);
    $this->branch = Branch::factory()->create([
        'clinic_id' => $this->clinic->id,
        'name' => 'Main Branch',
        'is_main' => true,
        'status' => BranchStatus::Active,
    ]);

    $this->mine = Patient::create([
        'clinic_id' => $this->clinic->id, 'first_name' => 'Assigned', 'last_name' => 'Patient',
        'phone' => '9876555501', 'gender' => 'male', 'uhid' => 'UHID-2026-0101',
    ]);
    $this->other = Patient::create([
        'clinic_id' => $this->clinic->id, 'first_name' => 'Unassigned', 'last_name' => 'Patient',
        'phone' => '9876555502', 'gender' => 'female', 'uhid' => 'UHID-2026-0102',
    ]);
});

test('a practitioner defaults to My Patients and only sees patients assigned to them', function () {
    $doctorUser = User::factory()->create(['user_type' => UserType::ClinicUser, 'name' => 'Dr. Kapoor']);
    $employee = ClinicEmployee::create([
        'clinic_id' => $this->clinic->id, 'user_id' => $doctorUser->id, 'name' => 'Dr. Kapoor', 'email' => 'kapoor@example.com', 'status' => 'active',
    ]);
    Practitioner::create(['clinic_id' => $this->clinic->id, 'organization_id' => $this->clinic->organization_id, 'clinic_employee_id' => $employee->id, 'specialty' => 'Dentist', 'is_active' => true]);
    $this->mine->update(['current_practitioner_id' => $employee->id]);

    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $this->clinic->id, 'user_id' => $doctorUser->id, 'is_primary_owner' => true, 'has_all_branches_access' => true, 'status' => ClinicMembershipStatus::Active,
    ]);

    $response = $this->actingAs($doctorUser)->withSession([
        ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id,
        ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
    ])->get('/clinic/patients');

    $response->assertInertia(fn ($page) => $page
        ->where('isPractitioner', true)
        ->where('filters.tab', 'mine')
        ->has('patients.data', 1)
        ->where('patients.data.0.id', $this->mine->id)
        ->where('metrics.mine', 1)
        ->where('metrics.total', 2)
    );
});

test('tab=all overrides the default My Patients tab for a practitioner', function () {
    $doctorUser = User::factory()->create(['user_type' => UserType::ClinicUser, 'name' => 'Dr. Kapoor']);
    $employee = ClinicEmployee::create([
        'clinic_id' => $this->clinic->id, 'user_id' => $doctorUser->id, 'name' => 'Dr. Kapoor', 'email' => 'kapoor@example.com', 'status' => 'active',
    ]);
    Practitioner::create(['clinic_id' => $this->clinic->id, 'organization_id' => $this->clinic->organization_id, 'clinic_employee_id' => $employee->id, 'specialty' => 'Dentist', 'is_active' => true]);
    $this->mine->update(['current_practitioner_id' => $employee->id]);

    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $this->clinic->id, 'user_id' => $doctorUser->id, 'is_primary_owner' => true, 'has_all_branches_access' => true, 'status' => ClinicMembershipStatus::Active,
    ]);

    $response = $this->actingAs($doctorUser)->withSession([
        ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id,
        ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
    ])->get('/clinic/patients?tab=all');

    $response->assertInertia(fn ($page) => $page
        ->where('filters.tab', 'all')
        ->has('patients.data', 2)
    );
});

test('a non-practitioner never sees the My Patients tab and always sees all clinic patients', function () {
    $receptionUser = User::factory()->create(['user_type' => UserType::ClinicUser, 'name' => 'Front Desk']);
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $this->clinic->id, 'user_id' => $receptionUser->id, 'is_primary_owner' => true, 'has_all_branches_access' => true, 'status' => ClinicMembershipStatus::Active,
    ]);

    $response = $this->actingAs($receptionUser)->withSession([
        ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id,
        ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
    ])->get('/clinic/patients');

    $response->assertInertia(fn ($page) => $page
        ->where('isPractitioner', false)
        ->where('filters.tab', 'all')
        ->has('patients.data', 2)
    );
});
