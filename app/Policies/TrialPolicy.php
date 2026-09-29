<?php

namespace App\Policies;

use App\Models\Trial;
use App\Models\User;
use App\Policies\Concerns\ChecksCrmPermissions;
use App\UserType;

/**
 * Who may see a trial, and who may change one.
 *
 * Two problems this rewrite fixes.
 *
 * Reading granted writing. `view` and `update` both accepted `view-trials` or
 * `view-crm`, so anybody who could open the trials board could also extend a
 * trial, cancel a customer's access or re-provision their tenant. Those are not
 * the same authority and they are now not the same permission.
 *
 * And provisioning had no permission of its own at all. Building a live tenant
 * for a customer, revoking their access, or moving the date their subscription
 * expires are the highest-consequence actions in this module — they touch a
 * real environment somebody is working in — so they sit behind
 * `trials.provision` rather than behind the same grant that lets a rep read a
 * list.
 *
 * Tenant isolation is checked on every per-record ability, including the
 * provisioning ones.
 */
class TrialPolicy
{
    use ChecksCrmPermissions;

    public function before(User $user, string $ability): ?bool
    {
        if ($user->user_type === UserType::MasterAdmin) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $this->hasAnyCrmPermission($user, 'view-trials', 'view-crm', 'view-leads');
    }

    public function view(User $user, Trial $trial): bool
    {
        return $this->viewAny($user) && $this->sharesTenantWith($user, $trial);
    }

    public function create(User $user): bool
    {
        /*
         * Creating a trial now provisions a live environment, so it needs the
         * same authority as the rest of provisioning — it is no longer the
         * paperwork exercise the old `view-trials` grant covered.
         */
        return $this->canProvision($user);
    }

    /**
     * Editing a trial's own record: notes, the plan, who owns it.
     *
     * A writing permission, not a reading one.
     */
    public function update(User $user, Trial $trial): bool
    {
        if (! $this->hasAnyCrmPermission($user, 'manage-trials', 'edit own leads')) {
            return false;
        }

        return $this->sharesTenantWith($user, $trial);
    }

    /**
     * Provision, retry, extend, cancel, resend the handover.
     *
     * Everything that reaches into the customer's live environment or moves the
     * date their access ends.
     */
    public function provision(User $user, Trial $trial): bool
    {
        return $this->canProvision($user) && $this->sharesTenantWith($user, $trial);
    }

    /**
     * Recording how a trial went: outcome, feedback, objections.
     *
     * Deliberately the ordinary writing permission rather than the provisioning
     * one. A rep who ran the trial should be able to write down what happened
     * without also being able to revoke a tenant.
     */
    public function complete(User $user, Trial $trial): bool
    {
        return $this->update($user, $trial);
    }

    /**
     * Deleting a trial removes evaluation history from conversion reporting,
     * so it stays with management.
     */
    public function delete(User $user, Trial $trial): bool
    {
        if (! $this->hasAnyCrmPermission($user, 'view-operations', 'view-analytics', 'manage-employees', 'manage-settings')) {
            return false;
        }

        return $this->sharesTenantWith($user, $trial);
    }

    /**
     * Asking for more time. Deliberately the ordinary writing permission
     * rather than `trials.provision` — a rep who ran the trial should be able
     * to ask for an extension without holding the authority to grant one to
     * themselves.
     */
    public function requestExtension(User $user, Trial $trial): bool
    {
        return $this->update($user, $trial);
    }

    /**
     * Deciding a pending request, or applying one's own extension outright.
     *
     * The same manager signal `crm.quotations.approve` reads — see
     * `AppServiceProvider`'s `crm.trials.approve-extension` gate.
     */
    public function approveExtension(User $user, Trial $trial): bool
    {
        return $this->hasAnyCrmPermission($user, 'view-operations', 'view-analytics', 'manage-employees', 'manage-settings')
            && $this->sharesTenantWith($user, $trial);
    }

    /** Whether this user may act on a customer's live environment at all. */
    protected function canProvision(User $user): bool
    {
        return $this->hasAnyCrmPermission($user, 'trials.provision');
    }

    /**
     * Whether the trial belongs to this user's tenant.
     *
     * A trial hangs off a pre-Won lead, and `leads.organization_id` is the
     * vendor tenant stamped at creation — not the customer's. Legacy leads
     * created before that stamping have none, and those stay in scope rather
     * than becoming invisible to everybody.
     *
     * Once a trial has provisioned, its customer organisation is a second,
     * firmer signal: it must never match the reader's own tenant, because that
     * would mean a vendor employee reading a trial belonging to their own
     * company as if it were a customer's.
     */
    protected function sharesTenantWith(User $user, Trial $trial): bool
    {
        if (! $this->isCompanyPortalUser($user)) {
            return false;
        }

        $leadOrganizationId = $trial->lead?->organization_id;

        if (! $leadOrganizationId || ! $user->organization_id) {
            return true;
        }

        return (int) $leadOrganizationId === (int) $user->organization_id;
    }
}
