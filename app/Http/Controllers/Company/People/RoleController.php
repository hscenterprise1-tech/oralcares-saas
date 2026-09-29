<?php

namespace App\Http\Controllers\Company\People;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Who holds which role, and what a role grants.
 *
 * Fourteen roles and roughly ninety permissions have existed with no way to see
 * either. This surfaces them and lets a role be given to or taken from a person.
 *
 * Roles themselves are read-only here, deliberately. `spatie_roles` carries
 * `id`, `branch_id`, `name` and `guard_name` — there is no column marking a role
 * as a system role, and every seeded role has a null `branch_id`, which the
 * seeders describe as the template scope. Offering edit or delete would mean
 * inventing the rule that decides whether `Organization Admin` may be stripped
 * of its permissions, and that decision is not this batch's to make.
 *
 * Assignment writes through Spatie's team scope. `config/permission.php` sets
 * `teams => true` with `team_foreign_key => branch_id`, so an assignment lands
 * in whichever team id is current — global request state that must be set
 * deliberately and put back, exactly as `CustomerProvisioningEngine` does.
 */
class RoleController extends Controller
{
    /**
     * Permission names come in four conventions — `view-crm`, `crm.view`,
     * `view_crm` and `manage billing`. Grouping reads the subject out of each
     * shape so the matrix is legible without renaming anything.
     */
    protected function moduleOf(string $permission): string
    {
        if (str_contains($permission, '.')) {
            return Str::headline(explode('.', $permission)[0]);
        }

        $normalised = str_replace(['-', '_'], ' ', $permission);
        $words = array_values(array_filter(explode(' ', $normalised)));

        /* Drop a leading verb so `view-crm` and `manage-employees` both group by subject. */
        if (count($words) > 1 && in_array($words[0], ['view', 'manage', 'create', 'edit', 'delete'], true)) {
            array_shift($words);
        }

        return Str::headline($words[0] ?? 'other');
    }

    public function index(): Response
    {
        Gate::authorize('roles.manage');

        $roles = Role::query()
            ->withCount('permissions')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'guard_name' => $role->guard_name,
                'permissions_count' => $role->permissions_count,
                'users_count' => \DB::table(config('permission.table_names.model_has_roles'))
                    ->where('role_id', $role->id)
                    ->count(),
                /*
                 * The seeders call a null branch the template scope. Reported
                 * rather than acted on: nothing here edits a role.
                 */
                'is_template' => $role->branch_id === null,
            ])
            ->values();

        return Inertia::render('Company/People/RolesWorkspace', [
            'roles' => $roles,
            'permissionCount' => Permission::count(),
        ]);
    }

    public function show(Role $role): Response
    {
        Gate::authorize('roles.manage');

        $granted = $role->permissions->pluck('name')->all();

        $matrix = Permission::query()
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Permission $permission) => $this->moduleOf($permission->name))
            ->map(fn ($permissions, $module) => [
                'module' => $module,
                'permissions' => $permissions->map(fn (Permission $permission) => [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'granted' => in_array($permission->name, $granted, true),
                ])->values(),
            ])
            ->sortKeys()
            ->values();

        $holders = User::query()
            ->whereIn('id', \DB::table(config('permission.table_names.model_has_roles'))
                ->where('role_id', $role->id)
                ->where('model_type', User::class)
                ->pluck('model_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'user_type'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'user_type' => $user->user_type?->value ?? (string) $user->user_type,
            ])
            ->values();

        return Inertia::render('Company/People/RoleDetail', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'guard_name' => $role->guard_name,
                'is_template' => $role->branch_id === null,
                'permissions_count' => $role->permissions->count(),
            ],
            'matrix' => $matrix,
            'holders' => $holders,
            'assignableUsers' => User::query()
                ->whereNotIn('id', $holders->pluck('id'))
                ->orderBy('name')
                ->limit(200)
                ->get(['id', 'name', 'email'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
                ->values(),
        ]);
    }

    public function assign(Request $request, Role $role)
    {
        Gate::authorize('roles.manage');

        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
        ]);

        $user = User::findOrFail($validated['user_id']);

        $this->withRoleTeam($role, fn () => $user->assignRole($role));

        return back()->with('success', "{$user->name} now holds {$role->name}.");
    }

    public function revoke(Request $request, Role $role)
    {
        Gate::authorize('roles.manage');

        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
        ]);

        $user = User::findOrFail($validated['user_id']);

        $this->withRoleTeam($role, fn () => $user->removeRole($role));

        return back()->with('success', "{$role->name} removed from {$user->name}.");
    }

    /**
     * Run a role write inside the role's own team scope, and put the previous
     * one back.
     *
     * The team id is global request state. Leaving it moved means every
     * permission check afterwards looks in the wrong team and silently finds
     * nothing — the failure `CustomerProvisioningEngine` documents at the one
     * other place in this application that touches it.
     */
    protected function withRoleTeam(Role $role, callable $callback): void
    {
        $previous = getPermissionsTeamId();

        try {
            setPermissionsTeamId($role->branch_id);
            $callback();
        } finally {
            setPermissionsTeamId($previous);
        }
    }
}
