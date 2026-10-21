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
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

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

    /**
     * Branch-days handled per batch; each batch loads its branches and their
     * operating hours in two queries.
     */
    private const CHUNK = 500;

    public function handle(QueueStateMachine $machine): int
    {
        $now = ClinicClock::now();
        $today = $now->toDateString();
        $from = $now->subDays(self::LOOKBACK_DAYS)->toDateString();
        $totals = ['queues_closed' => 0, 'left_queue' => 0, 'auto_completed' => 0, 'no_shows' => 0];

        foreach ($this->candidateDays($from, $today, $now)->chunk(self::CHUNK) as $chunk) {
            $branchIds = $chunk->map(fn (object $row) => (int) $row->branch_id)->unique()->values();
            $branches = Branch::withoutGlobalScopes()->whereIn('id', $branchIds)->get()->keyBy('id');
            $hours = ClinicOperatingHour::whereIn('branch_id', $branchIds)->get()->groupBy('branch_id');

            foreach ($chunk as $row) {
                $branch = $branches->get((int) $row->branch_id);
                $date = substr((string) $row->day, 0, 10);

                if (! $branch || ! $this->hasClosed($hours->get($branch->id, new Collection), $date, $now)) {
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

                [$dayStart, $nextDay] = $this->dayRange($date);
                $totals['no_shows'] += Appointment::withoutGlobalScopes()
                    ->where('clinic_id', $branch->clinic_id)
                    ->where('branch_id', $branch->id)
                    ->where('scheduled_at', '>=', $dayStart)
                    ->where('scheduled_at', '<', $nextDay)
                    ->whereIn('status', AppointmentStatus::awaitingArrival())
                    ->update(['status' => AppointmentStatus::NoShow]);
            }
        }

        $this->info(collect($totals)->map(fn (int $count, string $label) => str_replace('_', ' ', $label).": {$count}")->implode(', '));

        return self::SUCCESS;
    }

    /**
     * Only branch-days with something left to close: an open queue or a
     * booking that was never checked in.
     *
     * This runs every 15 minutes across every tenant, so the database does
     * the work: one `UNION` of distinct (branch, day) pairs found through
     * range predicates on `clinic_queues_open_day_index` and
     * `appointments_status_schedule_index`, streamed rather than loaded, with
     * today's pairs limited to branches already closed
     * (`clinic_operating_hours_day_closing_index`). It used to hydrate every
     * awaiting appointment of every clinic for eight days, with `whereDate()`
     * predicates no index could serve.
     *
     * @return LazyCollection<int, object{branch_id: int, day: string}>
     */
    private function candidateDays(string $from, string $today, CarbonImmutable $now): LazyCollection
    {
        [$rangeStart] = $this->dayRange($from);
        [$todayStart, $rangeEnd] = $this->dayRange($today);
        $awaiting = array_map(fn (AppointmentStatus $status) => $status->value, AppointmentStatus::awaitingArrival());

        $queues = fn (string $start, string $end) => DB::table('clinic_queues')
            ->whereNull('closed_at')
            ->where('queue_date', '>=', $start)
            ->where('queue_date', '<', $end)
            ->selectRaw('branch_id, date(queue_date) as day');

        $bookings = fn (string $start, string $end) => DB::table('appointments')
            ->whereIn('status', $awaiting)
            ->where('scheduled_at', '>=', $start)
            ->where('scheduled_at', '<', $end)
            ->whereNotNull('branch_id')
            ->selectRaw('branch_id, date(scheduled_at) as day');

        // Earlier days are over everywhere. Today only counts for branches
        // whose hours say they have closed, so during the working day the
        // sweep reads almost nothing.
        $closedToday = $this->branchesClosedFor($today, $now);

        return $queues($rangeStart, $todayStart)
            ->union($bookings($rangeStart, $todayStart))
            ->union($queues($todayStart, $rangeEnd)->whereIn('branch_id', $closedToday))
            ->union($bookings($todayStart, $rangeEnd)->whereIn('branch_id', $closedToday))
            ->orderBy('branch_id')->orderBy('day')->cursor();
    }

    /**
     * Branches whose working day `$date` has ended by `$now`: every window
     * that day is marked closed, or the last one has finished. A branch with
     * no hours set for that weekday is not included (it closes only once the
     * day is over), matching `hasClosed()`.
     */
    private function branchesClosedFor(string $date, CarbonImmutable $now): QueryBuilder
    {
        return DB::table('clinic_operating_hours')
            ->where('day_of_week', CarbonImmutable::parse($date)->dayOfWeek)
            ->groupBy('branch_id')
            ->havingRaw('sum(case when is_closed = 0 then 1 else 0 end) = 0 or max(case when is_closed = 0 then close_time end) <= ?', [$now->format('H:i:s')])
            ->select('branch_id');
    }

    /**
     * Whether the branch's working day has ended, from its hours for that weekday.
     *
     * @param  Collection<int, ClinicOperatingHour>  $branchHours  all of the branch's rows
     */
    private function hasClosed(Collection $branchHours, string $date, CarbonImmutable $now): bool
    {
        if ($date < $now->toDateString()) {
            return true;
        }

        $hours = $branchHours->where('day_of_week', CarbonImmutable::parse($date)->dayOfWeek);

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
        [, $nextDay] = $this->dayRange($date);

        return ClinicQueue::withoutGlobalScopes()
            ->where('clinic_id', $branch->clinic_id)
            ->where('branch_id', $branch->id)
            ->where('queue_date', '>=', $date)
            ->where('queue_date', '<', $nextDay)
            ->whereNull('closed_at')
            ->get();
    }

    /**
     * A calendar day as a half-open range of plain dates, which compares
     * correctly against DATE and DATETIME columns on every driver.
     *
     * @return array{0: string, 1: string}
     */
    private function dayRange(string $date): array
    {
        $day = CarbonImmutable::parse($date);

        return [$day->toDateString(), $day->addDay()->toDateString()];
    }
}
