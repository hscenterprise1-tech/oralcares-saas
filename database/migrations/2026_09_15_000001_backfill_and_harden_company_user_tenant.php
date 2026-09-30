<?php

use App\OrganizationRole;
use App\UserType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give every internal operator, and every lead, a provider tenant.
 *
 * Two findings, one cause, so one migration.
 *
 * T-1: `OrganizationScoped` failed open. It filtered when a tenant could be
 * resolved and applied nothing when it could not, so a company user carrying a
 * null `organization_id` was handed every organisation's rows. Four of the
 * eight company users in this database carry one. The scope is fail-closed as
 * of this change, which turns that same null from "sees everything" into "sees
 * nothing" — so the rows have to be corrected in the same breath, or half the
 * back office opens an empty product on deploy.
 *
 * T-2: four leads are stamped with a *customer* organisation as their CRM
 * tenant. `2026_09_02_170310_backfill_lead_tenant_for_legacy_rows` resolved a
 * missing tenant from the assigned user's `organization_id`, and this database
 * holds a master admin stamped with organisation 18 — a customer account. Its
 * own docblock anticipated the failure and said such a row should be "left
 * alone rather than guessed at", but the assigned-user branch fires before that
 * guard can apply. Under `CrmScope::LEVEL_ORGANIZATION` those four deals are
 * invisible to every HQ manager today.
 *
 * Nothing below is keyed to an id. The provider organisation is resolved by
 * role, and any lead whose tenant is not that organisation is corrected —
 * writing `WHERE organization_id IN (18, 19, 22)` would fix this database and
 * no other, and would say nothing about what the rule is.
 */
return new class extends Migration
{
    public function up(): void
    {
        $providerId = $this->providerOrganizationId();

        if ($providerId === null) {
            /*
             * No provider row means this is a fresh or partially seeded
             * database with nothing to correct yet. Guessing one here would
             * stamp real rows with an arbitrary tenant, which is the defect
             * being repaired. The fail-closed scope and the `Lead` guard both
             * still apply, so nothing is left unprotected by skipping.
             */
            return;
        }

        $this->stampInternalOperators($providerId);
        $this->reTenantLeadsOwnedByCustomers($providerId);
    }

    public function down(): void
    {
        /*
         * Deliberately irreversible.
         *
         * Restoring a null tenant to an operator, or handing a deal back to the
         * customer it was sold to, would reopen the finding this migration
         * closes. `lead_tenant_backfill_log` records which leads moved, so the
         * change is auditable without being automatically undoable.
         */
    }

    /** The one organisation that sells the software, by role rather than by id. */
    protected function providerOrganizationId(): ?int
    {
        $id = DB::table('organizations')
            ->where('role', OrganizationRole::Provider->value)
            ->orderBy('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Every internal operator carries the provider tenant.
     *
     * Company users only. A master admin with a null tenant is left alone: they
     * are the platform operator, `OrganizationScope` exempts them by rule, and
     * stamping one would be inventing a boundary they do not have.
     *
     * A master admin stamped with a *customer* tenant is a different matter and
     * is cleared, because that is what produced T-2 — the lead backfill read it
     * as the tenant of everything they touched.
     */
    protected function stampInternalOperators(int $providerId): void
    {
        DB::table('users')
            ->where('user_type', UserType::CompanyUser->value)
            ->whereNull('organization_id')
            ->update(['organization_id' => $providerId, 'updated_at' => now()]);

        $customerOrganizationIds = DB::table('organizations')
            ->where('role', '!=', OrganizationRole::Provider->value)
            ->pluck('id');

        if ($customerOrganizationIds->isNotEmpty()) {
            DB::table('users')
                ->where('user_type', UserType::MasterAdmin->value)
                ->whereIn('organization_id', $customerOrganizationIds)
                ->update(['organization_id' => null, 'updated_at' => now()]);
        }
    }

    /**
     * A lead belongs to the organisation selling it, never to the one buying it.
     *
     * `leads.customer_organization_id` is where the account a deal converted
     * into is recorded; `leads.organization_id` is the CRM tenant working it.
     * Conflating the two is what happened here.
     */
    protected function reTenantLeadsOwnedByCustomers(int $providerId): void
    {
        $misTenanted = DB::table('leads')
            ->where(function ($query) use ($providerId) {
                $query->whereNull('organization_id')->orWhere('organization_id', '!=', $providerId);
            })
            ->pluck('id');

        if ($misTenanted->isEmpty()) {
            return;
        }

        DB::table('leads')->whereIn('id', $misTenanted)->update([
            'organization_id' => $providerId,
            'updated_at' => now(),
        ]);

        /* The same audit table the original backfill wrote, for the same reason. */
        if (DB::getSchemaBuilder()->hasTable('lead_tenant_backfill_log')) {
            DB::table('lead_tenant_backfill_log')->insert(
                $misTenanted->map(fn ($id) => [
                    'lead_id' => $id,
                    'organization_id' => $providerId,
                    'created_at' => now(),
                ])->all()
            );
        }
    }
};
