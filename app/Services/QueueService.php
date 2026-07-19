<?php

namespace App\Services;

use App\Events\QueueUpdated;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueue;
use App\Models\ClinicQueueItem;
use App\VisitPriority;
use App\VisitStatus;
use Illuminate\Support\Facades\DB;

class QueueService
{
    /**
     * Create a new queue in a clinic branch.
     */
    public function createQueue(
        Clinic $clinic,
        Branch $branch,
        string $name,
        ?ClinicEmployee $doctor = null
    ): ClinicQueue {
        return ClinicQueue::create([
            'clinic_id' => $clinic->id,
            'branch_id' => $branch->id,
            'name' => $name,
            'doctor_id' => $doctor?->id,
        ]);
    }

    /**
     * Enqueue a clinical encounter.
     */
    public function enqueue(
        ClinicQueue $queue,
        ClinicalEncounter $encounter,
        VisitPriority $priority = VisitPriority::Normal
    ): ClinicQueueItem {
        return DB::transaction(function () use ($queue, $encounter, $priority) {
            $maxPosition = $queue->items()->max('position') ?? 0;

            $item = ClinicQueueItem::create([
                'clinic_queue_id' => $queue->id,
                'encounter_id' => $encounter->id,
                'position' => $maxPosition + 1,
                'priority' => $priority,
            ]);

            // Automatically set status to Waiting
            $encounter->update(['status' => VisitStatus::Waiting]);

            event(new QueueUpdated($queue));

            return $item;
        });
    }

    /**
     * Call the next patient in the queue.
     */
    public function callNext(ClinicQueue $queue): ?ClinicQueueItem
    {
        return DB::transaction(function () use ($queue) {
            $next = $queue->items()
                ->whereNull('called_at')
                ->orderBy('position')
                ->first();

            if ($next) {
                $next->update(['called_at' => now()]);
                $next->encounter->update(['status' => VisitStatus::Called]);

                event(new QueueUpdated($queue));
            }

            return $next;
        });
    }

    /**
     * Remove an encounter from the queue and re-order remaining items.
     */
    public function removeFromQueue(ClinicQueue $queue, ClinicalEncounter $encounter): void
    {
        DB::transaction(function () use ($queue, $encounter) {
            $queue->items()->where('encounter_id', $encounter->id)->delete();

            // Re-order
            $items = $queue->items()->orderBy('position')->get();
            $pos = 1;
            foreach ($items as $item) {
                $item->update(['position' => $pos++]);
            }

            event(new QueueUpdated($queue));
        });
    }
}
