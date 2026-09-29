<?php

use App\AppointmentStatus;
use App\Events\QueueItemTransitioned;
use App\Exceptions\IllegalQueueTransition;
use App\Models\ClinicQueueItem;
use App\Models\QueueHistory;
use App\QueueItemStatus;
use App\Services\QueueStateMachine;
use App\Services\Reception\ReceptionCheckIn;
use App\VisitPriority;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Tests\Support\ReceptionFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->machine = app(QueueStateMachine::class);
    $this->patient = $this->f->patient();
    $this->appointment = $this->f->appointment($this->patient, $this->f->doctorA, CarbonImmutable::parse('2026-09-28 10:15'));
    $this->item = app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $this->f->branch, $this->patient, $this->appointment);
});

test('a patient moves from waiting to called to consultation to done, with a timestamp for each step', function () {
    $this->travel(4)->minutes();
    $this->machine->call($this->item);
    $this->travel(2)->minutes();
    $this->machine->start($this->item);
    $this->travel(10)->minutes();
    $done = $this->machine->complete($this->item);

    expect($done->status)->toBe(QueueItemStatus::Completed)
        ->and($done->checked_in_at->format('H:i'))->toBe('10:00')
        ->and($done->called_at->format('H:i'))->toBe('10:04')
        ->and($done->started_at->format('H:i'))->toBe('10:06')
        ->and($done->completed_at->format('H:i'))->toBe('10:16');
});

test('every move is written to the queue history', function () {
    $this->machine->call($this->item);
    $this->machine->start($this->item);
    $this->machine->complete($this->item);

    expect(QueueHistory::where('clinic_queue_item_id', $this->item->id)->orderBy('id')->pluck('action')->all())
        ->toBe(['checked_in', 'call', 'start', 'complete']);
});

test('the appointment follows the queue', function () {
    expect($this->appointment->fresh()->status)->toBe(AppointmentStatus::CheckedIn);

    $this->machine->start($this->item);
    expect($this->appointment->fresh()->status)->toBe(AppointmentStatus::InConsultation);

    $this->machine->complete($this->item);
    expect($this->appointment->fresh()->status)->toBe(AppointmentStatus::Completed);
});

test('a doctor can take a waiting patient straight in without calling first', function () {
    $item = $this->machine->start($this->item);

    expect($item->status)->toBe(QueueItemStatus::InConsultation)
        ->and($item->called_at)->not->toBeNull();
});

test('moves that do not exist are refused and change nothing', function (array $before, string $action) {
    foreach ($before as $step) {
        $this->machine->{$step}($this->item);
    }
    $statusBefore = $this->item->fresh()->status;

    expect(fn () => $this->machine->{$action}($this->item))->toThrow(IllegalQueueTransition::class)
        ->and($this->item->fresh()->status)->toBe($statusBefore);
})->with([
    'completing a waiting patient' => [[], 'complete'],
    'calling a patient already in consultation' => [['start'], 'call'],
    'requeueing a waiting patient' => [[], 'requeue'],
    'cancelling a completed patient' => [['start', 'complete'], 'cancel'],
    'starting a cancelled patient' => [['cancel'], 'start'],
]);

test('a patient marked not present can be put back in the queue', function () {
    $this->machine->call($this->item);
    $this->machine->skip($this->item);
    $item = $this->machine->requeue($this->item);

    expect($item->status)->toBe(QueueItemStatus::Waiting)
        ->and($item->called_at)->toBeNull();
});

test('marking an emergency is logged with the change', function () {
    $item = $this->machine->setPriority($this->item, VisitPriority::Emergency);

    expect($item->priority)->toBe(VisitPriority::Emergency)
        ->and(QueueHistory::where('clinic_queue_item_id', $item->id)->where('action', 'priority_changed')->value('notes'))->toBe('normal → emergency');
});

test('each move announces itself for the display screens', function () {
    Event::fake([QueueItemTransitioned::class]);

    $this->machine->call($this->item);

    Event::assertDispatched(QueueItemTransitioned::class, fn (QueueItemTransitioned $event) => $event->action === 'call'
        && $event->fromStatus === 'waiting' && $event->toStatus === 'called' && $event->queueItem->is($this->item));
});

test('an illegal move reaches the browser as a validation message', function () {
    $response = $this->actingAs($this->f->owner)->withSession($this->f->session)
        ->patch("/clinic/reception/queue/{$this->item->id}/status", ['action' => 'complete']);

    $response->assertSessionHasErrors(['status' => 'Only a patient in consultation can be marked done.']);
    expect(ClinicQueueItem::find($this->item->id)->status)->toBe(QueueItemStatus::Waiting);
});
