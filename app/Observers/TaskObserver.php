<?php

namespace App\Observers;

use App\Models\Lead;
use App\Models\Task;
use App\Services\CRM\LeadFollowupSyncService;

/**
 * Keeps `leads.next_followup_at` derived from the task table.
 *
 * Hooked on the model rather than in the controllers because tasks are written
 * from eleven places — two controllers, three observers and six service
 * methods. Only completeFollowup() used to keep the lead in step, so every
 * other path left a stale date behind.
 *
 * Scope is deliberately narrow: a task only triggers a recompute when its
 * subject is a Lead. Tasks attached to any other polymorphic subject are
 * untouched.
 *
 * @see LeadFollowupSyncService for the rule itself
 */
class TaskObserver
{
    public function __construct(protected LeadFollowupSyncService $sync) {}

    public function created(Task $task): void
    {
        $this->syncFor($task);
    }

    public function updated(Task $task): void
    {
        // A task can move between leads, so the lead it left needs recomputing
        // as well as the one it joined.
        if ($task->isDirty('taskable_id') || $task->isDirty('taskable_type')) {
            $this->syncForSubject(
                $task->getOriginal('taskable_type'),
                $task->getOriginal('taskable_id')
            );
        }

        $this->syncFor($task);
    }

    public function deleted(Task $task): void
    {
        $this->syncFor($task);
    }

    public function restored(Task $task): void
    {
        $this->syncFor($task);
    }

    public function forceDeleted(Task $task): void
    {
        $this->syncFor($task);
    }

    protected function syncFor(Task $task): void
    {
        $this->syncForSubject($task->taskable_type, $task->taskable_id);
    }

    protected function syncForSubject(?string $type, int|string|null $id): void
    {
        if (! $id || $type !== (new Lead)->getMorphClass()) {
            return;
        }

        $this->sync->sync((int) $id);
    }
}
