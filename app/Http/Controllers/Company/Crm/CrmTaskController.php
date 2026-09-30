<?php

namespace App\Http\Controllers\Company\Crm;

use App\ActivityType;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Services\CRM\DealActivityRecorder;
use App\TaskPriority;
use App\TaskStatus;
use App\TaskType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Lead-scoped task management — the canonical CRM task path.
 *
 * Authorization derives from the parent Lead: if you may update the lead, you
 * may manage its tasks.
 */
class CrmTaskController extends Controller
{
    public function __construct(protected DealActivityRecorder $activity) {}

    public function store(Request $request, Lead $lead)
    {
        Gate::authorize('update', $lead);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => ['sometimes', 'required', Rule::enum(TaskType::class)],
            'priority' => ['sometimes', 'required', Rule::enum(TaskPriority::class)],
            'due_at' => 'nullable|date',
            'assigned_user_id' => 'nullable|exists:users,id',
        ]);

        if (! empty($validated['assigned_user_id']) && $lead->organization_id) {
            $targetOrgId = User::find((int) $validated['assigned_user_id'])?->resolveOrganizationId();

            if ($targetOrgId && $targetOrgId !== (int) $lead->organization_id) {
                throw ValidationException::withMessages([
                    'assigned_user_id' => 'That person is not on your team.',
                ]);
            }
        }

        $task = new Task($validated);
        $task->type = $validated['type'] ?? TaskType::FollowUp;
        $task->priority = $validated['priority'] ?? TaskPriority::Medium;
        $task->due_at = $validated['due_at'] ?? now()->addDays(2);
        $task->status = TaskStatus::Pending;
        $task->creator_id = auth()->id();

        $lead->tasks()->save($task);

        $this->activity->relatedRecord(
            $lead,
            ActivityType::FollowUp,
            'Task scheduled —',
            $task->title.($task->due_at ? ' (due '.$task->due_at->format('d M Y').')' : ''),
            ['task_id' => $task->id],
            $request->user(),
        );

        return redirect()->back()->with('success', 'Task created successfully.');
    }

    public function complete(Request $request, Lead $lead, Task $task)
    {
        Gate::authorize('update', $lead);

        if ($task->taskable_id !== $lead->id || $task->taskable_type !== $lead->getMorphClass()) {
            abort(403);
        }

        if ($task->status === TaskStatus::Cancelled) {
            throw ValidationException::withMessages([
                'status' => 'A cancelled task cannot be marked complete.',
            ]);
        }

        $task->update([
            'status' => TaskStatus::Completed,
            'completed_at' => now(),
        ]);

        $this->activity->relatedRecord(
            $lead,
            ActivityType::FollowUp,
            'Task completed —',
            (string) $task->title,
            ['task_id' => $task->id],
            $request->user(),
        );

        return redirect()->back()->with('success', 'Task marked as complete.');
    }

    public function destroy(Lead $lead, Task $task)
    {
        Gate::authorize('update', $lead);

        if ($task->taskable_id !== $lead->id || $task->taskable_type !== $lead->getMorphClass()) {
            abort(403);
        }

        $task->delete();

        return redirect()->back()->with('success', 'Task deleted successfully.');
    }
}
