<?php

namespace App\Support\ClinicAccess;

use App\ClinicPermission;
use App\ClinicRole;
use App\Models\Clinic;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\OwnerMode\OwnerMode;
use App\Services\Provisioning\EntitlementResolver;
use App\Support\Specialty\SpecialtyRegistry;
use Illuminate\Support\Facades\Route;

/**
 * The one answer to "may this user, here, right now, have this?" for the
 * Clinic Portal's navigation, quick actions and dashboard widgets.
 *
 * Everything is resolved once per request from the server's own state:
 * the session's clinic and branch (`ClinicContext`), the user's effective
 * permissions at that branch, the plan (`EntitlementResolver`), the clinic's
 * specialties and capabilities, and Owner Mode. A registry entry states its
 * needs as a requirement array and `allows()` checks every one of them; no
 * caller adds rules of its own, so navigation, actions and widgets can never
 * disagree about what a user may see.
 *
 * Requirement keys (all optional, all must hold):
 *   permissions      list, any one granted
 *   permissions_all  list, every one granted
 *   module           plan module, with `EnsureClinicEntitled`'s rule
 *   clinic_module    a separately sold module (`ClinicModules`: override, plan or add-on, default)
 *   capabilities     list, any one granted by the plan
 *   specialties      list, any one practised by the clinic
 *   extensions       list, any one specialty extension available here
 *   specialty_flag   a registry flag the specialties carry
 *   owner_mode       true: the actual owner, with Owner Mode active
 *   route            a route name that must be registered
 *   branch           'active': needs a chosen branch
 *                    'clinic_wide': needs access to every branch
 *   multi_branch     true: the clinic has more than one branch for this user
 *   practitioner     true: the user is a practitioner in this clinic
 *   workspace        list, any one of the user's workspaces (see `workspaces()`)
 *   no_workspace     true: the user has no role workspace (a minimal home)
 *   workspace_all    list, every one of them the user's
 *   not_workspace    list, none of which the user may work in
 *   unless           a requirement of its own: holds only when that one does NOT
 *
 * Owner Mode adds owner-level entries; it never lifts a plan, capability,
 * specialty, branch or tenant rule.
 */
class ClinicAccess
{
    /** Where the answer is kept: on the request itself, so it never outlives it. */
    private const MEMO = 'clinic_access.state';

    public function __construct(
        protected ClinicContext $context,
        protected OwnerMode $ownerMode,
        protected EntitlementResolver $entitlements,
        protected ClinicCapabilities $capabilities,
        protected SpecialtyRegistry $registry,
        protected ClinicModules $modules,
    ) {}

    /**
     * @param  array<string, mixed>  $requirement
     */
    public function allows(array $requirement): bool
    {
        if (! $this->context->hasClinic()) {
            return false;
        }

        $state = $this->state();

        if (isset($requirement['route']) && ! Route::has($requirement['route'])) {
            return false;
        }

        if (! empty($requirement['unless']) && $this->allows($requirement['unless'])) {
            return false;
        }

        if (! empty($requirement['permissions']) && array_intersect($requirement['permissions'], $state['permissions']) === []) {
            return false;
        }

        if (! empty($requirement['permissions_all']) && array_diff($requirement['permissions_all'], $state['permissions']) !== []) {
            return false;
        }

        if (isset($requirement['module']) && $state['entitlement']['has_subscription']
            && ! in_array($requirement['module'], $state['entitlement']['modules'], true)) {
            return false;
        }

        if (isset($requirement['clinic_module']) && ! ($state['clinic_modules'][$requirement['clinic_module']] ?? false)) {
            return false;
        }

        if (! empty($requirement['capabilities']) && array_intersect($requirement['capabilities'], $state['capabilities']) === []) {
            return false;
        }

        if (! empty($requirement['specialties']) && array_intersect($requirement['specialties'], $state['specialties']) === []) {
            return false;
        }

        if (! empty($requirement['extensions']) && array_intersect($requirement['extensions'], $state['extensions']) === []) {
            return false;
        }

        if (! empty($requirement['specialty_flag']) && ! $this->registry->hasFlag($state['specialties'], $requirement['specialty_flag'])) {
            return false;
        }

        if (! empty($requirement['owner_mode']) && ! ($state['is_owner'] && $state['owner_mode'])) {
            return false;
        }

        if (($requirement['branch'] ?? null) === 'active' && ! $state['has_active_branch']) {
            return false;
        }

        if (($requirement['branch'] ?? null) === 'clinic_wide' && ! $state['clinic_wide']) {
            return false;
        }

        if (! empty($requirement['multi_branch']) && $state['branch_count'] < 2) {
            return false;
        }

        if (! empty($requirement['practitioner']) && ! $state['practitioner']) {
            return false;
        }

        if (! empty($requirement['workspace']) && array_intersect($requirement['workspace'], $state['workspaces']) === []) {
            return false;
        }

        if (! empty($requirement['no_workspace']) && $state['workspaces'] !== []) {
            return false;
        }

        if (! empty($requirement['workspace_all']) && array_diff($requirement['workspace_all'], $state['workspaces']) !== []) {
            return false;
        }

        if (! empty($requirement['not_workspace']) && array_intersect($requirement['not_workspace'], $state['workspaces']) !== []) {
            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return $this->state()['permissions'];
    }

    public function isOwner(): bool
    {
        return $this->state()['is_owner'];
    }

    public function ownerModeActive(): bool
    {
        return $this->state()['owner_mode'];
    }

    /**
     * @return array{has_subscription: bool, modules: list<string>}
     */
    public function entitlement(): array
    {
        return $this->state()['entitlement'];
    }

    /**
     * @return list<string>
     */
    public function specialties(): array
    {
        return $this->state()['specialties'];
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return $this->state()['capabilities'];
    }

    /**
     * The specialty extensions available here (registry keys).
     *
     * @return list<string>
     */
    public function extensions(): array
    {
        return $this->state()['extensions'];
    }

    /** Whether a separately sold module (`config/clinic_modules.php`) is on for this clinic. */
    public function moduleEnabled(string $module): bool
    {
        return $this->state()['clinic_modules'][$module] ?? false;
    }

    /**
     * Every separately sold module and whether it is on here.
     *
     * @return array<string, bool>
     */
    public function clinicModules(): array
    {
        return $this->state()['clinic_modules'];
    }

    public function hasExtension(string $extension): bool
    {
        return in_array($extension, $this->extensions(), true);
    }

    /** Whether the specialties here carry a registry flag. */
    public function hasSpecialtyFlag(string $flag): bool
    {
        return $this->registry->hasFlag($this->specialties(), $flag);
    }

    public function clinicWide(): bool
    {
        return $this->state()['clinic_wide'];
    }

    /**
     * The clinic staff roles the user holds at the active branch.
     *
     * @return list<string>
     */
    public function roles(): array
    {
        return $this->state()['roles'];
    }

    /**
     * The workspaces this user works in, primary first (see `resolveWorkspaces`).
     *
     * @return list<string>
     */
    public function workspaces(): array
    {
        return $this->state()['workspaces'];
    }

    /** The workspace a user lands in; null for a role with no workspace (a minimal home). */
    public function primaryWorkspace(): ?string
    {
        return $this->state()['workspaces'][0] ?? null;
    }

    public function clinic(): ?Clinic
    {
        return $this->context->clinic();
    }

    /**
     * @return array{permissions: list<string>, roles: list<string>, is_owner: bool, owner_mode: bool, entitlement: array{has_subscription: bool, modules: list<string>}, specialties: list<string>, capabilities: list<string>, extensions: list<string>, has_active_branch: bool, clinic_wide: bool, branch_count: int, practitioner: bool, workspaces: list<string>}
     */
    private function state(): array
    {
        $memo = request()->attributes->get(self::MEMO);
        if (is_array($memo)) {
            return $memo;
        }

        $clinic = $this->context->clinic();
        $membership = $this->context->membership();
        $user = $this->context->user() ?? auth()->user();

        $raw = $clinic ? $this->entitlements->forClinic($clinic) : ['has_subscription' => false, 'modules' => []];
        $entitlement = ['has_subscription' => (bool) $raw['has_subscription'], 'modules' => array_values($raw['modules'])];

        $isOwner = (bool) $membership?->is_primary_owner;

        $state = [
            'permissions' => $user instanceof User ? $this->resolvePermissions($user, $isOwner) : [],
            'roles' => $user instanceof User
                ? array_values(array_intersect($user->getRoleNames()->all(), array_column(ClinicRole::cases(), 'value')))
                : [],
            'is_owner' => $isOwner,
            'owner_mode' => $isOwner && $this->ownerMode->isActive(),
            'entitlement' => $entitlement,
            'specialties' => $specialties = ($clinic ? $this->capabilities->specialties($clinic, $entitlement, $this->context->branch()) : []),
            'capabilities' => $capabilities = $this->capabilities->capabilities($entitlement),
            'extensions' => $this->capabilities->extensions($specialties, $capabilities),
            'has_active_branch' => $this->context->branch() !== null,
            'clinic_wide' => $membership !== null && ($membership->is_primary_owner || $membership->has_all_branches_access),
            'branch_count' => $membership ? $membership->accessibleActiveBranches()->limit(2)->pluck('branches.id')->count() : 0,
            'practitioner' => $practitioner = $this->context->currentEmployee()?->practitionerProfile !== null,
            'clinic_modules' => $clinic ? $this->modules->all($clinic, $raw['modules']) : [],
        ];
        $state['workspaces'] = self::resolveWorkspaces($state['permissions'], $isOwner, $practitioner);
        request()->attributes->set(self::MEMO, $state);

        return $state;
    }

    /**
     * The workspaces a user's permissions compose, primary first. Each comes
     * from what the user may do here, never from a role's name, so one person
     * can hold several: a doctor who also covers reception and billing works
     * in Today, the Front Desk and Billing.
     *
     * - Command Center: the owner, or whoever manages the clinic's staff.
     * - Front Desk: `front_office.operate`.
     * - Today: a practitioner who may read clinical records.
     * - Billing: may see bills and take payments.
     *
     * @param  list<string>  $permissions
     * @return list<string>
     */
    public static function resolveWorkspaces(array $permissions, bool $isOwner, bool $practitioner): array
    {
        $has = fn (ClinicPermission $permission) => in_array($permission->value, $permissions, true);

        return array_keys(array_filter([
            'command_center' => $isOwner || $has(ClinicPermission::StaffManage),
            'front_desk' => $has(ClinicPermission::FrontOffice),
            'today' => $practitioner && $has(ClinicPermission::ClinicalView),
            'billing' => $has(ClinicPermission::BillingView) && $has(ClinicPermission::PaymentCollect),
        ]));
    }

    /**
     * The clinic permissions the user holds at the active branch: all of them
     * for the primary owner (as `User::hasClinicPermission` decides), else
     * what their roles at this branch grant.
     *
     * @return list<string>
     */
    private function resolvePermissions(User $user, bool $isOwner): array
    {
        if ($isOwner || $user->isMasterAdmin()) {
            return ClinicPermission::values();
        }

        $granted = $user->getAllPermissions()->pluck('name')->all();

        return array_values(array_intersect(ClinicPermission::values(), $granted));
    }
}
