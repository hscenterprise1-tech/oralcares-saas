<?php

namespace App\Traits;

use App\Models\User;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;

trait OrganizationScoped
{
    /**
     * Boot the organization scoped trait for a model.
     *
     * The rule itself lives in `OrganizationScope` rather than in a closure
     * here. A closure cannot be named in `withoutGlobalScope()` except by the
     * string `'organization'`, cannot be unit-tested on its own, and — the
     * reason that mattered — made the fail-open branch invisible to anyone
     * reading the model. The scope class states all five branches in order.
     */
    protected static function bootOrganizationScoped(): void
    {
        static::addGlobalScope(new OrganizationScope);

        /*
         * Stamp the tenant on creation.
         *
         * Master admins are excluded, and that is the second half of finding
         * T-2. The lead backfill resolved a missing tenant from the assigned
         * user's `organization_id`, and this database holds a master admin
         * stamped with organisation 18 — a *customer* account — so four leads
         * were written with a customer as their CRM tenant. A master admin is
         * the platform operator; their own `organization_id` is an accident of
         * seeding, never the tenant a record belongs to.
         *
         * A record created by a master admin therefore keeps whatever tenant
         * the caller set explicitly, and the `provider` guard on `Lead` catches
         * it if nothing did.
         */
        static::creating(function (Model $model) {
            if ($model->organization_id) {
                return;
            }

            $user = auth()->user();

            if (! $user || ($user instanceof User && $user->isMasterAdmin())) {
                return;
            }

            if ($user->organization_id) {
                $model->organization_id = $user->organization_id;
            }
        });
    }

    /**
     * Query across every organisation.
     *
     * Kept for the handful of callers that legitimately cross the boundary —
     * provisioning, entitlement checks and the workflow engine, which act on a
     * tenant other than the caller's own. Prefer `OrganizationScope::asSystem()`
     * for a whole code path; this is for a single query.
     */
    public static function withoutOrganizationScope()
    {
        return static::withoutGlobalScope(OrganizationScope::class);
    }
}
