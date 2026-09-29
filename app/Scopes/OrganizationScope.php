<?php

namespace App\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Which organisation's rows a query may see.
 *
 * This scope used to fail *open*. Its whole body was
 * `if (auth()->check() && auth()->user()->organization_id) { ... }`, so the
 * filter applied when a tenant could be resolved and was simply absent when one
 * could not — a guest, or an authenticated user carrying a null
 * `organization_id`, received every organisation's rows rather than none. Four
 * of eight company users held a null tenant at the time this was written, so
 * for half the back office the boundary was not there at all.
 *
 * A boundary that disappears exactly when it cannot be computed is not a
 * boundary. The order below is what replaces it, and every branch is a decision
 * rather than a fallthrough:
 *
 *   1. System context          no filter — opened explicitly, by name (`asSystem`)
 *   2. Console with no user    no filter — artisan, queue workers, scheduler, seeders
 *   3. Master admin            no filter — the platform operator has no tenant
 *   4. Tenant resolved         where organization_id = <theirs>
 *   5. Anything else           where 1 = 0
 *
 * Rule 5 is the fix. It is deliberately last, so that adding a future case
 * means adding a branch above it rather than widening it.
 *
 * On rule 3: a master admin already passes every ability through the
 * `Gate::before` hook in `AppServiceProvider`, and `CrmScope::LEVEL_ALL` already
 * hands them every CRM row. Filtering them here would not have made the product
 * safer — it would have made the menu and the data disagree with the gate, and
 * two of the master admins in this database carry a null tenant, so rule 5
 * would have shown them an empty product.
 */
class OrganizationScope implements Scope
{
    /**
     * Whether a caller has explicitly opened a system context.
     *
     * Static rather than container-bound because it has to survive inside a
     * queued job's own container and be impossible to set by accident from a
     * request. It is never left on: `asSystem()` restores the previous value in
     * a `finally`.
     */
    protected static bool $systemContext = false;

    /**
     * Run a callback with the organisation boundary lifted.
     *
     * The one HTTP caller that needs this is the WhatsApp webhook. It is public
     * and unauthenticated by design — Meta posts to it — and the payload
     * handler reads and writes `Lead` and `MarketingContact` to turn an inbound
     * message into a lead. Under rule 5 that path would have found nothing,
     * created nothing, and reported success: inbound WhatsApp lead capture
     * would have stopped silently, which is the worst way for a security change
     * to fail.
     *
     * So the bypass is explicit and greppable rather than implied by "there is
     * no user here". A reviewer can list every place the boundary is lifted by
     * searching for this method.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function asSystem(callable $callback): mixed
    {
        $previous = static::$systemContext;
        static::$systemContext = true;

        try {
            return $callback();
        } finally {
            static::$systemContext = $previous;
        }
    }

    /** Whether a system context is currently open. */
    public static function inSystemContext(): bool
    {
        return static::$systemContext;
    }

    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (static::$systemContext) {
            return;
        }

        /*
         * Console — artisan commands, queue workers, the scheduler, seeders and
         * migrations — acts as the system across every tenant and has no user
         * to resolve one from, so the boundary cannot apply and must not fail
         * it closed. The brief is explicit that it is HTTP requests which must
         * always fail closed, and that console gets a documented bypass; this
         * is that bypass.
         *
         * It is deliberately the whole console rather than only the
         * unauthenticated part of it. I tried the narrower rule — lift only
         * when nobody is acting — on the theory that a signed-in user is
         * signed in wherever the request came from. It is a defensible rule and
         * it is the wrong one here: roughly fifty existing tests build fixtures
         * with users that carry no tenant, and narrowing the bypass turned all
         * of them from "scope does not apply" into "scope returns nothing".
         * That is a migration of the entire test suite onto tenanted fixtures,
         * which is a change worth making deliberately and not as a side effect
         * of a security patch.
         *
         * `enforce_organization_scope` is how a test opts back in, and the
         * tests that assert the boundary do exactly that. The helper is
         * `entersOrganizationScope()` in `tests/Pest.php`. This mirrors
         * `TenantScope`, which has carried the same switch under
         * `enforce_tenant_scope_in_tests` since it was written.
         */
        if (app()->runningInConsole() && ! app()->bound('enforce_organization_scope')) {
            return;
        }

        $user = auth()->user();

        if ($user instanceof User && $user->isMasterAdmin()) {
            return;
        }

        $organizationId = $user?->organization_id;

        if ($organizationId) {
            $builder->where($model->getTable().'.organization_id', $organizationId);

            return;
        }

        /*
         * No tenant could be resolved, so no rows are returned.
         *
         * `whereRaw('1 = 0')` rather than `whereNull('organization_id')`: rows
         * with a null tenant are not this user's rows either, and a filter that
         * returns *some* rows to a caller with no tenant is the same defect one
         * size smaller.
         */
        $builder->whereRaw('1 = 0');
    }
}
