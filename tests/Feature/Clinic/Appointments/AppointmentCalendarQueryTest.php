<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\Patient;
use App\Models\User;
use App\Services\ClinicContext;
use App\UserType;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * The calendar's `date`/`view`/`practitioner_ids` query params are the
 * whole contract the rebuilt frontend relies on (see the URL-is-the-only-
 * source-of-truth architecture in `resources/js/pages/Clinic/Appointments`)
 * — these lock that contract in, including the `practitioner_ids` type
 * bug (query-string values arrive as strings; a stray strict `in_array()`
 * comparison against the model's int ids silently filtered every doctor
 * out) found and fixed while rebuilding the calendar.
 */
beforeEach(function () {
    $this->clinic = Clinic::factory()->create(['name' => 'Calendar Query Clinic', 'slug' => 'calendar-query-clinic']);
    $this->branch = Branch::factory()->create([
        'clinic_id' => $this->clinic->id, 'name' => 'Main Branch', 'is_main' => true, 'status' => BranchStatus::Active,
    ]);

    $this->user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $this->membership = ClinicMembership::factory()->create([
        'clinic_id' => $this->clinic->id, 'user_id' => $this->user->id,
        'is_primary_owner' => true, 'has_all_branches_access' => true, 'status' => ClinicMembershipStatus::Active,
    ]);

    $this->doctorA = ClinicEmployee::create([
        'clinic_id' => $this->clinic->id, 'name' => 'Dr. A', 'email' => 'dr.a@calendar-query.test', 'status' => 'active',
    ]);
    $this->doctorB = ClinicEmployee::create([
        'clinic_id' => $this->clinic->id, 'name' => 'Dr. B', 'email' => 'dr.b@calendar-query.test', 'status' => 'active',
    ]);

    $this->patient = Patient::create([
        'clinic_id' => $this->clinic->id, 'first_name' => 'Test', 'last_name' => 'Patient',
        'phone' => '9876500002', 'gender' => 'male', 'uhid' => 'UHID-CQ-01',
    ]);

    $this->session = [
        ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
        ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
    ];
});

function makeCalendarAppointment(TestCase $test, ClinicEmployee $doctor, Carbon $at): Appointment
{
    return Appointment::create([
        'clinic_id' => $test->clinic->id, 'branch_id' => $test->branch->id, 'patient_id' => $test->patient->id,
        'practitioner_id' => $doctor->id, 'scheduled_at' => $at, 'duration_minutes' => 30, 'status' => 'scheduled',
    ]);
}

test('view=week returns appointments across the whole week, not just the requested day', function () {
    $monday = now()->startOfWeek(Carbon::MONDAY);
    makeCalendarAppointment($this, $this->doctorA, $monday->copy()->setTime(9, 0));
    makeCalendarAppointment($this, $this->doctorA, $monday->copy()->addDays(4)->setTime(11, 0)); // Friday
    makeCalendarAppointment($this, $this->doctorA, $monday->copy()->addDays(9)->setTime(11, 0)); // next week — must NOT appear

    $response = $this->actingAs($this->user)->withSession($this->session)
        ->get('/clinic/appointments?'.http_build_query(['date' => $monday->toDateString(), 'view' => 'week']));

    $response->assertInertia(fn ($page) => $page
        ->where('view', 'week')
        ->has('appointments', 2)
    );
});

test('view=month returns appointments across the whole visible month grid', function () {
    $monthStart = now()->startOfMonth();
    makeCalendarAppointment($this, $this->doctorA, $monthStart->copy()->setTime(9, 0));
    makeCalendarAppointment($this, $this->doctorA, $monthStart->copy()->endOfMonth()->setTime(9, 0));

    $response = $this->actingAs($this->user)->withSession($this->session)
        ->get('/clinic/appointments?'.http_build_query(['date' => $monthStart->toDateString(), 'view' => 'month']));

    $response->assertInertia(fn ($page) => $page
        ->where('view', 'month')
        ->has('appointments', 2)
    );
});

test('filtering to one practitioner via a string query value returns only their appointments', function () {
    $today = now();
    makeCalendarAppointment($this, $this->doctorA, $today->copy()->setTime(9, 0));
    makeCalendarAppointment($this, $this->doctorB, $today->copy()->setTime(10, 0));

    // `practitioner_ids[]=<id>` — exactly how a browser's query string sends
    // it: a string, not an int. This is the exact shape that silently broke
    // under the old strict `in_array()` comparison.
    $response = $this->actingAs($this->user)->withSession($this->session)
        ->get('/clinic/appointments?date='.$today->toDateString().'&practitioner_ids[]='.(string) $this->doctorA->id);

    $response->assertInertia(fn ($page) => $page
        ->has('practitioners', 1)
        ->where('practitioners.0.id', $this->doctorA->id)
        ->has('appointments', 1)
        ->where('appointments.0.practitioner_id', $this->doctorA->id)
    );
});

test('an empty practitioner filter shows every doctor, not none', function () {
    $today = now();
    makeCalendarAppointment($this, $this->doctorA, $today->copy()->setTime(9, 0));
    makeCalendarAppointment($this, $this->doctorB, $today->copy()->setTime(10, 0));

    $response = $this->actingAs($this->user)->withSession($this->session)
        ->get('/clinic/appointments?date='.$today->toDateString());

    $response->assertInertia(fn ($page) => $page
        ->has('practitioners', 2)
        ->has('appointments', 2)
    );
});
