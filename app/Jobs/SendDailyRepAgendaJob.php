<?php

namespace App\Jobs;

use App\DemoStatus;
use App\Models\Demo;
use App\Models\Task;
use App\Models\Trial;
use App\Models\User;
use App\Services\LeadWorkspaceService;
use App\TaskStatus;
use App\TrialStatus;
use App\UserType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * One message per rep, first thing, with everything they owe somebody today.
 *
 * Demos to run, trials that need a push, follow-ups that are already late. The
 * product could tell a rep each of these separately — and used to, across a demo
 * digest, a follow-up escalation and nothing at all for trials — but three
 * notifications before nine o'clock is three things to dismiss.
 *
 * Built from three grouped queries rather than a pass per rep. The old demo
 * digest loops over demos and sends per presenter, which is fine for demos; an
 * agenda touches three tables, so a per-rep loop would be three queries times
 * the size of the sales team. These are four queries whatever the headcount.
 *
 * Reps with an empty day are not messaged. Deciding that here — by only
 * assembling reps who appear in at least one of the three buckets — is what
 * keeps the work proportional to what is actually happening rather than to how
 * many people have accounts.
 */
class SendDailyRepAgendaJob implements ShouldQueue
{
    use Queueable;

    /**
     * Trial states that want a human today.
     *
     * `preparing` is in the list because a trial that never finished
     * provisioning is the most urgent kind: the customer was promised an
     * environment and has not got one.
     *
     * @var list<string>
     */
    private const TRIALS_NEEDING_ATTENTION = [
        TrialStatus::Preparing->value,
        TrialStatus::EndingSoon->value,
        TrialStatus::InGrace->value,
    ];

    public function handle(LeadWorkspaceService $workspace): void
    {
        $now = Carbon::now();

        $demos = $this->demosToday($now);
        $trials = $this->trialsNeedingAttention();
        $overdue = $this->overdueFollowups($now);

        $repIds = collect()
            ->merge($demos->keys())
            ->merge($trials->keys())
            ->merge($overdue->keys())
            ->unique()
            ->values();

        if ($repIds->isEmpty()) {
            Log::info('Daily rep agenda: nothing to send');

            return;
        }

        /*
         * Company-portal users only. A clinic user can be assigned a task by
         * their own practice, and they are not who this agenda is for.
         */
        $reps = User::query()
            ->whereIn('id', $repIds)
            ->where('user_type', '!=', UserType::ClinicUser)
            ->get();

        $sent = 0;

        foreach ($reps as $rep) {
            $result = $workspace->sendRepDailyAgenda($rep, [
                'demos' => (int) ($demos[$rep->id]['count'] ?? 0),
                'first_demo_at' => $demos[$rep->id]['first_at'] ?? null,
                'pending_trials' => (int) ($trials[$rep->id] ?? 0),
                'overdue_followups' => (int) ($overdue[$rep->id] ?? 0),
            ]);

            if ($result['success'] ?? false) {
                $sent++;
            }
        }

        Log::info('Daily rep agenda swept', ['reps' => $reps->count(), 'whatsapp_sent' => $sent]);
    }

    /**
     * Demos each presenter is running today, and when the first one starts.
     *
     * @return Collection<int, array{count:int, first_at:string}>
     */
    private function demosToday(Carbon $now): Collection
    {
        return Demo::query()
            ->whereNotNull('presenter_id')
            ->whereDate('scheduled_at', $now->toDateString())
            ->whereIn('status', [DemoStatus::Scheduled, DemoStatus::Confirmed])
            ->orderBy('scheduled_at')
            ->get(['presenter_id', 'scheduled_at'])
            ->groupBy('presenter_id')
            ->map(fn ($group) => [
                'count' => $group->count(),
                'first_at' => $group->first()->scheduled_at->format('h:i A'),
            ]);
    }

    /**
     * Open trials per owner that are in a state somebody should act on.
     *
     * @return Collection<int, int>
     */
    private function trialsNeedingAttention(): Collection
    {
        return Trial::query()
            ->whereNotNull('assigned_user_id')
            ->whereIn('status', self::TRIALS_NEEDING_ATTENTION)
            ->whereNull('lost_at')
            ->selectRaw('assigned_user_id, count(*) as aggregate')
            ->groupBy('assigned_user_id')
            ->pluck('aggregate', 'assigned_user_id')
            ->map(fn ($count) => (int) $count);
    }

    /**
     * Pending tasks per assignee whose due date has already passed.
     *
     * The same predicate `CrmMetricsService::repPerformance()` reports overdue
     * work with — due *before* today, so something due later today is on the
     * agenda as work, not as a failure.
     *
     * @return Collection<int, int>
     */
    private function overdueFollowups(Carbon $now): Collection
    {
        return Task::query()
            ->whereNotNull('assigned_user_id')
            ->where('status', TaskStatus::Pending)
            ->whereNotNull('due_at')
            ->where('due_at', '<', $now->copy()->startOfDay())
            ->selectRaw('assigned_user_id, count(*) as aggregate')
            ->groupBy('assigned_user_id')
            ->pluck('aggregate', 'assigned_user_id')
            ->map(fn ($count) => (int) $count);
    }
}
