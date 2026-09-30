<?php

namespace App\Http\Middleware;

use App\ClinicPermission;
use App\ClinicRole;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\OwnerMode\OwnerMode;
use App\Services\Provisioning\EntitlementResolver;
use App\Support\ClinicNavigation;
use App\Support\ClinicPortalErrors;
use App\Support\CrmPermissions;
use App\Support\CrmScope;
use App\UserType;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The most branches shared on every clinic page; the switcher searches
     * for the rest.
     */
    public const SHARED_BRANCH_LIMIT = 50;

    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Every permission name the navigation may legitimately test for.
     *
     * The granted names, plus each canonical CRM permission this user actually
     * satisfies. Nothing is widened: `CrmPermissions::check()` is the same call
     * the gates make, so a name only appears here if the route behind it would
     * let the user in.
     *
     * @return Collection<int, string>
     */
    protected function resolvedPermissions(mixed $user): Collection
    {
        $granted = method_exists($user, 'getAllPermissions')
            ? $user->getAllPermissions()->pluck('name')
            : collect();

        $canonical = collect(array_keys(CrmPermissions::ALIASES))
            ->filter(fn (string $permission) => CrmPermissions::check($user, $permission));

        return $granted->concat($canonical)->unique()->values();
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->user_type ? match ($user->user_type) {
                        UserType::MasterAdmin => 'Master Admin',
                        UserType::CompanyUser => 'Company Admin',
                        UserType::ClinicUser => 'Clinic Admin',
                    } : 'Master Admin',
                    'user_type' => $user->user_type?->value,
                    'roles' => method_exists($user, 'getRoleNames') ? $user->getRoleNames() : [],
                    /*
                     * The authorization bypass, stated once.
                     *
                     * `Gate::before` lets a master admin through every ability
                     * regardless of what their roles grant, so the navigation
                     * has to know the same thing or it hides modules the routes
                     * would happily serve. It did exactly that: the frontend
                     * inferred super-admin status from role *names*
                     * (`Super Admin`, `master-admin`) that no role in this
                     * database is called, and otherwise fell back to "an empty
                     * permission list means show everything". A master admin
                     * holds no roles, so the first test failed; and the list
                     * below is no longer empty for them, so the fallback
                     * stopped firing too — and every module whose permission is
                     * outside `CrmPermissions::ALIASES` (Marketing, Finance,
                     * Payroll, Notifications) vanished from their menu.
                     *
                     * This is the same signal `Gate::before` reads, so the two
                     * layers can no longer disagree. Nothing is granted: the
                     * permission list below stays exactly what the user really
                     * holds.
                     */
                    'is_super_admin' => $user instanceof User && $user->isMasterAdmin(),
                    /*
                     * The permissions granted, plus the canonical CRM names they
                     * satisfy.
                     *
                     * Backend authorization resolves legacy names through
                     * `CrmPermissions::ALIASES` — a role holding `crm.view` passes
                     * a gate asking for `view-crm`. The sidebar and the command
                     * palette do a plain `permissions.includes(name)` against this
                     * list, which knows nothing about that map, so six seeded roles
                     * (CEO, Operations, Sales, CRM, Support, Operations Manager)
                     * would have been shown an empty CRM menu while being allowed
                     * through every route behind it.
                     *
                     * Resolving here keeps one source of truth: the map stays in
                     * PHP, and the answer the navigation reads is the same answer
                     * the gate would give.
                     */
                    'permissions' => $this->resolvedPermissions($user),
                ] : null,
            ],
            'crm' => $this->crmContext($request),
            'clinic' => fn () => $this->clinicContext($request),
            'portalLinks' => fn () => $this->clinicPortalLinks($request),
            /*
             * `success` is the key the application actually uses.
             *
             * 162 `->with('success', ...)` calls across 50 controllers were
             * being flashed to a session key that was never shared with Inertia,
             * so every confirmation the backend sent was dropped before it
             * reached a page. `message` was shared instead and nothing sets it.
             * Both stay, so nothing that might rely on `message` breaks.
             */
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'message' => fn () => $request->session()->get('message'),
                'error' => fn () => $request->session()->get('error'),                /*
                 * Added with the trials work: a trial whose environment failed
                 * to build flashes a warning rather than an error, because the
                 * trial itself was created and is retryable. Without this it
                 * was flashed to a key nothing read, so the rep saw nothing at
                 * all — the same defect `success` had.
                 */
                'warning' => fn () => $request->session()->get('warning'),
            ],
        ]);
    }

    /**
     * CRM navigation context.
     *
     * The sidebar shows a management structure or a personal work structure
     * depending on the acting user, and that decision has to come from the
     * server: it is the same CrmScope signal the data queries use, so the menu
     * can never offer a surface the backend would refuse.
     *
     * Both values are lazy closures — they are only evaluated when Inertia
     * actually serialises them, so non-CRM pages pay nothing, and the counts
     * are skipped entirely on partial reloads that do not request them.
     *
     * @return array<string, mixed>
     */
    protected function crmContext(Request $request): array
    {
        $user = $request->user();

        if (! $user || ! CrmPermissions::isCompanyPortalUser($user)) {
            return ['is_crm_user' => false];
        }

        return [
            'is_crm_user' => CrmPermissions::checkAny($user, 'view-crm', 'view-leads') || $user->isMasterAdmin(),
            'is_manager' => CrmScope::isManager($user),
            'scope' => CrmScope::level($user),
        ];
    }

    /**
     * Clinic navigation and active context.
     *
     * Evaluated lazily to prevent database lookups on company routes.
     *
     * @return array<string, mixed>|null
     */
    protected function clinicContext(Request $request): ?array
    {
        $user = $request->user();
        if (! $user || ! app()->bound(ClinicContext::class)) {
            return null;
        }

        /** @var ClinicContext $context */
        $context = app(ClinicContext::class);
        if (! $context->hasClinic()) {
            return null;
        }

        $clinic = $context->clinic();
        $branch = $context->branch();
        $membership = $context->membership();

        /*
         * Bounded. A clinic with hundreds of branches must not ship all of
         * them on every page. The first SHARED_BRANCH_LIMIT are enough for
         * the switcher to open instantly; beyond that it searches the
         * `clinic.branches.index` endpoint, and the count tells it to.
         */
        $accessibleBranches = [];
        $accessibleBranchCount = 0;
        if ($membership) {
            $branchQuery = $membership->accessibleActiveBranches();
            $accessibleBranchCount = (clone $branchQuery)->count();
            $accessibleBranches = $branchQuery
                ->orderByDesc('branches.is_main')
                ->orderBy('branches.name')
                ->limit(self::SHARED_BRANCH_LIMIT)
                ->get(['branches.id', 'branches.name', 'branches.slug', 'branches.is_main'])
                ->toArray();
        }

        $entitlement = app(EntitlementResolver::class)->forClinic($clinic);
        $isOwner = (bool) ($membership?->is_primary_owner);
        $permissions = $this->clinicPermissions($user, $isOwner);

        return [
            'id' => $clinic->id,
            'name' => $clinic->name,
            'branding_name' => $clinic->branding_name,
            'logo_url' => $clinic->logo_url,
            'slug' => $clinic->slug,
            'organization_id' => $clinic->organization_id,
            'active_branch' => $branch ? [
                'id' => $branch->id,
                'name' => $branch->name,
                'slug' => $branch->slug,
                'is_main' => (bool) $branch->is_main,
                'display_name' => $branch->display_name,
                'logo_url' => $branch->logo_url,
            ] : null,
            'accessible_branches' => $accessibleBranches,
            'accessible_branch_count' => $accessibleBranchCount,
            'is_owner' => $isOwner,
            'has_all_branches_access' => (bool) ($membership?->has_all_branches_access),
            /*
             * What the tenant's plan grants, not what a row in `enabled_modules`
             * happens to say — `EntitlementResolver` is the one place the app
             * already asks this (`EnforceSubscriptionWindow`, trial telemetry),
             * so the clinic sidebar can't drift from what the subscription gate
             * itself would allow.
             */
            'enabled_modules' => $entitlement['modules'],
            'has_subscription' => (bool) $entitlement['has_subscription'],
            /*
             * What this user may do here, resolved on the server: the
             * effective clinic permissions at the active branch (everything
             * for the primary owner, as `hasClinicPermission` decides), the
             * roles held at that branch, and the navigation those two and
             * the plan allow. The portal reads these for presentation only;
             * every route still checks for itself.
             */
            'permissions' => $permissions,
            'roles' => $isOwner ? ['Owner'] : $this->clinicRoles($user),
            // The staff roles actually held here, owner or not, so an owner
            // who is also a doctor reads as "Doctor · Clinic Owner".
            'staff_roles' => $this->clinicRoles($user),
            // Whether Owner Mode is offered to this user and whether it is on.
            // Presentation only: owner-only routes use `clinic_owner_mode`.
            'owner_mode' => app(OwnerMode::class)->status(),
            'navigation' => ClinicNavigation::for(
                $permissions,
                ['has_subscription' => (bool) $entitlement['has_subscription'], 'modules' => array_values($entitlement['modules'])],
                array_filter(['clinic' => $request->route('clinic')], fn ($value) => is_string($value)),
            ),
        ];
    }

    /**
     * The Clinic Portal's own endpoints, built by the server so they are right
     * in path mode (`/clinic/...?clinic=slug`) and domain mode
     * (`slug.example.com/...`) alike. Relative, so a call never leaves the
     * host the session cookie belongs to. Null outside the portal.
     *
     * @return array<string, string>|null
     */
    protected function clinicPortalLinks(Request $request): ?array
    {
        if (! ClinicPortalErrors::isClinicPortalRequest($request) || ! Route::has('clinic.login')) {
            return null;
        }

        $clinic = app(ClinicContext::class)->requestedClinicSlug($request);
        $parameters = is_string($clinic) && $clinic !== '' ? ['clinic' => $clinic] : [];

        return [
            'signIn' => route('clinic.login', $parameters, false),
            'signOut' => route('clinic.logout', $parameters, false),
            'forgotPassword' => route('clinic.password.request', $parameters, false),
            'dashboard' => route('clinic.dashboard', $parameters, false),
            'branches' => route('clinic.branches.index', $parameters, false),
            'branchSwitch' => route('clinic.branches.switch', $parameters, false),
            'ownerMode' => route('clinic.owner-mode.store', $parameters, false),
            'notifications' => route('api.notifications.index', [], false),
            'notificationsUnreadCount' => route('api.notifications.unreadCount', [], false),
            'notificationsMarkAllRead' => route('api.notifications.markAllAsRead', [], false),
        ];
    }

    /**
     * The clinic permissions this user holds at the active branch.
     *
     * @return list<string>
     */
    protected function clinicPermissions(User $user, bool $isOwner): array
    {
        if ($isOwner || $user->isMasterAdmin()) {
            return ClinicPermission::values();
        }

        $granted = $user->getAllPermissions()->pluck('name')->all();

        return array_values(array_intersect(ClinicPermission::values(), $granted));
    }

    /**
     * The clinic role names this user holds at the active branch.
     *
     * @return list<string>
     */
    protected function clinicRoles(User $user): array
    {
        return array_values(array_intersect(
            $user->getRoleNames()->all(),
            array_column(ClinicRole::cases(), 'value'),
        ));
    }
}
