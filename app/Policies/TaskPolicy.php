<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Policies\Concerns\ChecksCrmPermissions;

class TaskPolicy
{
    use ChecksCrmPermissions;

    public function viewAny(User $user): bool
    {
        return $this->hasAnyCrmPermission($user, 'view-tasks', 'view-crm');
    }

    /**
     * A task is visible to its owner, its creator, or anyone with CRM task
     * visibility *in its own tenant*. Row-level narrowing for the global
     * list happens in TaskController::scopedQuery(); this is the check that
     * stands even when a task id is reached directly — previously this
     * policy had no tenant boundary of its own at all, so a permission grant
     * in one organisation authorised a task belonging to another.
     */
    public function view(User $user, Task $task): bool
    {
        if (! $this->isCompanyPortalUser($user) || ! $this->sharesTenantWith($user, $task)) {
            return false;
        }

        if ((int) $task->assigned_user_id === (int) $user->id
            || (int) $task->creator_id === (int) $user->id) {
            return true;
        }

        return $this->hasAnyCrmPermission($user, 'view-tasks', 'view-crm');
    }

    public function create(User $user): bool
    {
        return $this->hasAnyCrmPermission($user, 'view-tasks', 'view-crm');
    }

    public function update(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }

    public function delete(User $user, Task $task): bool
    {
        if (! $this->isCompanyPortalUser($user) || ! $this->sharesTenantWith($user, $task)) {
            return false;
        }

        if ((int) $task->creator_id === (int) $user->id) {
            return true;
        }

        return $this->hasAnyCrmPermission($user, 'view-tasks', 'view-crm');
    }

    /**
     * Whether this user's own tenant matches the task's. False — never a
     * pass — when either side is unresolvable, so a task nothing could trace
     * to an organisation (see Task::deriveOrganizationId()) is visible to a
     * master admin only, rather than to whoever happens to hold the
     * permission.
     */
    protected function sharesTenantWith(User $user, Task $task): bool
    {
        $userTenant = $user->resolveOrganizationId();

        if (! $userTenant || ! $task->organization_id) {
            return false;
        }

        return (int) $userTenant === (int) $task->organization_id;
    }
}
