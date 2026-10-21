<?php

use App\AppointmentSource;
use App\AppointmentStatus;
use App\BranchStatus;
use App\ClinicRole;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\ClinicalProcedure;
use App\Models\ClinicRoom;
use App\Models\ClinicSettingAudit;
use App\Models\Leave;
use App\Models\Patient;
use App\Models\PatientVisit;
use App\Models\PractitionerSchedule;
use App\Services\ClinicContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\ReceptionFixture;

/**
 * The appointment engine end to end: the date-and-time calendar, booking
 * by service with a chosen or any available provider, automatic rooms, new
 * cases booked before registration, the conflict rules, moving, cancelling,
 * status steps, recurring series, free-slot search, leave review, settings,
 * and the branch and permission boundaries around them.
 *
 * The clock is frozen on Monday 28 Sep 2026, 10:00 clinic time.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->patient = $this->f->patient('Ritu', 'Saxena');
    [$this->reception, $this->receptionSession] = $this->f->memberWithRole(ClinicRole::Receptionist);
});

function asReception()
{
    return test()->actingAs(test()->reception)->withSession(test()->receptionSession);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function book(array $overrides = [])
{
    return asReception()->post('/clinic/appointments', array_merge([
        'patient_id' => test()->patient->id,
        'practitioner_id' => test()->f->doctorA->id,
        'scheduled_at' => '2026-09-29 10:00:00',
        'duration_minutes' => 30,
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function bookedAt(string $at, int $duration = 30, array $attributes = []): Appointment
{
    return Appointment::create(array_merge([
        'clinic_id' => test()->f->clinic->id, 'branch_id' => test()->f->branch->id, 'patient_id' => test()->patient->id,
        'practitioner_id' => test()->f->doctorA->id, 'scheduled_at' => $at, 'duration_minutes' => $duration, 'status' => 'scheduled',
    ], $attributes));
}

function service(string $name, int $minutes): ClinicalProcedure
{
    return ClinicalProcedure::create(['clinic_id' => test()->f->clinic->id, 'code' => strtoupper(substr($name, 0, 3)).$minutes, 'name' => $name, 'category' => 'General', 'default_duration_minutes' => $minutes, 'is_active' => true]);
}

/**
 * @return list<string>
 */
function freeStarts(array $query): array
{
    return collect(asReception()->getJson('/clinic/appointments/availability?'.http_build_query($query))->assertOk()->json('slots'))
        ->map(fn (array $slot) => $slot['start'].' '.$slot['provider_id'])
        ->all();
}

// ─── The calendar: date → time → appointment ────────────────

test('reception sees every provider\'s appointments in one date-and-time calendar, each naming its provider', function () {
    bookedAt('2026-09-29 10:00:00');
    bookedAt('2026-09-29 10:00:00', 30, ['practitioner_id' => $this->f->doctorB->id, 'patient_id' => $this->f->patient('Meena', 'Shah')->id]);

    asReception()->get('/clinic/appointments?view=day&date=2026-09-29')->assertInertia(fn ($page) => $page
        ->component('ClinicPortal/Appointments/Index', true)
        ->where('filters.providers', [])
        ->has('appointments', 2)
        ->where('appointments.0.provider_name', 'Dr. Asha Mehta')
        ->where('appointments.1.provider_name', 'Dr. Bala Iyer')
        ->missing('plans')
        ->missing('rooms'));
});

test('the provider filter narrows the calendar to one provider', function () {
    bookedAt('2026-09-29 10:00:00');
    bookedAt('2026-09-29 11:00:00', 30, ['practitioner_id' => $this->f->doctorB->id]);

    asReception()->get('/clinic/appointments?view=day&date=2026-09-29&providers[]='.$this->f->doctorB->id)
        ->assertInertia(fn ($page) => $page->has('appointments', 1)->where('appointments.0.provider_id', $this->f->doctorB->id));
});

test('day, week and month each load only their own range; other views fall back to week', function () {
    bookedAt('2026-09-29 10:00:00');
    bookedAt('2026-10-02 10:00:00');
    bookedAt('2026-10-20 10:00:00');

    asReception()->get('/clinic/appointments?view=day&date=2026-09-29')
        ->assertInertia(fn ($page) => $page->has('appointments', 1)->where('appointments.0.start', '2026-09-29T10:00:00')->where('range.end', '2026-09-29'));
    asReception()->get('/clinic/appointments?view=week&date=2026-09-30')
        ->assertInertia(fn ($page) => $page->has('appointments', 2)->where('range', ['start' => '2026-09-28', 'end' => '2026-10-04']));
    asReception()->get('/clinic/appointments?view=month&date=2026-10-15')
        ->assertInertia(fn ($page) => $page->has('appointments', 3)->where('range', ['start' => '2026-09-28', 'end' => '2026-11-01']));
    asReception()->get('/clinic/appointments?view=agenda&date=2026-09-30')
        ->assertInertia(fn ($page) => $page->where('view', 'week'));
});

test('search finds appointments by patient, new-case name, reference and provider', function () {
    $mine = bookedAt('2026-09-29 10:00:00');
    bookedAt('2026-09-29 11:00:00', 30, ['patient_id' => null, 'prospect_name' => 'Zubin Khan', 'prospect_phone' => '9811100001', 'practitioner_id' => $this->f->doctorB->id]);

    asReception()->get('/clinic/appointments?view=day&date=2026-09-29&q=Ritu')->assertInertia(fn ($page) => $page->has('appointments', 1)->where('appointments.0.id', $mine->id));
    asReception()->get('/clinic/appointments?view=day&date=2026-09-29&q=Zubin')->assertInertia(fn ($page) => $page->has('appointments', 1)->where('appointments.0.new_case.name', 'Zubin Khan'));
    asReception()->get('/clinic/appointments?view=day&date=2026-09-29&q=APT-'.str_pad((string) $mine->id, 5, '0', STR_PAD_LEFT))->assertInertia(fn ($page) => $page->has('appointments', 1));
    asReception()->get('/clinic/appointments?view=day&date=2026-09-29&q=Bala')->assertInertia(fn ($page) => $page->has('appointments', 1));
});

// ─── Booking: service → provider → date → free time ─────────

test('the service sets the length, and the server sets source and room', function () {
    $scaling = service('Scaling', 45);

    book(['service_id' => $scaling->id, 'duration_minutes' => null, 'source' => 'online', 'room_id' => 99])->assertSessionHasNoErrors();

    $appointment = Appointment::sole();
    expect($appointment->service_id)->toBe($scaling->id)
        ->and($appointment->duration_minutes)->toBe(45)
        ->and($appointment->source)->toBe(AppointmentSource::Reception)
        ->and($appointment->room_id)->toBeNull()
        ->and($appointment->created_by)->toBe($this->reception->id);
});

test('free times follow the service length for one provider or any provider', function () {
    $this->f->clinic->update(['appointment_config' => ['day_start' => '09:00', 'day_end' => '12:00', 'slot_minutes' => 30]]);
    $long = service('Root canal', 90);
    bookedAt('2026-09-29 10:00:00', 60);

    expect(freeStarts(['provider_id' => $this->f->doctorA->id, 'service_id' => $long->id, 'from' => '2026-09-29', 'days' => 1]))
        ->toBe([])
        ->and(freeStarts(['service_id' => $long->id, 'from' => '2026-09-29', 'days' => 1]))
        ->toBe(['2026-09-29T09:00:00 '.$this->f->doctorB->id, '2026-09-29T09:30:00 '.$this->f->doctorB->id, '2026-09-29T10:00:00 '.$this->f->doctorB->id, '2026-09-29T10:30:00 '.$this->f->doctorB->id]);
});

test('free times skip booked time, leave, the break and the past', function () {
    $this->f->clinic->update(['appointment_config' => ['day_start' => '09:00', 'day_end' => '12:00', 'slot_minutes' => 30, 'break_start' => '11:00', 'break_end' => '11:30']]);
    bookedAt('2026-09-28 10:30:00', 30);
    Leave::create(['clinic_id' => $this->f->clinic->id, 'employee_id' => $this->f->doctorA->id, 'leave_type' => 'casual', 'status' => 'approved', 'start_date' => '2026-09-29', 'end_date' => '2026-09-29']);

    expect(collect(freeStarts(['provider_id' => $this->f->doctorA->id, 'from' => '2026-09-28', 'days' => 3, 'duration_minutes' => 30]))->map(fn ($slot) => substr($slot, 0, 19))->all())
        ->toBe(['2026-09-28T11:30:00', '2026-09-30T09:00:00', '2026-09-30T09:30:00', '2026-09-30T10:00:00', '2026-09-30T10:30:00', '2026-09-30T11:30:00']);
});

test('booking a past time is refused', function () {
    book(['scheduled_at' => '2026-09-28 09:30:00'])->assertSessionHasErrors('scheduled_at');

    expect(Appointment::count())->toBe(0);
});

// ─── Rooms and chairs: assigned, never chosen ───────────────

test('the provider\'s free chair is assigned, and a booking is refused when all their chairs are taken', function () {
    $this->f->clinic->update(['patient_flow_config' => ['rooms_enabled' => true]]);
    $chair = ClinicRoom::create(['branch_id' => $this->f->branch->id, 'name' => 'Chair 1', 'key' => 'chair-1', 'type' => 'chair', 'is_active' => true]);
    $this->f->doctorA->rooms()->attach($chair->id);
    $this->f->doctorB->rooms()->attach($chair->id);

    book()->assertSessionHasNoErrors();
    expect(Appointment::sole()->room_id)->toBe($chair->id);

    book(['practitioner_id' => $this->f->doctorB->id, 'patient_id' => $this->f->patient('Other', 'Person')->id])
        ->assertSessionHasErrors(['scheduled_at' => 'Every room or chair assigned to Dr. Bala Iyer is booked at that time. Next free with Dr. Bala Iyer: Tue 29 Sep, 10:30 AM.']);

    expect(freeStarts(['provider_id' => $this->f->doctorB->id, 'from' => '2026-09-29', 'days' => 1, 'duration_minutes' => 30, 'limit' => 60]))
        ->not->toContain('2026-09-29T10:00:00 '.$this->f->doctorB->id);
});

// ─── New case and repeat ────────────────────────────────────

test('a new case is booked by name and mobile without registering a patient', function () {
    $patients = Patient::count();

    book(['patient_id' => null, 'new_case' => ['name' => 'Kavya Rao', 'phone' => '98111 22233']])->assertSessionHasNoErrors();

    $appointment = Appointment::sole();
    expect(Patient::count())->toBe($patients)
        ->and($appointment->patient_id)->toBeNull()
        ->and($appointment->prospect_name)->toBe('Kavya Rao')
        ->and($appointment->prospect_phone)->toBe('9811122233')
        ->and($appointment->appointment_type)->toBe('new_case');
});

test('a new case on a registered patient\'s mobile is stopped unless the desk confirms it is someone else', function () {
    $this->patient->update(['phone' => '9811122233']);

    book(['patient_id' => null, 'new_case' => ['name' => 'Ritu S', 'phone' => '9811122233']])->assertSessionHasErrors('new_case.phone');
    expect(Appointment::count())->toBe(0);

    book(['patient_id' => null, 'new_case' => ['name' => 'Ravi Saxena', 'phone' => '9811122233', 'not_a_duplicate' => true]])->assertSessionHasNoErrors();
    expect(Appointment::sole()->prospect_name)->toBe('Ravi Saxena');
});

test('a new case is linked to their registration when they arrive, then checked in', function () {
    $appointment = bookedAt('2026-09-28 10:30:00', 30, ['patient_id' => null, 'prospect_name' => 'Kavya Rao', 'prospect_phone' => '9811122233']);
    $registered = $this->f->patient('Kavya', 'Rao');

    asReception()->patch("/clinic/appointments/{$appointment->id}/patient", ['patient_id' => $registered->id])->assertSessionHasNoErrors();
    expect($appointment->fresh()->patient_id)->toBe($registered->id);

    asReception()->patch("/clinic/appointments/{$appointment->id}/patient", ['patient_id' => $this->patient->id])->assertSessionHasErrors('patient_id');

    asReception()->post('/clinic/reception/check-in', ['appointment_id' => $appointment->id])->assertSessionHasNoErrors();
    expect($appointment->fresh()->status)->toBe(AppointmentStatus::CheckedIn);
});

test('a repeat appointment for a registered patient collects nothing at booking', function () {
    book()->assertSessionHasNoErrors();

    $appointment = Appointment::sole();
    expect($appointment->patient_id)->toBe($this->patient->id)
        ->and($appointment->appointment_type)->toBe('consultation')
        ->and(PatientVisit::count())->toBe(0);
});

test('there is no walk-in source and no waitlist', function () {
    expect(AppointmentSource::tryFrom('walk_in'))->toBeNull();

    book(['source' => 'walk_in'])->assertSessionHasNoErrors();
    expect(Appointment::sole()->source)->toBe(AppointmentSource::Reception);

    asReception()->post('/clinic/appointments/waitlist', ['patient_id' => $this->patient->id])->assertNotFound();
    expect(Schema::hasTable('appointment_waitlist_entries'))->toBeFalse();
});

// ─── Conflicts ──────────────────────────────────────────────

test('a provider cannot be double-booked, and the buffer keeps time free between bookings', function () {
    bookedAt('2026-09-29 10:00:00', 30);

    book(['scheduled_at' => '2026-09-29 10:15:00'])->assertSessionHasErrors('scheduled_at');
    book(['scheduled_at' => '2026-09-29 10:30:00'])->assertSessionHasNoErrors();

    $this->f->clinic->update(['appointment_config' => ['buffer_minutes' => 10]]);
    book(['scheduled_at' => '2026-09-29 11:05:00'])->assertSessionHasErrors('scheduled_at');
    book(['scheduled_at' => '2026-09-29 11:10:00'])->assertSessionHasNoErrors();
});

test('a refused booking names the clash and the next free time', function () {
    bookedAt('2026-09-29 10:00:00', 30);

    book(['scheduled_at' => '2026-09-29 10:15:00'])
        ->assertSessionHasErrors(['scheduled_at' => 'This provider already has another appointment at that time. Next free with Dr. Asha Mehta: Tue 29 Sep, 10:30 AM.']);
});

test('a provider on approved leave cannot be booked', function () {
    Leave::create(['clinic_id' => $this->f->clinic->id, 'employee_id' => $this->f->doctorA->id, 'leave_type' => 'casual', 'status' => 'approved', 'start_date' => '2026-09-29', 'end_date' => '2026-09-30']);

    book()->assertSessionHasErrors('scheduled_at');
    expect(Appointment::count())->toBe(0);
});

test('a provider is only booked inside their working hours at the branch', function () {
    PractitionerSchedule::create(['clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'clinic_employee_id' => $this->f->doctorA->id, 'day_of_week' => 2, 'start_time' => '14:00:00', 'end_time' => '18:00:00', 'is_available' => true]);

    book(['scheduled_at' => '2026-09-29 10:00:00'])->assertSessionHasErrors('scheduled_at');
    book(['scheduled_at' => '2026-09-29 14:00:00'])->assertSessionHasNoErrors();
});

test('the clinic break, holidays and saved working days are enforced', function () {
    $this->f->clinic->update(['appointment_config' => [
        'break_start' => '13:00', 'break_end' => '14:00', 'holidays' => ['2026-10-02'], 'working_days' => [1, 2, 3, 4, 5], 'day_start' => '09:00', 'day_end' => '18:00',
    ]]);

    book(['scheduled_at' => '2026-09-29 12:45:00'])->assertSessionHasErrors('scheduled_at');
    book(['scheduled_at' => '2026-10-02 10:00:00'])->assertSessionHasErrors('scheduled_at');
    book(['scheduled_at' => '2026-10-03 10:00:00'])->assertSessionHasErrors('scheduled_at');
    book(['scheduled_at' => '2026-09-29 17:45:00'])->assertSessionHasErrors('scheduled_at');
    book(['scheduled_at' => '2026-09-29 14:00:00'])->assertSessionHasNoErrors();
});

// ─── Leave review ───────────────────────────────────────────

test('appointments hit by later approved leave are flagged for review, never moved', function () {
    $hit = bookedAt('2026-10-01 10:00:00');
    bookedAt('2026-10-01 10:00:00', 30, ['practitioner_id' => $this->f->doctorB->id]);
    Leave::create(['clinic_id' => $this->f->clinic->id, 'employee_id' => $this->f->doctorA->id, 'leave_type' => 'casual', 'status' => 'approved', 'start_date' => '2026-10-01', 'end_date' => '2026-10-01']);

    asReception()->get('/clinic/appointments?view=day&date=2026-10-01')->assertInertia(fn ($page) => $page
        ->where('appointments.0.provider_on_leave', true)
        ->where('appointments.1.provider_on_leave', false)
        ->loadDeferredProps(fn ($reload) => $reload->has('context.onLeave', 1)->where('context.onLeave.0.id', $hit->id)));

    expect($hit->fresh()->scheduled_at->format('Y-m-d H:i'))->toBe('2026-10-01 10:00')
        ->and($hit->fresh()->status)->toBe(AppointmentStatus::Scheduled);
});

// ─── Branch and provider scope ──────────────────────────────

test('a provider placed only at another branch is neither listed nor bookable here', function () {
    $east = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'East', 'status' => BranchStatus::Active]);
    $this->f->doctorB->branches()->sync([$east->id]);

    book(['practitioner_id' => $this->f->doctorB->id])->assertSessionHasErrors('practitioner_id');
    expect(freeStarts(['provider_id' => $this->f->doctorB->id, 'from' => '2026-09-29', 'days' => 1]))->toBe([]);

    asReception()->get('/clinic/appointments?view=day&date=2026-09-29')
        ->assertInertia(fn ($page) => $page->has('providers', 1)->where('providers.0.id', $this->f->doctorA->id));
});

test('another branch\'s appointments are neither listed nor reachable', function () {
    $east = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'East', 'status' => BranchStatus::Active]);
    $theirs = bookedAt('2026-09-29 10:00:00', 30, ['branch_id' => $east->id]);
    bookedAt('2026-09-29 11:00:00');

    asReception()->get('/clinic/appointments?view=day&date=2026-09-29')->assertInertia(fn ($page) => $page->has('appointments', 1));

    asReception()->patch("/clinic/appointments/{$theirs->id}/reschedule", ['scheduled_at' => '2026-09-29 15:00:00'])->assertNotFound();
    asReception()->patch("/clinic/appointments/{$theirs->id}/status", ['status' => 'confirmed'])->assertNotFound();
    asReception()->post("/clinic/appointments/{$theirs->id}/cancel")->assertNotFound();

    expect($theirs->fresh()->status)->toBe(AppointmentStatus::Scheduled);
});

test('a single-branch clinic opens straight on its only branch', function () {
    asReception()->withSession([ClinicContext::SESSION_BRANCH_ID => null] + $this->receptionSession)
        ->get('/clinic/appointments')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('branch.id', $this->f->branch->id)->where('view', 'week'));
});

// ─── Permissions ────────────────────────────────────────────

test('a doctor sees their own schedule and cannot book, move, cancel or change status', function () {
    [$user, $session] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->f->doctorB->update(['user_id' => $user->id]);
    $appointment = bookedAt('2026-09-29 10:00:00');
    $as = fn () => $this->actingAs($user)->withSession($session);

    $as()->get('/clinic/appointments')->assertOk()->assertInertia(fn ($page) => $page
        ->where('filters.providers', [$this->f->doctorB->id])
        ->where('can.create', false)->where('can.manage', false));
    $as()->post('/clinic/appointments', ['patient_id' => $this->patient->id, 'practitioner_id' => $this->f->doctorA->id, 'scheduled_at' => '2026-09-29 12:00:00'])->assertForbidden();
    $as()->patch("/clinic/appointments/{$appointment->id}/reschedule", ['scheduled_at' => '2026-09-29 15:00:00'])->assertForbidden();
    $as()->post("/clinic/appointments/{$appointment->id}/cancel")->assertForbidden();
    $as()->patch("/clinic/appointments/{$appointment->id}/status", ['status' => 'confirmed'])->assertForbidden();
    $as()->put('/clinic/appointments/settings', [])->assertForbidden();
});

test('a doctor who may book is recorded as the source', function () {
    [$user, $session] = $this->f->memberWithRole(ClinicRole::Doctor);
    Role::findByName('Doctor')->givePermissionTo('appointment.create');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->f->doctorA->update(['user_id' => $user->id]);

    $this->actingAs($user)->withSession($session)->post('/clinic/appointments', [
        'patient_id' => $this->patient->id, 'practitioner_id' => $this->f->doctorA->id, 'scheduled_at' => '2026-09-29 10:00:00',
    ])->assertSessionHasNoErrors();

    expect(Appointment::sole()->source)->toBe(AppointmentSource::Doctor);
});

// ─── Moving ─────────────────────────────────────────────────

test('an appointment is rescheduled through the same checks', function () {
    $appointment = bookedAt('2026-09-29 10:00:00');
    bookedAt('2026-09-29 12:00:00', 30, ['patient_id' => $this->f->patient('Other', 'Person')->id]);

    asReception()->patch("/clinic/appointments/{$appointment->id}/reschedule", ['scheduled_at' => '2026-09-29 12:15:00'])->assertSessionHasErrors('scheduled_at');
    asReception()->patch("/clinic/appointments/{$appointment->id}/reschedule", ['scheduled_at' => '2026-09-28 09:00:00'])->assertSessionHasErrors('scheduled_at');
    expect($appointment->fresh()->scheduled_at->format('H:i'))->toBe('10:00');

    asReception()->patch("/clinic/appointments/{$appointment->id}/reschedule", ['scheduled_at' => '2026-09-29 15:00:00', 'practitioner_id' => $this->f->doctorB->id])
        ->assertSessionHasNoErrors();

    $moved = $appointment->fresh();
    expect($moved->scheduled_at->format('Y-m-d H:i'))->toBe('2026-09-29 15:00')
        ->and($moved->duration_minutes)->toBe(30)
        ->and($moved->practitioner_id)->toBe($this->f->doctorB->id)
        ->and($moved->reschedule_count)->toBe(1);
});

test('moving into its own old time does not clash with itself', function () {
    $appointment = bookedAt('2026-09-29 10:00:00', 60);

    asReception()->patch("/clinic/appointments/{$appointment->id}/reschedule", ['scheduled_at' => '2026-09-29 10:30:00'])->assertSessionHasNoErrors();

    expect(freeStarts(['provider_id' => $this->f->doctorA->id, 'appointment_id' => $appointment->id, 'from' => '2026-09-29', 'days' => 1, 'limit' => 120]))
        ->toContain('2026-09-29T10:30:00 '.$this->f->doctorA->id);
});

// ─── Cancel, no-show, status, check-in ──────────────────────

test('cancelling records the reason and frees the slot', function () {
    $appointment = bookedAt('2026-09-29 10:00:00');

    asReception()->post("/clinic/appointments/{$appointment->id}/cancel", ['reason' => 'Patient travelling'])->assertSessionHasNoErrors();

    $cancelled = $appointment->fresh();
    expect($cancelled->status)->toBe(AppointmentStatus::Cancelled)
        ->and($cancelled->cancellation_reason)->toBe('Patient travelling')
        ->and($cancelled->cancelled_by)->toBe($this->reception->id);

    book()->assertSessionHasNoErrors();
});

test('a no-show is only recorded once the appointment time has started', function () {
    $later = bookedAt('2026-09-28 11:00:00');
    $earlier = bookedAt('2026-09-28 09:00:00', 15, ['patient_id' => $this->f->patient('Early', 'Bird')->id]);

    asReception()->patch("/clinic/appointments/{$later->id}/status", ['status' => 'no_show'])->assertSessionHasErrors('status');
    asReception()->patch("/clinic/appointments/{$earlier->id}/status", ['status' => 'no_show'])->assertSessionHasNoErrors();

    expect($later->fresh()->status)->toBe(AppointmentStatus::Scheduled)
        ->and($earlier->fresh()->status)->toBe(AppointmentStatus::NoShow);
});

test('the calendar cannot jump an appointment to completed', function () {
    $appointment = bookedAt('2026-09-28 11:00:00');

    asReception()->patch("/clinic/appointments/{$appointment->id}/status", ['status' => 'completed'])->assertSessionHasErrors('status');

    expect($appointment->fresh()->status)->toBe(AppointmentStatus::Scheduled);
});

test('a confirmed appointment is checked in through Reception and shows as waiting', function () {
    $appointment = bookedAt('2026-09-28 10:30:00');
    asReception()->patch("/clinic/appointments/{$appointment->id}/status", ['status' => 'confirmed'])->assertSessionHasNoErrors();
    expect($appointment->fresh()->confirmed_at)->not->toBeNull();

    asReception()->post('/clinic/reception/check-in', ['appointment_id' => $appointment->id])->assertSessionHasNoErrors();

    expect($appointment->fresh()->status)->toBe(AppointmentStatus::CheckedIn);
    asReception()->get('/clinic/appointments?view=day&date=2026-09-28')
        ->assertInertia(fn ($page) => $page->where('appointments.0.display_status', 'waiting')->where('kpis.waiting', 1));
});

// ─── Recurring ──────────────────────────────────────────────

test('a weekly series books every occurrence, or none when one clashes', function () {
    bookedAt('2026-10-13 10:00:00', 30, ['patient_id' => $this->f->patient('Busy', 'Slot')->id]);

    book(['recurrence' => ['frequency' => 'weekly', 'occurrences' => 4]])->assertSessionHasErrors('scheduled_at');
    expect(session('errors')->first('scheduled_at'))->toStartWith('Tue 13 Oct: ');
    expect(Appointment::where('patient_id', $this->patient->id)->count())->toBe(0);

    book(['scheduled_at' => '2026-09-29 11:00:00', 'recurrence' => ['frequency' => 'weekly', 'occurrences' => 4]])->assertSessionHasNoErrors();

    $series = Appointment::where('patient_id', $this->patient->id)->orderBy('scheduled_at')->get();
    expect($series)->toHaveCount(4)
        ->and($series->pluck('appointment_series_id')->unique())->toHaveCount(1)
        ->and($series->last()->scheduled_at->format('Y-m-d H:i'))->toBe('2026-10-20 11:00')
        ->and($series->first()->source)->toBe(AppointmentSource::Recurring);
});

test('cancelling "this and following" leaves the earlier occurrences', function () {
    book(['recurrence' => ['frequency' => 'weekly', 'occurrences' => 4]])->assertSessionHasNoErrors();
    $series = Appointment::orderBy('scheduled_at')->get();

    asReception()->post("/clinic/appointments/{$series[2]->id}/cancel", ['scope' => 'following'])->assertSessionHasNoErrors();

    expect(Appointment::orderBy('scheduled_at')->pluck('status')->map->value->all())
        ->toBe(['scheduled', 'scheduled', 'cancelled', 'cancelled']);
});

// ─── Side panel and settings ────────────────────────────────

test('the side panel reports each provider\'s state and next free slot', function () {
    bookedAt('2026-09-28 09:45:00', 30);

    asReception()->get('/clinic/appointments')->assertInertia(fn ($page) => $page
        ->loadDeferredProps(fn ($reload) => $reload
            ->where('context.providers.0.id', $this->f->doctorA->id)
            ->where('context.providers.0.state', 'busy')
            ->where('context.providers.1.state', 'available')
            ->where('context.providers.1.next_slot', '2026-09-28T10:15:00')
            ->missing('context.waitlist')
            ->missing('context.rooms')));
});

test('appointment settings are validated, saved and audited', function () {
    $valid = [
        'slot_minutes' => 20, 'default_duration_minutes' => 40, 'buffer_minutes' => 5, 'day_start' => '08:00', 'day_end' => '20:00',
        'working_days' => [1, 2, 3, 4, 5, 6], 'break_start' => '13:00', 'break_end' => '13:30', 'holidays' => ['2026-10-02'],
        'cancellation_notice_hours' => 4, 'no_show_after_minutes' => 20, 'recurring_enabled' => true,
        'max_recurring_occurrences' => 10, 'online_booking_enabled' => false,
    ];

    $this->actingAs($this->f->owner)->withSession($this->f->session)->put('/clinic/appointments/settings', ['slot_minutes' => 7] + $valid)
        ->assertSessionHasErrors('slot_minutes');
    $this->actingAs($this->f->owner)->withSession($this->f->session)->put('/clinic/appointments/settings', $valid)
        ->assertSessionHasNoErrors();

    expect($this->f->clinic->fresh()->appointment_config['slot_minutes'])->toBe(20)
        ->and(ClinicSettingAudit::where('section', 'appointments')->count())->toBe(1);
});
