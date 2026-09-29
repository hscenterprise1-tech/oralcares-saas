<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\Models\Appointment;
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
    $this->clinic = Clinic::factory()->create(['name' => 'My Day Clinic', 'slug' => 'my-day-clinic']);
    $this->branch = Branch::factory()->create([
        'clinic_id' => $this->clinic->id,
        'name' => 'Main Branch',
        'is_main' => true,
        'status' => BranchStatus::Active,
    ]);
});

test('the dashboard shows a My day band with assigned counts for a practitioner', function () {
    $doctorUser = User::factory()->create(['user_type' => UserType::ClinicUser, 'name' => 'Dr. Nair']);
    $employee = ClinicEmployee::create([
        'clinic_id' => $this->clinic->id, 'user_id' => $doctorUser->id, 'name' => 'Dr. Nair', 'email' => 'nair@example.com', 'status' => 'active',
    ]);
    Practitioner::create(['clinic_id' => $this->clinic->id, 'organization_id' => $this->clinic->organization_id, 'clinic_employee_id' => $employee->id, 'specialty' => 'Dentist', 'is_active' => true]);

    $myPatient = Patient::create([
        'clinic_id' => $this->clinic->id, 'first_name' => 'Assigned', 'last_name' => 'Patient',
        'phone' => '9876577701', 'gender' => 'male', 'uhid' => 'UHID-2026-0301', 'current_practitioner_id' => $employee->id,
    ]);
    Patient::create([
        'clinic_id' => $this->clinic->id, 'first_name' => 'Someone', 'last_name' => 'Else',
        'phone' => '9876577702', 'gender' => 'female', 'uhid' => 'UHID-2026-0302',
    ]);

    Appointment::create([
        'clinic_id' => $this->clinic->id, 'branch_id' => $this->branch->id, 'patient_id' => $myPatient->id,
        'scheduled_at' => now(), 'status' => 'scheduled',
    ]);

    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $this->clinic->id, 'user_id' => $doctorUser->id, 'is_primary_owner' => true, 'has_all_branches_access' => true, 'status' => ClinicMembershipStatus::Active,
    ]);

    $response = $this->actingAs($doctorUser)->withSession([
        ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id,
        ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
    ])->get('/clinic/dashboard');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->where('myDay.today_appointments', 1)
        ->where('myDay.total_assigned', 1)
    );
});

test('the dashboard has no My day band for a user with no clinical profile', function () {
    $receptionUser = User::factory()->create(['user_type' => UserType::ClinicUser, 'name' => 'Front Desk']);
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $this->clinic->id, 'user_id' => $receptionUser->id, 'is_primary_owner' => true, 'has_all_branches_access' => true, 'status' => ClinicMembershipStatus::Active,
    ]);

    $response = $this->actingAs($receptionUser)->withSession([
        ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id,
        ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
    ])->get('/clinic/dashboard');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->where('myDay', null));
});
