<?php

namespace App\Policies\Concerns;

use App\Models\User;
use App\Support\CrmPermissions;

/**
 * Thin policy-facing wrapper over {@see CrmPermissions}, which holds the
 * canonical permission names and their legacy aliases.
 */
trait ChecksCrmPermissions
{
    protected function hasCrmPermission(User $user, string $permission): bool
    {
        return CrmPermissions::check($user, $permission);
    }

    protected function hasAnyCrmPermission(User $user, string ...$permissions): bool
    {
        return CrmPermissions::checkAny($user, ...$permissions);
    }

    protected function isCompanyPortalUser(User $user): bool
    {
        return CrmPermissions::isCompanyPortalUser($user);
    }
}
