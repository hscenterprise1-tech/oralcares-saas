<?php

namespace App\Observers\CRM;

use App\Models\Trial;

class TrialObserver
{
    /**
     * Handle the Trial "created" event.
     */
    public function created(Trial $trial): void
    {
        // Schedule a follow-up task 2 days before the trial ends
        $dueDate = $trial->end_date->subDays(2);

        // If the trial is shorter than 2 days, set due date to tomorrow
        if ($dueDate->isPast()) {
            $dueDate = now()->addDay();
        }

        $trial->lead->tasks()->create([
            'title' => 'Trial Ending Soon Follow-up',
            'description' => 'The trial for this lead ends on '.$trial->end_date->format('Y-m-d').'. Reach out to discuss next steps.',
            'status' => 'pending',
            'priority' => 'high',
            'due_at' => $dueDate,
            'assigned_user_id' => $trial->lead->assigned_user_id,
            /*
             * Whoever started the trial, not user 1.
             *
             * `creator_id` was hardcoded to 1, which is a foreign key to
             * whichever user happens to hold that id — a stranger on any real
             * install, and a constraint violation where no such user exists.
             * The column is nullable, so an unattributed task is both possible
             * and more honest than a wrong attribution.
             */
            'creator_id' => auth()->id(),
        ]);
    }

    /**
     * Handle the Trial "updated" event.
     */
    public function updated(Trial $trial): void
    {
        //
    }

    /**
     * Handle the Trial "deleted" event.
     */
    public function deleted(Trial $trial): void
    {
        //
    }

    /**
     * Handle the Trial "restored" event.
     */
    public function restored(Trial $trial): void
    {
        //
    }

    /**
     * Handle the Trial "force deleted" event.
     */
    public function forceDeleted(Trial $trial): void
    {
        //
    }
}
