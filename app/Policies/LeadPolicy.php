<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;
use App\Policies\Concerns\ChecksCrmPermissions;
use App\UserType;

class LeadPolicy
{
    use ChecksCrmPermissions;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasAnyCrmPermission($user, 'view-leads', 'view-crm');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Lead $lead): bool
    {
        if (! $this->hasAnyCrmPermission($user, 'view-leads', 'view-crm')) {
            return false;
        }

        return $this->sharesTenantWith($user, $lead);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->hasAnyCrmPermission($user, 'create leads', 'view-leads', 'view-crm');
    }

    /**
     * Determine whether the user can update the model.
     *
     * Reading a deal and changing one are different rights. This accepted
     * `view-leads` and `view-crm` as fallbacks, so anybody who could open the
     * CRM could rewrite every field on every deal, move its stage and delete
     * its tasks. Only a real write permission satisfies it now.
     *
     * One seeded role changes behaviour: Operations Manager holds CRM read and
     * no CRM write, and becomes a viewer. That is the intent of separating the
     * two, not a regression.
     */
    public function update(User $user, Lead $lead): bool
    {
        if (! $this->hasCrmPermission($user, 'edit own leads')) {
            return false;
        }

        return $this->sharesTenantWith($user, $lead);
    }

    /**
     * Deletion stays master-admin only — leads carry commercial history and
     * soft-deleting one removes it from every downstream report.
     */
    public function delete(User $user, Lead $lead): bool
    {
        $userTypeVal = is_object($user->user_type) && isset($user->user_type->value)
            ? $user->user_type->value
            : (string) $user->user_type;

        if ($userTypeVal !== UserType::MasterAdmin->value) {
            return false;
        }

        return $this->sharesTenantWith($user, $lead);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Lead $lead): bool
    {
        return $this->update($user, $lead);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Lead $lead): bool
    {
        return $this->delete($user, $lead);
    }

    /**
     * Organization guard.
     *
     * `leads.organization_id` is the CRM tenant — the organisation working the
     * deal, stamped at creation — and NOT the customer organisation, which is
     * `customer_organization_id`. An earlier docblock here said the opposite,
     * and the code was written to match the docblock: a null on either side
     * meant "matches everybody", which handed every open deal in the database
     * to any authenticated CRM user, because null was the normal state for
     * legacy rows.
     *
     * Strict equality now. Rows that predate the tenant stamp were given one
     * by `backfill_lead_tenant_for_legacy_rows`, and `Lead::saving()` stops
     * new ones appearing. A row that still somehow carries no tenant is
     * reachable only by a master admin, who bypasses policies entirely — which
     * is the right place for an unattributable record to surface.
     */
    protected function sharesTenantWith(User $user, Lead $lead): bool
    {
        $userTenant = $user->resolveOrganizationId();

        if (! $userTenant || ! $lead->organization_id) {
            return false;
        }

        return (int) $userTenant === (int) $lead->organization_id;
    }
}
