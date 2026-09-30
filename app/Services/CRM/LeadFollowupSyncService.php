<?php

namespace App\Services\CRM;

use App\Models\Lead;
use App\Models\Task;
use App\TaskStatus;
use App\TaskType;

/**
 * The single authority for `leads.next_followup_at`.
 *
 * THE RULE
 *
 *   next_followup_at = the earliest `due_at` among that lead's PENDING tasks
 *   whose type is a contact type (follow_up or call). NULL when none exist.
 *
 * Contact types only: both mean "you owe this lead contact by this date",
 * which is exactly what the column and every overdue queue represent. A `demo`
 * task is scheduled work with its own `demos.scheduled_at`; `to_do` is generic
 * admin. Neither moves the follow-up date.
 *
 * The column stays denormalised rather than being replaced by a subquery
 * because it is filtered, sorted and indexed on across the CRM. It is now
 * derived, never authored: every writer goes through here, so the Task table
 * and the lead can no longer disagree.
 *
 * Writes bypass the model layer deliberately. Recomputing a cached date is not
 * a business event — it must not fire LeadObserver, re-score the lead,
 * recompute next-best-action or land on the activity timeline.
 */
class LeadFollowupSyncService
{
    /**
     * Task types that constitute a follow-up commitment.
     *
     * @var list<TaskType>
     */
    public const CONTACT_TYPES = [TaskType::FollowUp, TaskType::Call];

    /**
     * Recompute and persist the follow-up date for one lead.
     *
     * Returns the value written, so callers can assert on it without a reload.
     */
    public function sync(Lead|int|null $lead): ?string
    {
        $leadId = $lead instanceof Lead ? $lead->getKey() : $lead;

        if (! $leadId) {
            return null;
        }

        $next = $this->resolveNextFollowupAt((int) $leadId);

        Lead::withoutGlobalScopes()
            ->whereKey($leadId)
            ->update(['next_followup_at' => $next]);

        if ($lead instanceof Lead) {
            $lead->setAttribute('next_followup_at', $next);
            $lead->syncOriginalAttribute('next_followup_at');
        }

        return $next;
    }

    /**
     * Recompute for several leads — used after a mass task update, which
     * bypasses model events by design.
     *
     * @param  iterable<Lead|int>  $leads
     */
    public function syncMany(iterable $leads): void
    {
        foreach ($leads as $lead) {
            $this->sync($lead);
        }
    }

    /**
     * The earliest outstanding contact commitment, as a datetime string.
     */
    protected function resolveNextFollowupAt(int $leadId): ?string
    {
        return Task::query()
            ->where('taskable_type', (new Lead)->getMorphClass())
            ->where('taskable_id', $leadId)
            ->where('status', TaskStatus::Pending->value)
            ->whereIn('type', array_map(fn (TaskType $t) => $t->value, self::CONTACT_TYPES))
            ->whereNotNull('due_at')
            ->min('due_at');
    }
}
