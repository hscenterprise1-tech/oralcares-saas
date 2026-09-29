<?php

namespace App\Services\Security;

use App\Models\LoginHistory;
use App\Models\User;
use App\UserType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * What access exists, and where it is not enforced.
 *
 * This builds no second permission system. Roles and permissions are Spatie's,
 * the super-admin bypass is the `Gate::before` hook in `AppServiceProvider`, and
 * sign-ins come from the `login_histories` rows the existing listener writes.
 * Everything here reads.
 *
 * The route audit is the part worth having. It walks the registered routes and
 * reports which Company routes are gated by middleware, which authorize inside
 * their controller, and which do neither — the last group being reachable by any
 * authenticated company user. That number is meant to be looked at, so it is a
 * page rather than a note in a report nobody reruns.
 */
class SecurityReviewService
{
    /**
     * Call shapes that count as authorizing inside a controller action.
     *
     * Deliberately broad: a scope helper that narrows the query to what the
     * actor may see is as much a control as an explicit gate, and counting only
     * `Gate::authorize` would report working code as unprotected.
     */
    protected const AUTH_SIGNALS = [
        'Gate::authorize',
        '$this->authorize',
        'abort_unless',
        'abort_if',
        'Gate::allows',
        'CrmPermissions::check',
        'PeopleScope::',
        'CrmScope::',
        '->can(',
    ];

    /**
     * Routes that are open on purpose — sign-in and the static state pages.
     *
     * This used to also list `forgot-password`, `reset-password`, `verify-email`,
     * `magic-link`, `otp-verify`, `pin-verify`, `two-factor-challenge`,
     * `invite-user`, `accept-invitation` and `lock-screen`. Those routes are
     * gone: each rendered a form posting to a POST route nobody had registered.
     * Keeping them named here would let them come back unnoticed and be counted
     * as deliberately public.
     */
    protected const PUBLIC_PATTERN = '#^company/(login|logout|session-timeout|session-expired|unauthorized|error/|browser-unsupported|maintenance|offline|dashboard|$)#';

    /**
     * Every Company route, and how it is protected.
     *
     * @return array<string, mixed>
     */
    public function routeAudit(): array
    {
        $sources = [];
        $middleware = 0;
        $controller = 0;
        $public = 0;
        $unprotected = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'company/')) {
                continue;
            }

            $methods = array_values(array_diff($route->methods(), ['HEAD']));
            $mutating = (bool) array_intersect($methods, ['POST', 'PUT', 'PATCH', 'DELETE']);
            $gates = $this->gatesOn($route);

            if ($gates !== []) {
                $middleware++;

                continue;
            }

            if (preg_match(self::PUBLIC_PATTERN, $uri)) {
                $public++;

                continue;
            }

            $action = $route->getActionName();

            if (! str_contains($action, '@')) {
                /* A closure route authorizes nothing it does not write itself. */
                $unprotected[] = $this->row($uri, $methods, $mutating, 'closure', $route->getName());

                continue;
            }

            if ($this->actionAuthorizes($action, $sources)) {
                $controller++;

                continue;
            }

            $unprotected[] = $this->row($uri, $methods, $mutating, $action, $route->getName());
        }

        usort($unprotected, fn (array $a, array $b) => [$b['mutating'], $a['uri']] <=> [$a['mutating'], $b['uri']]);

        return [
            'gated_by_middleware' => $middleware,
            'gated_in_controller' => $controller,
            'intentionally_public' => $public,
            'unprotected' => $unprotected,
            'unprotected_count' => count($unprotected),
            'unprotected_mutating' => count(array_filter($unprotected, fn ($r) => $r['mutating'])),
            'total' => $middleware + $controller + $public + count($unprotected),
        ];
    }

    /** The `can:` / `permission:` gates a route carries, if any. */
    protected function gatesOn(\Illuminate\Routing\Route $route): array
    {
        return array_values(array_filter(
            $route->gatherMiddleware(),
            fn ($mw) => is_string($mw) && (str_starts_with($mw, 'can:') || str_starts_with($mw, 'permission:') || str_starts_with($mw, 'role:')),
        ));
    }

    /** @return array<string, mixed> */
    protected function row(string $uri, array $methods, bool $mutating, string $action, ?string $name): array
    {
        return [
            'uri' => $uri,
            'methods' => implode('|', $methods),
            'mutating' => $mutating,
            'action' => $action,
            'name' => $name,
            'module' => $this->moduleOf($uri),
        ];
    }

    protected function moduleOf(string $uri): string
    {
        $parts = explode('/', $uri);

        return $parts[1] ?? 'company';
    }

    /** Whether the controller action contains any recognised authorization call. */
    protected function actionAuthorizes(string $action, array &$sources): bool
    {
        [$class, $method] = explode('@', $action, 2);
        $file = base_path(str_replace(['App\\', '\\'], ['app/', '/'], $class).'.php');

        if (! isset($sources[$file])) {
            $sources[$file] = is_file($file) ? file_get_contents($file) : '';
        }

        $src = $sources[$file];

        if ($src === '') {
            return false;
        }

        if (! preg_match('/function\s+'.preg_quote($method, '/').'\s*\(/', $src, $match, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        $body = substr($src, $match[0][1]);

        if (preg_match('/\n    (?:public|protected|private)\s+function\s/', $body, $next, PREG_OFFSET_CAPTURE, 10)) {
            $body = substr($body, 0, $next[0][1]);
        }

        foreach (self::AUTH_SIGNALS as $signal) {
            if (str_contains($body, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Roles, what they hold, and who holds them.
     *
     * Role assignments are scoped by `branch_id` — Spatie is configured with
     * teams — so `users_count` here is the raw pivot count rather than what
     * resolves on a Company request, which is a different and much smaller
     * number. That difference is the point, and the page says so.
     *
     * @return list<array<string, mixed>>
     */
    public function roles(): array
    {
        return Role::query()
            ->withCount(['permissions', 'users'])
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'guard' => $role->guard_name,
                'branch_id' => $role->branch_id,
                'permissions_count' => $role->permissions_count,
                'users_count' => $role->users_count,
                /*
                 * There is no `is_system` column on `roles`, so nothing in this
                 * database distinguishes a role the application depends on from
                 * one somebody made. Reported as unknown rather than guessed
                 * from the name.
                 */
                'is_system' => null,
            ])
            ->values()
            ->all();
    }

    /**
     * Permissions nothing holds.
     *
     * A permission with no role behind it is either a gate nobody can pass or a
     * leftover. Both are worth seeing; neither is deleted here.
     *
     * @return array<string, mixed>
     */
    public function permissionReconciliation(): array
    {
        $permissions = Permission::query()->with('roles:id,name')->orderBy('name')->get();

        $orphans = $permissions->filter(fn (Permission $permission) => $permission->roles->isEmpty());

        /* Permission names the router actually gates on. */
        $used = collect(Route::getRoutes())
            ->flatMap(fn ($route) => $route->gatherMiddleware())
            ->filter(fn ($mw) => is_string($mw) && str_starts_with($mw, 'can:'))
            ->map(fn ($mw) => substr($mw, 4))
            ->unique()
            ->values();

        return [
            'total' => $permissions->count(),
            'held_by_no_role' => $orphans->map(fn (Permission $permission) => $permission->name)->values(),
            'gated_on_by_routes' => $used,
            /* Gated on by a route but held by nobody: a locked door with no key. */
            'unreachable_gates' => $used->intersect($orphans->pluck('name'))->values(),
        ];
    }

    /**
     * Who has access, and how.
     *
     * @return list<array<string, mixed>>
     */
    public function users(?string $search = null): array
    {
        return User::query()
            ->whereIn('user_type', [UserType::MasterAdmin->value, UserType::CompanyUser->value])
            ->when($search, fn ($query, $term) => $query->where(function ($inner) use ($term) {
                $inner->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%");
            }))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'user_type', 'organization_id'])
            ->map(function (User $user) {
                /*
                 * Read straight off the pivot, aliased because the Spatie
                 * tables are prefixed in this application. Deliberately not
                 * through the relation: the relation applies the team scope,
                 * and the whole point here is to show assignments that exist
                 * but do not resolve on a Company request.
                 */
                $assignments = DB::table(config('permission.table_names.model_has_roles').' as mhr')
                    ->join(config('permission.table_names.roles').' as r', 'r.id', '=', 'mhr.role_id')
                    ->where('mhr.model_id', $user->id)
                    ->get(['r.name as name', 'mhr.branch_id as branch_id']);

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'user_type' => $user->user_type?->value,
                    /*
                     * The bypass, stated per user. A master admin is authorized
                     * for every ability by `Gate::before` regardless of the
                     * roles below, and a page that did not say so would make
                     * their empty role list look like no access.
                     */
                    'is_super_admin' => $user->isMasterAdmin(),
                    'roles' => $assignments->pluck('name')->unique()->values(),
                    /*
                     * Assignments are team-scoped on `branch_id`. One made
                     * against a branch does not resolve on a Company request,
                     * which is why a user can hold a 48-permission role and
                     * still arrive with none.
                     */
                    'role_branches' => $assignments->pluck('branch_id')->unique()->values(),
                    'inactive_assignments' => $assignments->filter(fn ($row) => ! in_array($row->branch_id, [null, 0], true))->count(),
                    'last_login' => LoginHistory::query()
                        ->where('user_id', $user->id)
                        ->latest('id')
                        ->value('login_time'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Recent sign-ins.
     *
     * @return list<array<string, mixed>>
     */
    public function loginHistory(int $limit = 100, ?int $userId = null): array
    {
        return LoginHistory::query()
            ->with('user:id,name,email,user_type')
            ->when($userId, fn ($query, $id) => $query->where('user_id', $id))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (LoginHistory $entry) => [
                'id' => $entry->id,
                'user' => $entry->user?->name,
                'email' => $entry->user?->email,
                'device' => $entry->device,
                'browser' => $entry->browser,
                'ip_address' => $entry->ip_address,
                'status' => $entry->status,
                'login_time' => $entry->login_time ? Carbon::parse($entry->login_time)->format('d M Y, H:i') : null,
                'logout_time' => $entry->logout_time ? Carbon::parse($entry->logout_time)->format('d M Y, H:i') : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Sessions the application is currently holding.
     *
     * Read from Laravel's own `sessions` table. Nothing here revokes one —
     * ending somebody's session is an act with consequences and no existing
     * backend supports it, so it is not invented on this page.
     *
     * @return array<string, mixed>
     */
    public function sessions(): array
    {
        if (! DB::getSchemaBuilder()->hasTable('sessions')) {
            return ['supported' => false, 'rows' => [], 'total' => 0];
        }

        $rows = DB::table('sessions')
            ->leftJoin('users', 'users.id', '=', 'sessions.user_id')
            ->orderByDesc('sessions.last_activity')
            ->limit(50)
            ->get(['sessions.id', 'sessions.user_id', 'sessions.ip_address', 'sessions.user_agent', 'sessions.last_activity', 'users.name', 'users.email']);

        return [
            'supported' => true,
            'total' => DB::table('sessions')->count(),
            'signed_in' => DB::table('sessions')->whereNotNull('user_id')->count(),
            'rows' => $rows->map(fn ($row) => [
                /* Never the session id itself — that is the credential. */
                'id' => substr(hash('sha256', (string) $row->id), 0, 12),
                'user' => $row->name,
                'email' => $row->email,
                'ip_address' => $row->ip_address,
                'user_agent' => $row->user_agent ? substr((string) $row->user_agent, 0, 120) : null,
                'last_activity' => $row->last_activity
                    ? Carbon::createFromTimestamp($row->last_activity)->format('d M Y, H:i')
                    : null,
                'is_anonymous' => $row->user_id === null,
            ])->values(),
        ];
    }
}
