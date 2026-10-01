<?php

namespace App\Support\ClinicAccess;

use App\ClinicPermission;
use App\ClinicRole;
use App\Models\Clinic;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\OwnerMode\OwnerMode;
use App\Services\Provisioning\EntitlementResolver;
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
 *   capabilities     list, any one granted by the plan
 *   specialties      list, any one practised by the clinic
 *   owner_mode       true: the actual owner, with Owner Mode active
 *   route            a route name that must be registered
 *   branch           'active': needs a chosen branch
 *                    'clinic_wide': needs access to every branch
 *   multi_branch     true: the clinic has more than one branch for this user
 *   practitioner     true: the user is a practitioner in this clinic
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

        if (! empty($requirement['capabilities']) && array_intersect($requirement['capabilities'], $state['capabilities']) === []) {
            return false;
        }

        if (! empty($requirement['specialties']) && array_intersect($requirement['specialties'], $state['specialties']) === []) {
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

    public function clinic(): ?Clinic
    {
        return $this->context->clinic();
    }

    /**
     * @return array{permissions: list<string>, roles: list<string>, is_owner: bool, owner_mode: bool, entitlement: array{has_subscription: bool, modules: list<string>}, specialties: list<string>, capabilities: list<string>, has_active_branch: bool, clinic_wide: bool, branch_count: int, practitioner: bool}
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
            'specialties' => $clinic ? $this->capabilities->specialties($clinic, $entitlement) : [],
            'capabilities' => $this->capabilities->capabilities($entitlement),
            'has_active_branch' => $this->context->branch() !== null,
            'clinic_wide' => $membership !== null && ($membership->is_primary_owner || $membership->has_all_branches_access),
            'branch_count' => $membership ? $membership->accessibleActiveBranches()->limit(2)->pluck('branches.id')->count() : 0,
            'practitioner' => $this->context->currentEmployee()?->practitionerProfile !== null,
        ];
        request()->attributes->set(self::MEMO, $state);

        return $state;
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
