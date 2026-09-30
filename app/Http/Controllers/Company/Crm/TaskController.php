<?php

namespace App\Http\Controllers\Company\Crm;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The write endpoints behind My Work's task composer.
 *
 * There is no tasks page any more. The index this class used to render was a
 * third list of the same rows — My Work already carried your open tasks and,
 * for an admin, everyone else's — so it was retired and its URL redirects
 * there. These three endpoints stayed, because the modal on My Work posts to
 * them and a task created from a person's own queue belongs to no single deal.
 *
 * Kept distinct from the lead-scoped CrmTaskController on purpose: that one
 * authorizes against the deal a task hangs off, this one against the task.
 */
class TaskController extends Controller
{
    public function store(Request $request)
    {
        Gate::authorize('create', Task::class);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|string',
            'type' => 'required|string',
            'priority' => 'required|string',
            'due_at' => 'required|date',
            'assigned_user_id' => 'nullable|exists:users,id',
            'taskable_type' => 'nullable|string',
            'taskable_id' => 'nullable|integer',
        ]);

        $validated['creator_id'] = auth()->id();

        $this->assertSameTenant($request->user(), $validated);

        Task::create($validated);

        return back()->with('success', 'Task created successfully.');
    }

    public function update(Request $request, Task $task)
    {
        Gate::authorize('update', $task);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|string',
            'type' => 'required|string',
            'priority' => 'required|string',
            'due_at' => 'required|date',
            'assigned_user_id' => 'nullable|exists:users,id',
        ]);

        $this->assertSameTenant($request->user(), $validated);

        $task->update($validated);

        return back()->with('success', 'Task updated successfully.');
    }

    /**
     * Refuse a write that would hand this task to a target — a taskable
     * record or an assignee — outside the acting user's own organisation.
     *
     * `Task::deriveOrganizationId()` stamps every task's tenant from exactly
     * these fields, so an attacker pointing `taskable_type`/`taskable_id` at
     * another tenant's lead did not fail to create a task — it created one
     * that then belonged, correctly, to the other tenant. The fix is to
     * refuse the write before it happens, not to change what the tenant
     * derivation resolves to.
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertSameTenant(?User $user, array $data): void
    {
        if (! $user) {
            return;
        }

        $actingOrgId = $user->resolveOrganizationId();
        $targetOrgId = Task::deriveOrganizationId(new Task($data));

        if ($targetOrgId && $targetOrgId !== $actingOrgId) {
            throw ValidationException::withMessages([
                'taskable_id' => 'That record is not in your organisation.',
            ]);
        }
    }

    public function destroy(Task $task)
    {
        Gate::authorize('delete', $task);

        $task->delete();

        return back()->with('success', 'Task deleted successfully.');
    }
}
