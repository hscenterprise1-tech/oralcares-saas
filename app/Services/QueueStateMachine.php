<?php

namespace App\Services;

use App\AppointmentStatus;
use App\Events\PatientCalled;
use App\Events\PatientCompleted;
use App\Events\PatientRecalled;
use App\Events\PatientSkipped;
use App\Events\QueueItemTransitioned;
use App\Exceptions\IllegalQueueTransition;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueueItem;
use App\Models\QueueHistory;
use App\QueueItemStatus;
use App\Support\ClinicClock;
use App\VisitPriority;
use Illuminate\Support\Facades\DB;

/**
 * The only way a queue item changes status.
 *
 *     waiting ──call──▶ called ──start──▶ in_consultation ──complete──▶ completed
 *        ▲  └─────────────start───────────▲
 *        └──requeue── skipped ◀──skip── waiting | called
 *     cancel: waiting | called | skipped ──▶ cancelled
 *
 * Every move locks the row, refuses an edge that does not exist
 * (`IllegalQueueTransition`), stamps the state's timestamp in clinic wall-clock
 * time, writes an immutable `queue_histories` row, keeps the appointment and
 * visit in step, and fires `QueueItemTransitioned` (plus the older specific
 * events the TV display listens for). Replaces `PatientFlowService`.
 */
class QueueStateMachine
{
    /**
     * @var array<string, array{from: array<int, QueueItemStatus>, to: QueueItemStatus}>
     */
    private const MOVES = [
        'call' => ['from' => [QueueItemStatus::Waiting], 'to' => QueueItemStatus::Called],
        'recall' => ['from' => [QueueItemStatus::Called], 'to' => QueueItemStatus::Called],
        'start' => ['from' => [QueueItemStatus::Waiting, QueueItemStatus::Called], 'to' => QueueItemStatus::InConsultation],
        'complete' => ['from' => [QueueItemStatus::InConsultation], 'to' => QueueItemStatus::Completed],
        'skip' => ['from' => [QueueItemStatus::Waiting, QueueItemStatus::Called], 'to' => QueueItemStatus::Skipped],
        'requeue' => ['from' => [QueueItemStatus::Skipped], 'to' => QueueItemStatus::Waiting],
        'cancel' => ['from' => [QueueItemStatus::Waiting, QueueItemStatus::Called, QueueItemStatus::Skipped], 'to' => QueueItemStatus::Cancelled],
    ];

    private const REFUSALS = [
        'call' => 'Only a waiting patient can be called.',
        'recall' => 'Only a patient who has been called can be called again.',
        'start' => 'Only a waiting or called patient can be taken in.',
        'complete' => 'Only a patient in consultation can be marked done.',
        'skip' => 'Only a waiting or called patient can be marked as not present.',
        'requeue' => 'Only a patient marked as not present can be put back in the queue.',
        'cancel' => 'This patient has already been seen or has left the queue.',
    ];

    /**
     * Statuses still on the board — the ones a doctor or reception can act on.
     *
     * @var array<int, QueueItemStatus>
     */
    public const ACTIVE = [QueueItemStatus::Waiting, QueueItemStatus::Called, QueueItemStatus::InConsultation, QueueItemStatus::Skipped];

    /**
     * Records the check-in itself. The item must have just been created as
     * waiting by `Reception\ReceptionCheckIn`.
     */
    public function admitted(ClinicQueueItem $item, ?ClinicEmployee $performer, ?string $notes = null): void
    {
        $this->record($item, 'checked_in', $performer, $notes);
        event(new QueueItemTransitioned($item, 'checked_in', null, $item->status->value));
    }

    public function call(ClinicQueueItem $item, ?ClinicEmployee $performer = null): ClinicQueueItem
    {
        return $this->move($item, 'call', $performer);
    }

    public function recall(ClinicQueueItem $item, ?ClinicEmployee $performer = null): ClinicQueueItem
    {
        return $this->move($item, 'recall', $performer);
    }

    public function start(ClinicQueueItem $item, ?ClinicEmployee $performer = null): ClinicQueueItem
    {
        return $this->move($item, 'start', $performer);
    }

    public function complete(ClinicQueueItem $item, ?ClinicEmployee $performer = null, ?string $notes = null): ClinicQueueItem
    {
        return $this->move($item, 'complete', $performer, $notes);
    }

    public function skip(ClinicQueueItem $item, ?ClinicEmployee $performer = null): ClinicQueueItem
    {
        return $this->move($item, 'skip', $performer);
    }

    public function requeue(ClinicQueueItem $item, ?ClinicEmployee $performer = null): ClinicQueueItem
    {
        return $this->move($item, 'requeue', $performer);
    }

    public function cancel(ClinicQueueItem $item, ?ClinicEmployee $performer = null, ?string $notes = null): ClinicQueueItem
    {
        return $this->move($item, 'cancel', $performer, $notes);
    }

    /**
     * Changes an active item's priority (e.g. marks it an emergency). Who may
     * do this is the route's `queue.manage` check; the change is always logged.
     */
    public function setPriority(ClinicQueueItem $item, VisitPriority $priority, ?ClinicEmployee $performer = null): ClinicQueueItem
    {
        return DB::transaction(function () use ($item, $priority, $performer): ClinicQueueItem {
            $item = $this->lock($item);

            if (! in_array($item->status, self::ACTIVE, true)) {
                throw new IllegalQueueTransition($item->status->value, 'priority', 'Priority can only be changed while the patient is still in the queue.');
            }

            $previous = $item->priority ?? VisitPriority::Normal;
            if ($previous === $priority) {
                return $item;
            }

            $item->update(['priority' => $priority]);
            $this->record($item, 'priority_changed', $performer, "{$previous->value} → {$priority->value}");
            event(new QueueItemTransitioned($item, 'priority_changed', $item->status->value, $item->status->value));

            return $item;
        });
    }

    private function move(ClinicQueueItem $item, string $action, ?ClinicEmployee $performer, ?string $notes = null): ClinicQueueItem
    {
        return DB::transaction(function () use ($item, $action, $performer, $notes): ClinicQueueItem {
            $item = $this->lock($item);
            $from = $item->status;
            $move = self::MOVES[$action];

            if (! in_array($from, $move['from'], true)) {
                throw new IllegalQueueTransition($from->value, $action, self::REFUSALS[$action]);
            }

            $now = ClinicClock::now();
            $item->update(array_merge(['status' => $move['to']], match ($action) {
                'call', 'recall' => ['called_at' => $now],
                'start' => ['started_at' => $now, 'called_at' => $item->called_at ?? $now],
                'complete' => ['completed_at' => $now],
                'requeue' => ['called_at' => null],
                default => [],
            }));

            $this->syncAppointmentAndVisit($item, $action);
            $this->record($item, $action, $performer, $notes);

            event(new QueueItemTransitioned($item, $action, $from->value, $item->status->value));
            match ($action) {
                'call' => event(new PatientCalled($item)),
                'recall' => event(new PatientRecalled($item)),
                'skip' => event(new PatientSkipped($item)),
                'complete' => event(new PatientCompleted($item)),
                default => null,
            };

            return $item;
        });
    }

    private function lock(ClinicQueueItem $item): ClinicQueueItem
    {
        return ClinicQueueItem::with('queue')->lockForUpdate()->findOrFail($item->id);
    }

    private function record(ClinicQueueItem $item, string $action, ?ClinicEmployee $performer, ?string $notes = null): void
    {
        $queue = $item->queue ?? $item->load('queue')->queue;

        QueueHistory::create([
            'clinic_id' => $queue->clinic_id,
            'branch_id' => $queue->branch_id,
            'clinic_queue_item_id' => $item->id,
            'action' => $action,
            'performed_by' => $performer?->id,
            'notes' => $notes,
            'created_at' => ClinicClock::now(),
        ]);
    }

    /**
     * The appointment and visit mirror the queue, so the calendar and the
     * patient's record show the same state reception sees.
     */
    private function syncAppointmentAndVisit(ClinicQueueItem $item, string $action): void
    {
        $appointmentStatus = match ($action) {
            'start' => AppointmentStatus::InConsultation,
            'complete' => AppointmentStatus::Completed,
            'requeue' => AppointmentStatus::CheckedIn,
            default => null,
        };

        if ($appointmentStatus && $item->appointment_id) {
            $item->appointment()->withoutGlobalScopes()->update(['status' => $appointmentStatus, 'updated_by' => auth()->id()]);
        }

        if ($item->patient_visit_id) {
            $item->patientVisit()->withoutGlobalScopes()->update([
                'queue_status' => $item->status->value,
                'visit_status' => match ($item->status) {
                    QueueItemStatus::Completed => 'completed',
                    QueueItemStatus::Cancelled => 'cancelled',
                    QueueItemStatus::InConsultation => 'in_progress',
                    default => 'checked_in',
                },
            ]);
        }
    }
}
