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
use App\Support\ClinicClock;
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

test('Today shows a practitioner their own patients: booked with them today and assigned to them', function () {
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
        'clinic_id' => $this->clinic->id, 'branch_id' => $this->branch->id, 'patient_id' => $myPatient->id, 'practitioner_id' => $employee->id,
        // Appointments store clinic wall-clock time, as Today reads it.
        'scheduled_at' => ClinicClock::now(), 'status' => 'scheduled',
    ]);

    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $this->clinic->id, 'user_id' => $doctorUser->id, 'is_primary_owner' => true, 'has_all_branches_access' => true, 'status' => ClinicMembershipStatus::Active,
    ]);

    $response = $this->actingAs($doctorUser)->withSession([
        ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id,
        ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
    ])->get('/clinic/consultations');

    // The practitioner's own day is on Today. This practitioner also owns the clinic: in Doctor
    // Mode (the default) their home is the Calendar; in Owner Mode, the Command Center, which
    // keeps "Your patients" above the clinic's day.
    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('ClinicPortal/Today/Index', false)
        ->where('mine.booked_today', 1)
        ->where('mine.assigned', 1));

    // A doctor-owner in Doctor Mode lands on the Doctor Dashboard (Today).
    $this->get('/clinic/dashboard')->assertRedirect(route('clinic.consultations.index'));

    $this->withSession(withOwnerMode([ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $this->branch->id]))
        ->get('/clinic/dashboard')->assertOk()
        ->assertInertia(fn ($page) => $page->component('ClinicPortal/CommandCenter/Index', false)
            ->where('sections.mine.state', 'ready')
            ->where('sections.mine.figures.0.key', 'my_waiting'));
});

test('someone with no clinical profile gets no own-patients figures on Today and no My day band anywhere', function () {
    $receptionUser = User::factory()->create(['user_type' => UserType::ClinicUser, 'name' => 'Front Desk']);
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $this->clinic->id, 'user_id' => $receptionUser->id, 'is_primary_owner' => true, 'has_all_branches_access' => true, 'status' => ClinicMembershipStatus::Active,
    ]);

    $response = $this->actingAs($receptionUser)->withSession([
        ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id,
        ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
    ])->get('/clinic/dashboard');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->missing('sections.mine'));

    $this->get('/clinic/consultations')->assertOk()->assertInertia(fn ($page) => $page->where('mine', null));
});
