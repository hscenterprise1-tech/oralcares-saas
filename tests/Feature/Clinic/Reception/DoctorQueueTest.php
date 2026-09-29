<?php

use App\ClinicRole;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\QueueHistory;
use App\QueueItemStatus;
use App\Services\QueueStateMachine;
use App\Services\Reception\DoctorQueue;
use App\Services\Reception\DoctorRoster;
use App\Services\Reception\PatientFlowConfigService;
use App\Services\Reception\QueueBoard;
use App\Services\Reception\ReceptionCheckIn;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
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

/**
 * A DoctorQueue whose board returns a snapshot taken earlier: what a doctor's
 * request read just before another doctor's request committed its claim.
 */
function doctorQueueReadingStaleBoard(Collection $snapshot): DoctorQueue
{
    $board = new class(app(DoctorRoster::class), app(PatientFlowConfigService::class)) extends QueueBoard
    {
        public Collection $snapshot;

        public function todaysItems(Clinic $clinic, Branch $branch, CarbonImmutable $now): Collection
        {
            return $this->snapshot->map(fn ($item) => $item->replicate()->forceFill(['id' => $item->id])->setRelations($item->getRelations()));
        }
    };
    $board->snapshot = $snapshot;

    return new DoctorQueue($board, app(QueueStateMachine::class));
}

test('two doctors calling at the same moment cannot both claim the same walk-in', function () {
    $walkIn = ($this->checkIn)('Shared', null);
    $walkIn->update(['doctor_id' => null]);

    // Both requests read the board while the walk-in is still unassigned…
    $snapshot = app(QueueBoard::class)->todaysItems($this->f->clinic, $this->f->branch, CarbonImmutable::now('Asia/Kolkata'));

    // …then the other doctor's request commits first.
    app(DoctorQueue::class)->callNext($this->f->clinic, $this->f->branch, $this->f->doctorA);

    expect(fn () => doctorQueueReadingStaleBoard($snapshot)->callNext($this->f->clinic, $this->f->branch, $this->me))
        ->toThrow(ValidationException::class, 'Another doctor has just called the patient who was next.');

    $walkIn->refresh();
    expect($walkIn->doctor_id)->toBe($this->f->doctorA->id)
        ->and($walkIn->status)->toBe(QueueItemStatus::Called)
        ->and(QueueHistory::where('clinic_queue_item_id', $walkIn->id)->where('action', 'call')->count())->toBe(1);
});

test('a doctor who loses the race for a walk-in calls their own next patient instead', function () {
    $walkIn = ($this->checkIn)('Shared', null);
    $walkIn->update(['doctor_id' => null]);
    $this->travel(1)->minute();
    $mine = ($this->checkIn)('Mine');

    $snapshot = app(QueueBoard::class)->todaysItems($this->f->clinic, $this->f->branch, CarbonImmutable::now('Asia/Kolkata'));
    app(DoctorQueue::class)->callNext($this->f->clinic, $this->f->branch, $this->f->doctorA);

    $called = doctorQueueReadingStaleBoard($snapshot)->callNext($this->f->clinic, $this->f->branch, $this->me);

    expect($called->id)->toBe($mine->id)
        ->and($walkIn->fresh()->doctor_id)->toBe($this->f->doctorA->id);
});
