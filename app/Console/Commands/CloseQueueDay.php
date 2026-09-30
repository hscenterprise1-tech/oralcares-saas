<?php

namespace App\Console\Commands;

use App\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\ClinicOperatingHour;
use App\Models\ClinicQueue;
use App\Models\ClinicQueueItem;
use App\QueueItemStatus;
use App\Services\QueueStateMachine;
use App\Support\ClinicClock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Closes each branch's reception day once the branch itself has closed.
 * Scheduled every 15 minutes; safe to run any number of times.
 *
 * A branch has closed for a day when, by its `ClinicOperatingHour` rows for
 * that weekday, the *last* open window has ended — so a split shift
 * (09:00–13:00, 17:00–21:00) is not closed during the afternoon break — or
 * when the day is marked closed. A branch with no hours set is only closed
 * once the day is over. Nothing is hardcoded to a clock time.
 *
 * Closing a day:
 * - patients still waiting, called or marked not present leave the queue
 *   (cancelled, logged "Day closed");
 * - a patient still in consultation keeps the day open until the doctor
 *   marks them done — unless the day is already over, when they are
 *   completed by the system and logged as such;
 * - appointments that were never checked in become no-shows.
 */
class CloseQueueDay extends Command
{
    protected $signature = 'queue:close-day';

    protected $description = 'Close reception queues and mark no-shows for branches whose working day has ended';

    /**
     * How far back an unclosed day is still picked up (e.g. after downtime).
     */
    private const LOOKBACK_DAYS = 7;

    public function handle(QueueStateMachine $machine): int
    {
        $now = ClinicClock::now();
        $today = $now->toDateString();
        $from = $now->subDays(self::LOOKBACK_DAYS)->toDateString();
        $totals = ['queues_closed' => 0, 'left_queue' => 0, 'auto_completed' => 0, 'no_shows' => 0];

        foreach ($this->candidateDays($from, $today) as [$branchId, $date]) {
            $branch = Branch::withoutGlobalScopes()->find($branchId);
            if (! $branch || ! $this->hasClosed($branch, $date, $now)) {
                continue;
            }

            $dayIsOver = $date < $today;

            foreach ($this->openQueues($branch, $date) as $queue) {
                foreach (ClinicQueueItem::where('clinic_queue_id', $queue->id)->whereIn('status', QueueStateMachine::ACTIVE)->get() as $item) {
                    if ($item->status !== QueueItemStatus::InConsultation) {
                        $machine->cancel($item, null, 'Day closed');
                        $totals['left_queue']++;
                    } elseif ($dayIsOver) {
                        $machine->complete($item, null, 'Completed by the system: still in consultation when the day ended');
                        $totals['auto_completed']++;
                    }
                }

                $stillSeeing = ClinicQueueItem::where('clinic_queue_id', $queue->id)->where('status', QueueItemStatus::InConsultation)->exists();
                if (! $stillSeeing) {
                    $queue->update(['closed_at' => $now]);
                    $totals['queues_closed']++;
                }
            }

            $totals['no_shows'] += Appointment::withoutGlobalScopes()
                ->where('branch_id', $branch->id)
                ->whereDate('scheduled_at', $date)
                ->where('status', AppointmentStatus::Scheduled)
                ->update(['status' => AppointmentStatus::NoShow]);
        }

        $this->info(collect($totals)->map(fn (int $count, string $label) => str_replace('_', ' ', $label).": {$count}")->implode(', '));

        return self::SUCCESS;
    }

    /**
     * Only branch-days with something left to close: an open queue or a
     * booking that was never checked in.
     *
     * @return Collection<int, array{0: int, 1: string}>
     */
    private function candidateDays(string $from, string $today): Collection
    {
        $fromQueues = ClinicQueue::withoutGlobalScopes()
            ->whereNull('closed_at')
            ->whereNotNull('queue_date')
            ->whereDate('queue_date', '>=', $from)
            ->whereDate('queue_date', '<=', $today)
            ->get(['branch_id', 'queue_date'])
            ->map(fn (ClinicQueue $queue) => [(int) $queue->branch_id, $queue->queue_date->toDateString()]);

        $fromBookings = Appointment::withoutGlobalScopes()
            ->where('status', AppointmentStatus::Scheduled)
            ->whereNotNull('branch_id')
            ->whereDate('scheduled_at', '>=', $from)
            ->whereDate('scheduled_at', '<=', $today)
            ->get(['branch_id', 'scheduled_at'])
            ->map(fn (Appointment $appointment) => [(int) $appointment->branch_id, $appointment->scheduled_at->toDateString()]);

        return $fromQueues->concat($fromBookings)->unique(fn (array $pair) => implode('|', $pair))->values();
    }

    private function hasClosed(Branch $branch, string $date, CarbonImmutable $now): bool
    {
        if ($date < $now->toDateString()) {
            return true;
        }

        $hours = ClinicOperatingHour::where('branch_id', $branch->id)
            ->where('day_of_week', CarbonImmutable::parse($date)->dayOfWeek)
            ->get();

        if ($hours->isEmpty()) {
            return false;
        }

        $open = $hours->reject(fn (ClinicOperatingHour $row) => $row->is_closed);
        if ($open->isEmpty()) {
            return true;
        }

        return $now->format('H:i:s') >= $open->max('close_time');
    }

    /**
     * @return Collection<int, ClinicQueue>
     */
    private function openQueues(Branch $branch, string $date): Collection
    {
        return ClinicQueue::withoutGlobalScopes()
            ->where('branch_id', $branch->id)
            ->whereNull('closed_at')
            ->whereDate('queue_date', $date)
            ->get();
    }
}
