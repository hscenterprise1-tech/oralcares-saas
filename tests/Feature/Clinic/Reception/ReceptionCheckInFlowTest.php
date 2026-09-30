<?php

use App\AppointmentStatus;
use App\Models\ClinicQueue;
use App\Models\ClinicQueueItem;
use App\Models\ClinicRoom;
use App\Models\Leave;
use App\Models\PractitionerSchedule;
use App\QueueItemStatus;
use App\Services\QueueStateMachine;
use App\Services\Reception\QueueBoard;
use App\Services\Reception\ReceptionCheckIn;
use App\VisitPriority;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->checkIn = app(ReceptionCheckIn::class);
});

function checkInThroughReception(array $payload)
{
    $test = test();

    return $test->actingAs($test->f->owner)->withSession($test->f->session)->post('/clinic/reception/check-in', $payload);
}

test('checking in a booked patient links the appointment and sends them to their booked doctor', function () {
    $patient = $this->f->patient('Rahul', 'Sharma');
    $appointment = $this->f->appointment($patient, $this->f->doctorB, CarbonImmutable::parse('2026-09-28 10:30'));

    $response = checkInThroughReception(['appointment_id' => $appointment->id]);

    $item = ClinicQueueItem::sole();
    $response->assertSessionHas('success', 'Rahul S. · 0001 checked in for Dr. Bala Iyer.');
    expect($item->appointment_id)->toBe($appointment->id)
        ->and($item->doctor_id)->toBe($this->f->doctorB->id)
        ->and($item->status)->toBe(QueueItemStatus::Waiting)
        ->and($item->patientVisit->caseNumber->case_number)->toBe('OPD-2026-000001')
        ->and($appointment->fresh()->status)->toBe(AppointmentStatus::CheckedIn);
});

test('a walk-in goes to their own assigned doctor when that doctor is working', function () {
    $patient = $this->f->patient(attributes: ['current_practitioner_id' => $this->f->doctorB->id]);

    checkInThroughReception(['patient_id' => $patient->id]);

    expect(ClinicQueueItem::sole()->doctor_id)->toBe($this->f->doctorB->id);
});

test('a walk-in without an available own doctor goes to the working doctor with the shortest queue', function () {
    $this->checkIn->checkIn($this->f->clinic, $this->f->branch, $this->f->patient('Waiting', 'One'), doctor: $this->f->doctorA);
    Leave::create(['clinic_id' => $this->f->clinic->id, 'employee_id' => $this->f->doctorB->id, 'leave_type' => 'casual',
        'status' => 'approved', 'start_date' => '2026-09-28', 'end_date' => '2026-09-28']);
    $third = $this->f->doctor('Dr. Chitra Rao');
    $walkIn = $this->f->patient('Neha', 'Kapoor', ['current_practitioner_id' => $this->f->doctorB->id]);

    $item = $this->checkIn->checkIn($this->f->clinic, $this->f->branch, $walkIn);

    expect($item->doctor_id)->toBe($third->id);
});

test('a doctor outside their shift is not picked for a walk-in', function () {
    PractitionerSchedule::create(['branch_id' => $this->f->branch->id, 'clinic_employee_id' => $this->f->doctorA->id,
        'day_of_week' => 1, 'start_time' => '17:00:00', 'end_time' => '21:00:00', 'is_available' => true]);

    $item = $this->checkIn->checkIn($this->f->clinic, $this->f->branch, $this->f->patient());

    expect($item->doctor_id)->toBe($this->f->doctorB->id);
});

test('a patient already in today\'s queue is not queued twice', function () {
    $patient = $this->f->patient('Anita', 'Roy');
    checkInThroughReception(['patient_id' => $patient->id]);

    checkInThroughReception(['patient_id' => $patient->id])
        ->assertSessionHasErrors(['patient_id' => "Anita R. is already in today's queue."]);

    expect(ClinicQueueItem::count())->toBe(1);
});

test('one queue is kept per branch per day', function () {
    checkInThroughReception(['patient_id' => $this->f->patient('One', 'A')->id]);
    checkInThroughReception(['patient_id' => $this->f->patient('Two', 'B')->id]);

    expect(ClinicQueue::count())->toBe(1)
        ->and(ClinicQueue::sole()->queue_date->toDateString())->toBe('2026-09-28')
        ->and(ClinicQueueItem::orderBy('position')->pluck('position')->all())->toBe([1, 2]);
});

test('no check-ins once the day\'s queue has closed', function () {
    checkInThroughReception(['patient_id' => $this->f->patient('Early', 'Bird')->id]);
    ClinicQueue::sole()->update(['closed_at' => now()]);

    checkInThroughReception(['patient_id' => $this->f->patient('Late', 'Comer')->id])
        ->assertSessionHasErrors(['patient_id' => "Today's queue at Main Branch has closed. Check-ins open again tomorrow."]);
});

test('an appointment can only be checked in on its own day', function () {
    $appointment = $this->f->appointment($this->f->patient(), $this->f->doctorA, CarbonImmutable::parse('2026-09-29 10:00'));

    checkInThroughReception(['appointment_id' => $appointment->id])
        ->assertSessionHasErrors(['appointment_id' => "Only today's appointments can be checked in."]);
});

test('an appointment that was already checked in cannot be checked in again', function () {
    $appointment = $this->f->appointment($this->f->patient(), $this->f->doctorA, CarbonImmutable::parse('2026-09-28 11:00'), AppointmentStatus::Completed);

    checkInThroughReception(['appointment_id' => $appointment->id])
        ->assertSessionHasErrors(['appointment_id' => 'This appointment has already been checked in, completed or cancelled.']);
});

test('the queue orders itself: emergencies first, on-time bookings keep their slot, everyone else by arrival', function () {
    $booked = $this->f->patient('Booked', 'OnTime');
    $late = $this->f->patient('Booked', 'Late');
    $walkIn = $this->f->patient('Walk', 'In');
    $emergency = $this->f->patient('Emer', 'Gency');
    $bookedAppointment = $this->f->appointment($booked, $this->f->doctorA, CarbonImmutable::parse('2026-09-28 10:20'));
    $lateAppointment = $this->f->appointment($late, $this->f->doctorA, CarbonImmutable::parse('2026-09-28 09:30'));

    $this->checkIn->checkIn($this->f->clinic, $this->f->branch, $walkIn, doctor: $this->f->doctorA);        // 10:00
    $this->travel(5)->minutes();
    $this->checkIn->checkIn($this->f->clinic, $this->f->branch, $late, $lateAppointment);                    // 10:05, 35 min late
    $this->travel(5)->minutes();
    $this->checkIn->checkIn($this->f->clinic, $this->f->branch, $booked, $bookedAppointment);                // 10:10, slot 10:20
    $this->travel(5)->minutes();
    $this->checkIn->checkIn($this->f->clinic, $this->f->branch, $emergency, doctor: $this->f->doctorA, priority: VisitPriority::Emergency); // 10:15

    $board = app(QueueBoard::class)->forBranch($this->f->clinic, $this->f->branch);

    expect(array_column(array_column($board['queue'], 'patient'), 'short_name'))
        ->toBe(['Emer G.', 'Walk I.', 'Booked L.', 'Booked O.']);
});

test('the board raises long waits, called-but-not-seen, late bookings and doctors on leave', function () {
    $waiting = $this->checkIn->checkIn($this->f->clinic, $this->f->branch, $this->f->patient('Long', 'Wait'), doctor: $this->f->doctorA);
    $called = $this->checkIn->checkIn($this->f->clinic, $this->f->branch, $this->f->patient('Called', 'Away'), doctor: $this->f->doctorA);
    app(QueueStateMachine::class)->call($called);
    $this->f->appointment($this->f->patient('Not', 'Here'), $this->f->doctorA, CarbonImmutable::parse('2026-09-28 10:00'));
    Leave::create(['clinic_id' => $this->f->clinic->id, 'employee_id' => $this->f->doctorB->id, 'leave_type' => 'casual',
        'status' => 'approved', 'start_date' => '2026-09-28', 'end_date' => '2026-09-28']);
    $this->f->appointment($this->f->patient('Booked', 'Onleave'), $this->f->doctorB, CarbonImmutable::parse('2026-09-28 12:00'));

    $this->travel(21)->minutes();
    $attention = collect(app(QueueBoard::class)->forBranch($this->f->clinic, $this->f->branch)['attention']);

    expect($attention->pluck('kind')->all())->toBe(['called_not_seen', 'long_wait', 'doctor_on_leave', 'late'])
        ->and($attention->firstWhere('kind', 'long_wait')['item_id'])->toBe($waiting->id)
        ->and($attention->firstWhere('kind', 'late')['detail'])->toBe('Booked 10:00, 21 min late, not checked in');
});

test('wait times come from real check-in and call times', function () {
    $first = $this->checkIn->checkIn($this->f->clinic, $this->f->branch, $this->f->patient('First', 'P'), doctor: $this->f->doctorA);
    $second = $this->checkIn->checkIn($this->f->clinic, $this->f->branch, $this->f->patient('Second', 'P'), doctor: $this->f->doctorB);
    $this->travel(10)->minutes();
    app(QueueStateMachine::class)->call($first);
    $this->travel(20)->minutes();
    app(QueueStateMachine::class)->start($second);

    $waits = app(QueueBoard::class)->forBranch($this->f->clinic, $this->f->branch)['wait_times'];

    expect($waits)->toBe(['average' => 20, 'longest' => 30, 'seen' => 2, 'by_hour' => [['hour' => 10, 'average' => 20, 'longest' => 30, 'count' => 2]]]);
});

test('with nobody seen yet there is no wait-time figure to show', function () {
    $this->checkIn->checkIn($this->f->clinic, $this->f->branch, $this->f->patient());

    expect(app(QueueBoard::class)->forBranch($this->f->clinic, $this->f->branch)['wait_times'])
        ->toBe(['average' => null, 'longest' => null, 'seen' => 0, 'by_hour' => []]);
});

test('rooms appear on the board only when the clinic uses rooms and the doctor has one', function () {
    $room = ClinicRoom::create(['branch_id' => $this->f->branch->id, 'name' => 'Chair 2', 'key' => 'chair-2', 'type' => 'chair', 'is_active' => true]);
    $this->f->doctorA->rooms()->attach($room->id);
    $this->checkIn->checkIn($this->f->clinic, $this->f->branch, $this->f->patient(), doctor: $this->f->doctorA);

    $off = app(QueueBoard::class)->forBranch($this->f->clinic, $this->f->branch);
    $this->f->clinic->update(['patient_flow_config' => ['rooms_enabled' => true]]);
    $on = app(QueueBoard::class)->forBranch($this->f->clinic->fresh(), $this->f->branch);

    expect($off['queue'][0]['room'])->toBeNull()
        ->and(collect($off['doctors'])->firstWhere('id', $this->f->doctorA->id)['room'])->toBeNull()
        ->and($on['queue'][0]['room'])->toBe('Chair 2')
        ->and(collect($on['doctors'])->firstWhere('id', $this->f->doctorA->id)['room'])->toBe('Chair 2')
        ->and(collect($on['doctors'])->firstWhere('id', $this->f->doctorB->id)['room'])->toBeNull();
});
