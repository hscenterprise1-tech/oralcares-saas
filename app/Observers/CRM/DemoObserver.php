<?php

namespace App\Observers\CRM;

use App\DemoStatus;
use App\Models\Demo;
use App\TaskPriority;
use App\TaskStatus;
use App\TaskType;

class DemoObserver
{
    /**
     * Handle the Demo "created" event.
     */
    public function created(Demo $demo): void
    {
        //
    }

    /**
     * Handle the Demo "updated" event.
     */
    public function updated(Demo $demo): void
    {
        if ($demo->wasChanged('status')) {
            $statusValue = is_object($demo->status) && isset($demo->status->value)
                ? $demo->status->value
                : (string) $demo->status;

            if ($statusValue === DemoStatus::Completed->value) {
                // Schedule post-demo follow-up task
                $demo->lead->tasks()->create([
                    'title' => "Post-Demo Follow-up: {$demo->lead->company_name}",
                    'description' => 'Follow up with client after demo completion. Outcome: '.($demo->outcome?->label() ?? 'N/A').'. Notes: '.($demo->feedback ?? 'No feedback recorded.'),
                    'type' => TaskType::Demo->value,
                    'status' => TaskStatus::Pending->value,
                    'priority' => TaskPriority::High->value,
                    'due_at' => now()->addDay(),
                    'assigned_user_id' => $demo->presenter_id ?? $demo->lead->assigned_user_id,
                    'creator_id' => auth()->id() ?? $demo->presenter_id ?? 1,
                ]);
            } elseif ($statusValue === DemoStatus::NoShow->value) {
                // Schedule no-show reschedule follow-up task
                $demo->lead->tasks()->create([
                    'title' => "No-Show Follow-up: {$demo->lead->company_name}",
                    'description' => "Client did not attend scheduled demo at {$demo->scheduled_at->format('M d, H:i')}. Contact client to reschedule.",
                    'type' => TaskType::Call->value,
                    'status' => TaskStatus::Pending->value,
                    'priority' => TaskPriority::High->value,
                    'due_at' => now()->addHours(4),
                    'assigned_user_id' => $demo->presenter_id ?? $demo->lead->assigned_user_id,
                    'creator_id' => auth()->id() ?? $demo->presenter_id ?? 1,
                ]);
            }
        }
    }
}
