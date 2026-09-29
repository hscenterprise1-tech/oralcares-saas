<?php

namespace App\Policies;

use App\Models\CompanyQuotation;
use App\Models\User;
use App\Policies\Concerns\ChecksCrmPermissions;
use App\UserType;

/**
 * Who may read a quotation, who may change one, and who may approve one.
 *
 * Three authorities, not one. `update` previously accepted `view-quotations` or
 * `view-crm` — the same grants that open the Commercials page — so anyone who
 * could read the list could also rewrite a price on a proposal that had already
 * gone to a customer. Reading is not writing.
 *
 * Approving is a third thing again, and the sharpest of the three: it clears a
 * discount. A rep who can edit their own quotation must not be able to sign off
 * on the discount they just applied to it, so approval sits with management.
 * That gate already existed as `crm.quotations.approve` in AppServiceProvider
 * and is mirrored here so the policy tells the whole story in one place.
 *
 * Tenant isolation is checked on every per-record ability, including approval.
 */
class QuotationPolicy
{
    use ChecksCrmPermissions;

    /**
     * MasterAdmin bypasses organization constraints.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isMasterAdmin()) {
            return true;
        }

        return null;
    }

    /* ── Reading ─────────────────────────────────────────────────────────── */

    public function viewAny(User $user): bool
    {
        return $this->hasAnyCrmPermission($user, 'view-quotations', 'view-crm', 'view-leads');
    }

    public function view(User $user, CompanyQuotation $quotation): bool
    {
        return $this->viewAny($user) && $this->sharesTenantWith($user, $quotation);
    }

    /* ── Writing ─────────────────────────────────────────────────────────── */

    /**
     * Raising a quotation is a rep's own job, so it takes the writing
     * permission rather than the reading one.
     */
    public function create(User $user): bool
    {
        return $this->canWrite($user);
    }

    /**
     * Editing the commercial terms of a proposal.
     *
     * Deliberately not satisfied by `view-quotations` or `view-crm`: those are
     * what a finance or support user holds to look at the pipeline, and looking
     * at a price is not the same as setting one.
     */
    public function update(User $user, CompanyQuotation $quotation): bool
    {
        return $this->canWrite($user) && $this->sharesTenantWith($user, $quotation);
    }

    /* ── Approving ───────────────────────────────────────────────────────── */

    /**
     * Signing off a quotation, and with it whatever discount it carries.
     *
     * Management authority. Held apart from `update` so the person who applied
     * a discount cannot be the person who clears it.
     */
    public function approve(User $user, CompanyQuotation $quotation): bool
    {
        return $this->canApprove($user) && $this->sharesTenantWith($user, $quotation);
    }

    /** Rejecting is the same authority as approving — both close the decision. */
    public function reject(User $user, CompanyQuotation $quotation): bool
    {
        return $this->approve($user, $quotation);
    }

    /**
     * Recording that money arrived against this quotation.
     *
     * A fourth authority, deliberately. Pricing a deal, signing off its
     * discount, and stating that the customer has paid are three different
     * jobs — and the last one is the one an auditor cares about. A rep who
     * priced the quotation should not be able to declare it settled.
     */
    public function recordPayment(User $user, CompanyQuotation $quotation): bool
    {
        return $this->hasAnyCrmPermission($user, 'record-payment')
            && $this->sharesTenantWith($user, $quotation);
    }

    /**
     * Deleting removes commercial history from every downstream report, so it
     * stays with management rather than with anyone who can edit a draft.
     */
    public function delete(User $user, CompanyQuotation $quotation): bool
    {
        return $this->canApprove($user) && $this->sharesTenantWith($user, $quotation);
    }

    /* ── The three authorities ───────────────────────────────────────────── */

    /** May author or change commercial terms. */
    protected function canWrite(User $user): bool
    {
        return $this->hasAnyCrmPermission($user, 'manage-quotations', 'edit own leads', 'create leads');
    }

    /** May sign off on terms somebody else authored. */
    protected function canApprove(User $user): bool
    {
        return $this->hasAnyCrmPermission($user, 'view-operations', 'view-analytics', 'manage-employees', 'manage-settings');
    }

    /**
     * Whether the quotation belongs to this user's tenant.
     *
     * `createQuotation()` stamps `organization_id` straight from the lead's
     * own at creation, and every lead has carried a tenant since Phase 0 — so
     * a null tenant here is a legacy row, not the working set, and fails
     * closed exactly as `LeadPolicy::sharesTenantWith()` does. A master admin
     * still reaches every quotation, legacy included, via `before()` above.
     */
    protected function sharesTenantWith(User $user, CompanyQuotation $quotation): bool
    {
        $typeVal = is_object($user->user_type) && isset($user->user_type->value)
            ? $user->user_type->value
            : (string) $user->user_type;

        if ($typeVal === UserType::ClinicUser->value) {
            return false;
        }

        $userTenant = $user->resolveOrganizationId();

        if (! $userTenant || ! $quotation->organization_id) {
            return false;
        }

        return (int) $quotation->organization_id === (int) $userTenant;
    }
}
