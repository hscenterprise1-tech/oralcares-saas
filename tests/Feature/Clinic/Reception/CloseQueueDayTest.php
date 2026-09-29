<?php

use App\AppointmentStatus;
use App\Models\ClinicOperatingHour;
use App\Models\ClinicQueue;
use App\QueueItemStatus;
use App\Services\QueueStateMachine;
use App\Services\Reception\ReceptionCheckIn;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

/**
 * 2026-09-28 is a Monday. The branch runs a split shift: 09:00–13:00 and
 * 17:00–21:00.
 */
beforeEach(function () {
    $this->f = ReceptionFixture::make();

    foreach ([['09:00:00', '13:00:00'], ['17:00:00', '21:00:00']] as [$open, $close]) {
        ClinicOperatingHour::create(['clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id,
            'day_of_week' => 1, 'open_time' => $open, 'close_time' => $close, 'is_closed' => false]);
    }

    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'Asia/Kolkata'));
    $this->waiting = app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $this->f->branch, $this->f->patient('Still', 'Waiting'));
    $this->noShow = $this->f->appointment($this->f->patient('Never', 'Came'), $this->f->doctorA, CarbonImmutable::parse('2026-09-28 18:00'));
});

function closeDayAt(string $localTime): void
{
    test()->travelTo(CarbonImmutable::parse("2026-09-28 {$localTime}", 'Asia/Kolkata'));
    test()->artisan('queue:close-day')->assertSuccessful();
}

test('the afternoon break of a split shift does not close the day', function () {
    closeDayAt('14:30');

    expect(ClinicQueue::sole()->closed_at)->toBeNull()
        ->and($this->waiting->fresh()->status)->toBe(QueueItemStatus::Waiting)
        ->and($this->noShow->fresh()->status)->toBe(AppointmentStatus::Scheduled);
});

test('once the last shift ends the queue closes, waiting patients leave and missed bookings become no-shows', function () {
    closeDayAt('21:05');

    expect(ClinicQueue::sole()->closed_at)->not->toBeNull()
        ->and($this->waiting->fresh()->status)->toBe(QueueItemStatus::Cancelled)
        ->and($this->waiting->histories()->where('action', 'cancel')->value('notes'))->toBe('Day closed')
        ->and($this->noShow->fresh()->status)->toBe(AppointmentStatus::NoShow);
});

test('a patient still with the doctor keeps the day open until they are done', function () {
    app(QueueStateMachine::class)->start($this->waiting);

    closeDayAt('21:05');

    expect(ClinicQueue::sole()->closed_at)->toBeNull()
        ->and($this->waiting->fresh()->status)->toBe(QueueItemStatus::InConsultation);
});

test('a day left open overnight is closed the next day, completing anyone left in consultation', function () {
    app(QueueStateMachine::class)->start($this->waiting);

    test()->travelTo(CarbonImmutable::parse('2026-09-29 08:00', 'Asia/Kolkata'));
    test()->artisan('queue:close-day')->assertSuccessful();

    expect(ClinicQueue::sole()->closed_at)->not->toBeNull()
        ->and($this->waiting->fresh()->status)->toBe(QueueItemStatus::Completed)
        ->and($this->waiting->histories()->where('action', 'complete')->value('notes'))->toBe('Completed by the system: still in consultation when the day ended');
});

test('a branch marked closed for the day is closed at once', function () {
    ClinicOperatingHour::where('branch_id', $this->f->branch->id)->update(['is_closed' => true]);

    closeDayAt('10:30');

    expect(ClinicQueue::sole()->closed_at)->not->toBeNull();
});

test('a branch with no operating hours is only closed after the day is over', function () {
    ClinicOperatingHour::where('branch_id', $this->f->branch->id)->delete();

    closeDayAt('23:30');
    expect(ClinicQueue::sole()->closed_at)->toBeNull();

    test()->travelTo(CarbonImmutable::parse('2026-09-29 00:15', 'Asia/Kolkata'));
    test()->artisan('queue:close-day')->assertSuccessful();
    expect(ClinicQueue::sole()->closed_at)->not->toBeNull();
});

test('running the close twice changes nothing more', function () {
    closeDayAt('21:05');
    $closedAt = ClinicQueue::sole()->closed_at;

    closeDayAt('21:20');

    expect(ClinicQueue::sole()->closed_at->equalTo($closedAt))->toBeTrue()
        ->and($this->waiting->histories()->where('action', 'cancel')->count())->toBe(1);
});
