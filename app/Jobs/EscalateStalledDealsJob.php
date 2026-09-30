<?php

namespace App\Jobs;

use App\LeadStatus;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Services\LeadWorkspaceService;
use App\Shared\Notification\Enums\NotificationPriority;
use App\TaskPriority;
use App\TaskStatus;
use App\TaskType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Turns "stalled" from a number on the Deals list into an actual nudge.
 *
 * `Lead::scopeStaleFor()` — the same definition the Deals list badge and the
 * Overview's "Stalled Deals" panel already read — has always been able to
 * name a stalled deal. Nothing acted on it. This creates one follow-up task
 * and one notification for the owner, and only once: a lead stays flagged
 * every day it remains stalled, but a second run finds the first task still
 * pending and does nothing further, rather than piling a fresh one on top of
 * it. Unassigned leads are left to `CheckUnassignedLeadSlaJob`, which already
 * owns that case — a stalled deal with nobody to escalate to is a staffing
 * gap, not a re-engagement one.
 */
class EscalateStalledDealsJob implements ShouldQueue
{
    use Queueable;

    /** Marks a task as this job's own, so a later run can recognise it. */
    public const TASK_TITLE_PREFIX = 'Stalled deal: ';

    public function handle(LeadWorkspaceService $workspace): void
    {
        $days = (int) config('crm.stale_lead_days', 14);

        Lead::query()
            ->staleFor($days)
            ->whereNotIn('status', [LeadStatus::Won->value, LeadStatus::Lost->value])
            ->whereNotNull('assigned_user_id')
            ->chunkById(200, function ($leads) use ($workspace, $days) {
                foreach ($leads as $lead) {
                    $this->escalate($lead, $workspace, $days);
                }
            });
    }

    protected function escalate(Lead $lead, LeadWorkspaceService $workspace, int $days): void
    {
        $alreadyEscalated = Task::query()
            ->where('taskable_type', $lead->getMorphClass())
            ->where('taskable_id', $lead->id)
            ->where('status', TaskStatus::Pending->value)
            ->where('title', self::TASK_TITLE_PREFIX.$lead->company_name)
            ->exists();

        if ($alreadyEscalated) {
            return;
        }

        Task::create([
            'title' => self::TASK_TITLE_PREFIX.$lead->company_name,
            'description' => "No stage movement in {$days}+ days. Re-engage the client or update the stage to reflect what actually happened.",
            'type' => TaskType::FollowUp,
            'status' => TaskStatus::Pending,
            'priority' => TaskPriority::High,
            'due_at' => now()->addDay(),
            'assigned_user_id' => $lead->assigned_user_id,
            'taskable_type' => $lead->getMorphClass(),
            'taskable_id' => $lead->id,
        ]);

        $owner = User::find($lead->assigned_user_id);

        if (! $owner) {
            return;
        }

        $workspace->notifyUser(
            $owner->id,
            'Deal has stalled',
            "{$lead->company_name} has had no stage movement in {$days}+ days.",
            NotificationPriority::HIGH,
            ['type' => 'stalled_deal', 'lead_id' => $lead->id],
        );
    }
}
