<?php

use App\AppointmentStatus;
use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\ClinicMembership;
use App\Models\Patient;
use App\Models\PatientVisit;
use App\Models\User;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\Patient\PatientBranchActivityRecorder;
use App\Services\Reception\ReceptionCheckIn;
use App\UserType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReceptionFixture;

/**
 * Patient 360 · Visits & History: the visits a patient made and the bookings
 * they never came for, newest day first, never counting a booking as a
 * visit; only for roles that work with visits, only the member's branches,
 * and only links to pages that exist.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->patient = $this->f->patient('Meera', 'Iyer');
    app(PatientBranchActivityRecorder::class)->record($this->f->clinic->id, $this->patient->id, $this->f->branch->id, now(), 'registration');
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->asRole = function (ClinicRole $role) {
        [$user, $session] = $this->f->memberWithRole($role);

        return fn () => $this->actingAs($user)->withSession($session);
    };
    $this->url = fn (?Patient $patient = null, int $page = 1) => '/clinic/patients/'.($patient ?? $this->patient)->id."/360/visits?page={$page}";
    $this->visit = fn (string $date, string $status = 'completed', ?int $branchId = null, ?Patient $patient = null) => PatientVisit::withoutGlobalScopes()->create([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $branchId ?? $this->f->branch->id, 'patient_id' => ($patient ?? $this->patient)->id,
        'doctor_id' => $this->f->doctorA->id, 'registration_type' => 'repeat', 'visit_date' => $date, 'visit_status' => $status, 'queue_status' => 'completed',
        'visit_reason' => 'Tooth pain',
    ]);
    $this->booking = fn (string $at, AppointmentStatus $status) => $this->f->appointment($this->patient, $this->f->doctorB, CarbonImmutable::parse($at), $status);
});

test('visits and the bookings never attended come newest day first, and a booking is never counted as a visit', function () {
    ($this->visit)('2026-10-20');
    ($this->visit)('2026-10-22', 'registered');
    ($this->booking)('2026-10-18 11:00', AppointmentStatus::NoShow);
    ($this->booking)('2026-10-15 09:30', AppointmentStatus::Scheduled);
    ($this->booking)('2026-10-25 16:00', AppointmentStatus::Cancelled);
    ($this->booking)('2026-10-30 10:00', AppointmentStatus::Scheduled);
    // Booked for today and checked in: it becomes today's visit, not a second row.
    $today = ($this->booking)('2026-10-26 09:30', AppointmentStatus::Scheduled);
    $this->actingAs($this->f->owner);
    app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $this->f->branch, $this->patient, $today);

    $json = ($this->asOwner)()->getJson(($this->url)())->assertOk()->json();

    expect(collect($json['data'])->map(fn (array $row) => [$row['kind'], $row['date'], $row['status'], $row['attended']])->all())->toBe([
        ['visit', '2026-10-26', 'checked_in', true],
        ['booking', '2026-10-25', 'cancelled', false],
        ['visit', '2026-10-22', 'registered', false],
        ['visit', '2026-10-20', 'completed', true],
        ['booking', '2026-10-18', 'missed', false],
        ['booking', '2026-10-15', 'not_attended', false],
    ])
        ->and($json['counts'])->toBe(['visits' => 2, 'not_attended' => 3])
        ->and($json['data'][0])->toMatchArray(['booked_for' => '09:30 AM', 'branch' => 'Main Branch', 'practitioner' => 'Dr. Bala Iyer'])
        ->and($json['data'][3])->toMatchArray(['reason' => 'Tooth pain', 'practitioner' => 'Dr. Asha Mehta']);
});

test('an empty history is empty, not padded', function () {
    ($this->asOwner)()->getJson(($this->url)())->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('counts', ['visits' => 0, 'not_attended' => 0]);
});

test('a long history comes a page at a time, with no row on two pages', function () {
    foreach (range(1, 17) as $day) {
        ($this->visit)(sprintf('2026-09-%02d', $day));
    }

    $first = ($this->asOwner)()->getJson(($this->url)())->assertOk()->json();
    $second = ($this->asOwner)()->getJson(($this->url)(null, 2))->assertOk()->json();

    expect($first['data'])->toHaveCount(15)->and($first['meta'])->toBe(['page' => 1, 'last_page' => 2, 'total' => 17])
        ->and($first['data'][0]['date'])->toBe('2026-09-17')
        ->and(collect($second['data'])->pluck('date')->all())->toBe(['2026-09-02', '2026-09-01']);
});

test('the desk and clinicians see visits; billing does not; the consultation is named to clinicians only and linked nowhere yet', function () {
    $visit = ($this->visit)('2026-10-24', 'in_progress');
    $this->actingAs($this->f->owner);
    app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->f->doctorA, $visit);
    ($this->booking)('2026-10-20 10:00', AppointmentStatus::NoShow);

    $reception = ($this->asRole)(ClinicRole::Receptionist)()->getJson(($this->url)())->assertOk()->json('data');
    expect($reception[0]['consultation'])->toBeNull()
        // The day in Appointments is a real page the desk may open.
        ->and($reception[1]['calendar_url'])->toBe(route('clinic.appointments.index', ['date' => '2026-10-20']));

    $doctor = ($this->asRole)(ClinicRole::Doctor)()->getJson(($this->url)())->assertOk()->json('data');
    expect($doctor[0]['consultation'])->toMatchArray(['status' => 'open', 'url' => null])
        ->and($doctor[0]['consultation']['number'])->not->toBeNull();

    ($this->asRole)(ClinicRole::BillingStaff)()->getJson(($this->url)())->assertForbidden();
});

test('another branch\'s visits stay with that branch, and another clinic sees nothing', function () {
    $north = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'North', 'is_main' => false, 'status' => BranchStatus::Active]);
    ($this->visit)('2026-10-20');
    ($this->visit)('2026-10-21', 'completed', $north->id);

    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create(['clinic_id' => $this->f->clinic->id, 'user_id' => $user->id, 'has_all_branches_access' => false, 'status' => ClinicMembershipStatus::Active]);
    DB::table('branch_clinic_membership')->insert(['clinic_id' => $this->f->clinic->id, 'clinic_membership_id' => $membership->id, 'branch_id' => $this->f->branch->id]);
    $this->f->memberWithRole(ClinicRole::Receptionist);
    app(ClinicRoleAssignmentService::class)->syncRoles($user, [ClinicRole::Receptionist->value], [$this->f->branch]);
    $asMain = fn () => $this->actingAs($user)->withSession([ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $this->f->branch->id]);

    expect(collect($asMain()->getJson(($this->url)())->assertOk()->json('data'))->pluck('branch')->all())->toBe(['Main Branch'])
        ->and(collect(($this->asOwner)()->getJson(($this->url)())->json('data'))->pluck('branch')->all())->toBe(['North', 'Main Branch']);

    $other = ReceptionFixture::make('other-clinic');
    $this->actingAs($other->owner)->withSession($other->session)->getJson(($this->url)())->assertForbidden();
});

test('the section is offered on the page to the roles that may open it', function () {
    $sections = fn ($as) => collect($as()->get("/clinic/patients/{$this->patient->id}")->viewData('page')['props']['sections'])->where('built', true)->pluck('key')->all();

    expect($sections(($this->asRole)(ClinicRole::Receptionist)))->toContain('visits')
        ->and($sections(($this->asRole)(ClinicRole::BillingStaff)))->not->toContain('visits')
        ->and(($this->asOwner)()->get("/clinic/patients/{$this->patient->id}")->viewData('page')['props']['endpoints']['visits'])
        ->toBe(route('clinic.patients.360.section', [$this->patient->id, 'visits']));
});
