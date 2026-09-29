<?php

namespace App\Services\Reception;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueueItem;
use App\QueueItemStatus;
use App\Services\QueueStateMachine;
use App\Support\ClinicClock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A doctor's view of today's queue — who is with them now, who is next — and
 * the two taps they make: "Call next" and "Done & call next". A walk-in
 * waiting for "any doctor" is next for every doctor; calling them assigns
 * them to whoever called.
 */
class DoctorQueue
{
    public function __construct(
        protected QueueBoard $board,
        protected QueueStateMachine $machine,
    ) {}

    /**
     * @return array{now: array<string, mixed>|null, called: array<string, mixed>|null, next: array<string, mixed>|null, waiting_after: int}
     */
    public function nowAndNext(Clinic $clinic, Branch $branch, ClinicEmployee $doctor): array
    {
        $now = ClinicClock::now();
        $items = $this->mine($clinic, $branch, $doctor);
        $rooms = collect();
        $present = fn (?ClinicQueueItem $item) => $item ? $this->board->present($item, $now, false, $rooms) : null;

        $waiting = $items->where('status', QueueItemStatus::Waiting)->values();

        return [
            'now' => $present($items->firstWhere('status', QueueItemStatus::InConsultation)),
            'called' => $present($items->firstWhere('status', QueueItemStatus::Called)),
            'next' => $present($waiting->first()),
            'waiting_after' => max(0, $waiting->count() - 1),
        ];
    }

    /**
     * Call this doctor's next waiting patient.
     *
     * Walk-ins with no doctor are shared by every doctor at the branch, so two
     * doctors can reach for the same one at once. The claim is therefore made
     * on a locked row: the item is re-read under `lockForUpdate`, and a doctor
     * only takes it if it is still waiting and still unassigned (or already
     * theirs). A doctor who loses the race moves on to their next patient
     * and never overwrites the winner's `doctor_id`.
     *
     * The doctor's own row is locked first, so two taps from the same doctor
     * run one after the other and cannot call two patients.
     */
    public function callNext(Clinic $clinic, Branch $branch, ClinicEmployee $doctor): ClinicQueueItem
    {
        return DB::transaction(function () use ($clinic, $branch, $doctor) {
            ClinicEmployee::withoutGlobalScopes()->whereKey($doctor->id)->lockForUpdate()->first();

            $items = $this->mine($clinic, $branch, $doctor);

            if ($current = $items->firstWhere('status', QueueItemStatus::InConsultation)) {
                throw ValidationException::withMessages(['status' => "Finish with {$current->patientVisit?->patient?->short_name} first."]);
            }

            if ($called = $items->firstWhere('status', QueueItemStatus::Called)) {
                throw ValidationException::withMessages(['status' => "{$called->patientVisit?->patient?->short_name} has been called and not seen yet. Take them in, call again, or mark them not present."]);
            }

            $candidates = $items->where('status', QueueItemStatus::Waiting)->values();

            if ($candidates->isEmpty()) {
                throw ValidationException::withMessages(['status' => 'No one is waiting for you.']);
            }

            foreach ($candidates as $candidate) {
                $claimed = $this->claim($candidate, $doctor);

                if ($claimed) {
                    return $this->machine->call($claimed, $doctor);
                }
            }

            throw ValidationException::withMessages(['status' => 'Another doctor has just called the patient who was next. No one else is waiting for you.']);
        });
    }

    /**
     * Lock the item and take it for this doctor, or return null when it has
     * been called, cancelled or taken by another doctor since it was read.
     */
    private function claim(ClinicQueueItem $candidate, ClinicEmployee $doctor): ?ClinicQueueItem
    {
        $item = ClinicQueueItem::query()->lockForUpdate()->find($candidate->id);

        if (! $item || $item->status !== QueueItemStatus::Waiting) {
            return null;
        }

        if ($item->doctor_id !== null && $item->doctor_id !== $doctor->id) {
            return null;
        }

        if ($item->doctor_id === null) {
            $item->update(['doctor_id' => $doctor->id]);
        }

        return $item;
    }

    public function completeAndCallNext(Clinic $clinic, Branch $branch, ClinicEmployee $doctor, ClinicQueueItem $item): ?ClinicQueueItem
    {
        return DB::transaction(function () use ($clinic, $branch, $doctor, $item) {
            $this->machine->complete($item, $doctor);

            $hasWaiting = $this->mine($clinic, $branch, $doctor)->contains('status', QueueItemStatus::Waiting);

            return $hasWaiting ? $this->callNext($clinic, $branch, $doctor) : null;
        });
    }

    /**
     * This doctor's active items plus unassigned walk-ins, in queue order.
     *
     * @return Collection<int, ClinicQueueItem>
     */
    private function mine(Clinic $clinic, Branch $branch, ClinicEmployee $doctor): Collection
    {
        $now = ClinicClock::now();

        return QueueBoard::order(
            $this->board->todaysItems($clinic, $branch, $now)->filter(fn (ClinicQueueItem $item) => in_array($item->status, [QueueItemStatus::Waiting, QueueItemStatus::Called, QueueItemStatus::InConsultation], true)
                && ($item->doctor_id === $doctor->id || ($item->doctor_id === null && $item->status === QueueItemStatus::Waiting))),
            $now,
        );
    }
}
