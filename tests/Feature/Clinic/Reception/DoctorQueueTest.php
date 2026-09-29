<?php

use App\ClinicRole;
use App\QueueItemStatus;
use App\Services\QueueStateMachine;
use App\Services\Reception\DoctorQueue;
use App\Services\Reception\ReceptionCheckIn;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    [$this->doctorUser, $this->doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->me = $this->f->doctor('Dr. Me', $this->doctorUser);
    $this->checkIn = fn (string $first, $doctor = 'me') => app(ReceptionCheckIn::class)->checkIn(
        $this->f->clinic, $this->f->branch, $this->f->patient($first, 'Patient'), doctor: $doctor === 'me' ? $this->me : $doctor,
    );
});

test('call next calls my first waiting patient', function () {
    $first = ($this->checkIn)('First');
    $this->travel(1)->minute();
    ($this->checkIn)('Second');

    $this->actingAs($this->doctorUser)->withSession($this->doctorSession)->post('/clinic/queue/call-next')
        ->assertSessionHas('success', 'First P. has been called.');

    expect($first->fresh()->status)->toBe(QueueItemStatus::Called);
});

test('a walk-in waiting for any doctor is called by, and assigned to, whoever calls next', function () {
    $walkIn = ($this->checkIn)('Anyone', null);
    $walkIn->update(['doctor_id' => null]);

    $called = app(DoctorQueue::class)->callNext($this->f->clinic, $this->f->branch, $this->me);

    expect($called->id)->toBe($walkIn->id)
        ->and($called->fresh()->doctor_id)->toBe($this->me->id);
});

test('another doctor\'s patients are never called by me', function () {
    ($this->checkIn)('Theirs', $this->f->doctorA);

    $this->actingAs($this->doctorUser)->withSession($this->doctorSession)->post('/clinic/queue/call-next')
        ->assertSessionHasErrors(['status' => 'No one is waiting for you.']);
});

test('I finish with the current patient before calling the next', function () {
    $current = ($this->checkIn)('Current');
    ($this->checkIn)('Next');
    app(QueueStateMachine::class)->start($current);

    $this->actingAs($this->doctorUser)->withSession($this->doctorSession)->post('/clinic/queue/call-next')
        ->assertSessionHasErrors(['status' => 'Finish with Current P. first.']);
});

test('done and call next completes the current patient and calls the next in one tap', function () {
    $current = ($this->checkIn)('Current');
    $this->travel(1)->minute();
    $next = ($this->checkIn)('Next');
    app(QueueStateMachine::class)->start($current);

    $this->actingAs($this->doctorUser)->withSession($this->doctorSession)
        ->patch("/clinic/reception/queue/{$current->id}/status", ['action' => 'complete', 'then_call_next' => true])
        ->assertSessionHas('success', 'Done. Next P. has been called.');

    expect($current->fresh()->status)->toBe(QueueItemStatus::Completed)
        ->and($next->fresh()->status)->toBe(QueueItemStatus::Called);
});

test('now and next shows who is with me, who is next, and how many wait after', function () {
    $now = ($this->checkIn)('Current');
    $this->travel(1)->minute();
    ($this->checkIn)('Next');
    $this->travel(1)->minute();
    ($this->checkIn)('After');
    app(QueueStateMachine::class)->start($now);

    $card = app(DoctorQueue::class)->nowAndNext($this->f->clinic, $this->f->branch, $this->me);

    expect($card['now']['patient']['short_name'])->toBe('Current P.')
        ->and($card['next']['patient']['short_name'])->toBe('Next P.')
        ->and($card['waiting_after'])->toBe(1)
        ->and($card['called'])->toBeNull();
});
