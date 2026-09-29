<?php

use App\AppointmentStatus;
use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\ClinicOperatingHour;
use App\Models\DoctorAvailability;
use App\Models\Leave;
use App\Models\Patient;
use App\Models\Practitioner;
use App\Models\User;
use App\Services\ClinicContext;
use App\UserType;
use Tests\TestCase;

beforeEach(function () {
    $this->clinic = Clinic::factory()->create(['name' => 'Conflict Test Clinic', 'slug' => 'conflict-test']);
    $this->branch = Branch::factory()->create([
        'clinic_id' => $this->clinic->id,
        'name' => 'Main Branch',
        'is_main' => true,
        'status' => BranchStatus::Active,
    ]);

    $this->user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $this->membership = ClinicMembership::factory()->create([
        'clinic_id' => $this->clinic->id,
        'user_id' => $this->user->id,
        'is_primary_owner' => true,
        'has_all_branches_access' => true,
        'status' => ClinicMembershipStatus::Active,
    ]);

    $this->doctor = ClinicEmployee::create([
        'clinic_id' => $this->clinic->id, 'name' => 'Dr. Conflict', 'email' => 'dr.conflict@example.com', 'status' => 'active',
    ]);
    $this->practitioner = Practitioner::create([
        'clinic_id' => $this->clinic->id, 'organization_id' => $this->clinic->organization_id,
        'clinic_employee_id' => $this->doctor->id, 'specialty' => 'Dentist', 'is_active' => true,
    ]);

    $this->patient = Patient::create([
        'clinic_id' => $this->clinic->id, 'first_name' => 'Test', 'last_name' => 'Patient',
        'phone' => '9876500001', 'gender' => 'male', 'uhid' => 'UHID-CONFLICT-01',
    ]);

    $this->session = [
        ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
        ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
    ];
});

function bookAppointment(TestCase $test, string $scheduledAt, array $overrides = [])
{
    return $test->actingAs($test->user)->withSession($test->session)->post('/clinic/appointments', array_merge([
        'patient_id' => $test->patient->id,
        'practitioner_id' => $test->doctor->id,
        'scheduled_at' => $scheduledAt,
        'duration_minutes' => 30,
        'appointment_type' => 'consultation',
    ], $overrides));
}

test('booking outside branch operating hours is rejected', function () {
    ClinicOperatingHour::create([
        'clinic_id' => $this->clinic->id, 'branch_id' => $this->branch->id,
        'day_of_week' => now()->addDay()->dayOfWeek, 'open_time' => '09:00:00', 'close_time' => '17:00:00', 'is_closed' => false,
    ]);

    $response = bookAppointment($this, now()->addDay()->setTime(20, 0)->toDateTimeString());

    $response->assertSessionHasErrors(['scheduled_at']);
    expect(Appointment::where('patient_id', $this->patient->id)->exists())->toBeFalse();
});

test('booking on a day the branch is closed is rejected', function () {
    ClinicOperatingHour::create([
        'clinic_id' => $this->clinic->id, 'branch_id' => $this->branch->id,
        'day_of_week' => now()->addDay()->dayOfWeek, 'open_time' => '09:00:00', 'close_time' => '17:00:00', 'is_closed' => true,
    ]);

    $response = bookAppointment($this, now()->addDay()->setTime(11, 0)->toDateTimeString());

    $response->assertSessionHasErrors(['scheduled_at']);
});

test('booking a doctor during their approved leave is rejected', function () {
    Leave::create([
        'clinic_id' => $this->clinic->id, 'employee_id' => $this->doctor->id, 'leave_type' => 'sick',
        'status' => 'approved', 'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDay()->toDateString(),
    ]);

    $response = bookAppointment($this, now()->addDay()->setTime(11, 0)->toDateTimeString());

    $response->assertSessionHasErrors(['scheduled_at']);
});

test('a pending (not yet approved) leave does not block booking', function () {
    Leave::create([
        'clinic_id' => $this->clinic->id, 'employee_id' => $this->doctor->id, 'leave_type' => 'sick',
        'status' => 'requested', 'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDay()->toDateString(),
    ]);

    $response = bookAppointment($this, now()->addDay()->setTime(11, 0)->toDateTimeString());

    $response->assertRedirect();
    expect(Appointment::where('patient_id', $this->patient->id)->exists())->toBeTrue();
});

test('booking outside a doctors configured availability window is rejected', function () {
    $dayOfWeek = now()->addDay()->dayOfWeek === 0 ? 7 : now()->addDay()->dayOfWeek;
    // `organization_id` is auto-stamped from the acting user by
    // `OrganizationScoped` and isn't in `$fillable` — `forceCreate()` sets it
    // directly rather than depending on an auth side effect in the fixture.
    DoctorAvailability::forceCreate([
        'organization_id' => $this->clinic->organization_id,
        'branch_id' => $this->branch->id, 'doctor_profile_id' => $this->practitioner->id,
        'day_of_week' => $dayOfWeek, 'start_time' => '09:00:00', 'end_time' => '13:00:00', 'is_available' => true,
    ]);

    $response = bookAppointment($this, now()->addDay()->setTime(15, 0)->toDateTimeString());

    $response->assertSessionHasErrors(['scheduled_at']);
});

test('a doctor with no availability rows configured is not blocked by the availability check', function () {
    $response = bookAppointment($this, now()->addDay()->setTime(15, 0)->toDateTimeString());

    $response->assertRedirect();
    expect(Appointment::where('patient_id', $this->patient->id)->exists())->toBeTrue();
});

test('a doctor cannot be double-booked for an overlapping time', function () {
    $start = now()->addDay()->setTime(11, 0);
    Appointment::create([
        'clinic_id' => $this->clinic->id, 'branch_id' => $this->branch->id, 'patient_id' => $this->patient->id,
        'practitioner_id' => $this->doctor->id, 'scheduled_at' => $start, 'duration_minutes' => 30,
        'status' => AppointmentStatus::Scheduled->value,
    ]);

    // Overlaps: existing 11:00-11:30, new request 11:15-11:45.
    $response = bookAppointment($this, $start->copy()->addMinutes(15)->toDateTimeString());

    $response->assertSessionHasErrors(['scheduled_at']);
});

test('booking immediately after an existing appointment ends is not treated as a conflict', function () {
    $start = now()->addDay()->setTime(11, 0);
    Appointment::create([
        'clinic_id' => $this->clinic->id, 'branch_id' => $this->branch->id, 'patient_id' => $this->patient->id,
        'practitioner_id' => $this->doctor->id, 'scheduled_at' => $start, 'duration_minutes' => 30,
        'status' => AppointmentStatus::Scheduled->value,
    ]);

    // Existing ends at 11:30; new request starts exactly then.
    $response = bookAppointment($this, $start->copy()->addMinutes(30)->toDateTimeString());

    $response->assertRedirect();
});

test('a cancelled appointment does not block a new booking at the same time', function () {
    $start = now()->addDay()->setTime(11, 0);
    Appointment::create([
        'clinic_id' => $this->clinic->id, 'branch_id' => $this->branch->id, 'patient_id' => $this->patient->id,
        'practitioner_id' => $this->doctor->id, 'scheduled_at' => $start, 'duration_minutes' => 30,
        'status' => AppointmentStatus::Cancelled->value,
    ]);

    $response = bookAppointment($this, $start->toDateTimeString());

    $response->assertRedirect();
});
